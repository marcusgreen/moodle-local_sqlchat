<?php
// This file is part of Moodle - https://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_sqlchat;

/**
 * Walks every install.xml in the running Moodle (core + all plugins) and
 * compresses the combined schema into compact text suitable for an LLM prompt.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class schema_compressor {

    /** Cache key for the compressed schema. */
    private const CACHE_KEY = 'compressed_v3';

    /**
     * Common Moodle-convention column → table mappings that aren't covered by the
     * automatic `<col>` / `<col>id` matching rules. Only safe, unambiguous entries.
     *
     * @var array<string, string>
     */
    private const FK_ALIASES = [
        'category'      => 'course_categories',
        'categoryid'    => 'course_categories',
        'cmid'          => 'course_modules',
        'coursemoduleid' => 'course_modules',
        'groupid'       => 'groups',
        'groupingid'    => 'groupings',
        'usermodified'  => 'user',
        'modifierid'    => 'user',
        'authorid'      => 'user',
        'creatorid'     => 'user',
        'ownerid'       => 'user',
        'reviewerid'    => 'user',
        'graderid'      => 'user',
        'fromuserid'    => 'user',
        'touserid'      => 'user',
    ];

    /**
     * Returns the compressed schema, using MUC cache when available.
     *
     * @param bool $forcerefresh Skip the cache and rebuild.
     * @return string Compressed schema text.
     */
    public function get_compact(bool $forcerefresh = false): string {
        $cache = \cache::make('local_sqlchat', 'schema');
        if (!$forcerefresh) {
            $hit = $cache->get(self::CACHE_KEY);
            if ($hit !== false) {
                return $hit;
            }
        }
        $compact = $this->build();
        $cache->set(self::CACHE_KEY, $compact);
        return $compact;
    }

    /**
     * Walk every install.xml across core + plugins and return compressed text,
     * one table per line. Convention-based FK inference fills gaps where
     * install.xml omits explicit `<KEY TYPE="foreign">` declarations.
     *
     * @return string
     */
    public function build(): string {
        $this->require_xmldb();

        $collected = $this->collect_tables();
        if ($collected === []) {
            return '';
        }
        $names = array_keys($collected);
        $nameset = array_flip($names);

        $lines = [];
        foreach ($collected as $name => $info) {
            $line = $this->render_table($name, $info, $nameset);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return implode("\n", $lines);
    }

    /**
     * Walk install.xml files and return a map of
     * `tablename => ['fields' => [['name'=>..,'pk'=>bool], ...], 'fks' => [col=>ref]]`.
     *
     * @return array<string, array{fields: array<int, array{name: string, pk: bool}>, fks: array<string, string>}>
     */
    private function collect_tables(): array {
        $tables = [];
        foreach ($this->xmldb_files() as $path) {
            try {
                $xmldb = new \xmldb_file($path);
                $xmldb->loadXMLStructure();
                $structure = $xmldb->getStructure();
                if (!$structure) {
                    continue;
                }
                foreach ($structure->getTables() as $table) {
                    $name = $table->getName();
                    if ($name === '' || isset($tables[$name])) {
                        continue;
                    }
                    $fks = [];
                    foreach ($table->getKeys() as $key) {
                        if ($key->getType() !== XMLDB_KEY_FOREIGN
                            && $key->getType() !== XMLDB_KEY_FOREIGN_UNIQUE) {
                            continue;
                        }
                        $fields = $key->getFields();
                        $reftable = $key->getRefTable();
                        if (!$fields || !$reftable) {
                            continue;
                        }
                        $fks[$fields[0]] = $reftable;
                    }
                    $fields = [];
                    foreach ($table->getFields() as $field) {
                        $colname = $field->getName();
                        if ($colname === '') {
                            continue;
                        }
                        $fields[] = [
                            'name' => $colname,
                            'pk' => (bool) $field->getSequence(),
                            'isint' => $field->getType() === XMLDB_TYPE_INTEGER,
                        ];
                    }
                    if ($fields === []) {
                        continue;
                    }
                    $tables[$name] = ['fields' => $fields, 'fks' => $fks];
                }
            } catch (\Throwable $e) {
                debugging(
                    "local_sqlchat: failed to parse {$path}: " . $e->getMessage(),
                    DEBUG_DEVELOPER
                );
            }
        }
        return $tables;
    }

    /**
     * Render one collected table as a compact line. Applies declared FKs first,
     * then convention-based inference for columns that lack a declared FK.
     *
     * @param string $tablename Name of the table being rendered.
     * @param array{fields: array<int, array{name: string, pk: bool}>, fks: array<string, string>} $info
     * @param array<string, int> $nameset Flipped table-name lookup.
     * @return string
     */
    private function render_table(string $tablename, array $info, array $nameset): string {
        $cols = [];
        foreach ($info['fields'] as $field) {
            $colname = $field['name'];
            $token = $colname;
            if ($field['pk']) {
                $token .= ' PK';
            }
            $ref = $info['fks'][$colname] ?? null;
            if ($ref === null && ($field['isint'] ?? false)) {
                $ref = $this->infer_fk($colname, $tablename, $nameset);
            }
            if ($ref !== null) {
                $token .= '→' . $ref;
            }
            $cols[] = $token;
        }
        return $tablename . '(' . implode(', ', $cols) . ')';
    }

    /**
     * Convention-based FK inference. Returns a target table name when the column
     * unambiguously matches a known table or a curated alias. Never returns the
     * same table the column lives in (rejects self-references like `id`).
     *
     * Rules (first match wins):
     *  1. Curated alias (FK_ALIASES).
     *  2. Column name === existing table name.
     *  3. Column name ends in `id` and the prefix matches an existing table.
     *
     * @param string $colname Column name.
     * @param string $tablename Table the column lives in.
     * @param array<string, int> $nameset Flipped table-name lookup.
     * @return string|null Inferred reference table or null when nothing matches.
     */
    private function infer_fk(string $colname, string $tablename, array $nameset): ?string {
        if ($colname === 'id') {
            return null;
        }
        if (isset(self::FK_ALIASES[$colname])) {
            $target = self::FK_ALIASES[$colname];
            if ($target !== $tablename && isset($nameset[$target])) {
                return $target;
            }
        }
        if (isset($nameset[$colname]) && $colname !== $tablename) {
            return $colname;
        }
        if (str_ends_with($colname, 'id') && strlen($colname) > 2) {
            $stem = substr($colname, 0, -2);
            if ($stem !== $tablename && isset($nameset[$stem])) {
                return $stem;
            }
        }
        return null;
    }

    /**
     * Collect every install.xml shipped by core and the installed plugins.
     *
     * @return string[] Absolute file paths to install.xml files.
     */
    private function xmldb_files(): array {
        global $CFG;

        $paths = [];

        $corexml = $CFG->dirroot . '/lib/db/install.xml';
        if (is_readable($corexml)) {
            $paths[] = $corexml;
        }

        foreach (\core_component::get_plugin_types() as $type => $unused) {
            foreach (\core_component::get_plugin_list($type) as $unusedname => $plugindir) {
                $candidate = $plugindir . '/db/install.xml';
                if (is_readable($candidate)) {
                    $paths[] = $candidate;
                }
                if (is_dir($plugindir)) {
                    foreach ($this->find_subplugin_install_xml($plugindir) as $sub) {
                        $paths[] = $sub;
                    }
                }
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Discover install.xml files belonging to subplugins of a host plugin.
     *
     * @param string $plugindir Absolute path to the host plugin directory.
     * @return string[]
     */
    private function find_subplugin_install_xml(string $plugindir): array {
        $subpluginsfile = $plugindir . '/db/subplugins.json';
        if (!is_readable($subpluginsfile)) {
            return [];
        }
        $json = json_decode(file_get_contents($subpluginsfile), true);
        if (!is_array($json) || empty($json['plugintypes']) || !is_array($json['plugintypes'])) {
            return [];
        }
        global $CFG;
        $found = [];
        foreach ($json['plugintypes'] as $relpath) {
            $abs = $CFG->dirroot . '/' . trim($relpath, '/');
            if (!is_dir($abs)) {
                continue;
            }
            foreach (scandir($abs) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $candidate = $abs . '/' . $entry . '/db/install.xml';
                if (is_readable($candidate)) {
                    $found[] = $candidate;
                }
            }
        }
        return $found;
    }

    /**
     * Ensure the XMLDB classes are loaded. Moodle autoloads them when DDL is
     * used, but this class may run from CLI/test contexts before that happens.
     *
     * @return void
     */
    private function require_xmldb(): void {
        global $CFG;
        if (class_exists('\xmldb_file')) {
            return;
        }
        require_once($CFG->dirroot . '/lib/ddllib.php');
    }
}
