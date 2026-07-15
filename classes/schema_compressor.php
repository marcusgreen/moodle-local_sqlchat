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

    /** Cache key for the per-table DDL map. */
    private const DDL_CACHE_KEY = 'ddl_map_v2';

    /**
     * Table names excluded from the schema sent to the LLM. The legacy core `log`
     * table is dropped so the model never targets it.
     *
     * @var string[]
     */
    private const EXCLUDED_TABLES = [
        'log',
    ];

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
     * Return CREATE TABLE DDL for the schema, optionally restricted to a subset of tables.
     *
     * Renders dialect-neutral `CREATE TABLE` statements (one per table) from the same install.xml
     * walk as {@see get_compact()}, giving the LLM exact column types/lengths/nullability and
     * inferred foreign keys (as `REFERENCES`) instead of the terse one-line summary. Table and
     * reference names are left UNPREFIXED to match the prompt's "use unprefixed names" rule.
     *
     * @param string[]|null $only Lower-case table names to include; null/empty means all tables.
     * @param bool $forcerefresh Skip the cache and rebuild the DDL map.
     * @return string Newline-joined CREATE TABLE statements.
     */
    public function get_ddl(?array $only = null, bool $forcerefresh = false): string {
        $map = $this->get_ddl_map($forcerefresh);
        if ($map === []) {
            return '';
        }
        if ($only) {
            $wanted = array_flip($only);
            $map = array_intersect_key($map, $wanted);
            if ($map === []) {
                // The requested subset matched nothing — fall back to the full DDL rather than
                // sending the model an empty schema.
                $map = $this->get_ddl_map($forcerefresh);
            }
        }
        return implode("\n\n", $map);
    }

    /**
     * Build (and cache) a map of `tablename => "CREATE TABLE ... ;"`.
     *
     * @param bool $forcerefresh Skip the cache and rebuild.
     * @return array<string, string>
     */
    public function get_ddl_map(bool $forcerefresh = false): array {
        $cache = \cache::make('local_sqlchat', 'schema');
        if (!$forcerefresh) {
            $hit = $cache->get(self::DDL_CACHE_KEY);
            if ($hit !== false) {
                return $hit;
            }
        }
        $map = $this->build_ddl_map();
        $cache->set(self::DDL_CACHE_KEY, $map);
        return $map;
    }

    /**
     * Walk every install.xml across core + plugins and render each table as a CREATE TABLE
     * statement keyed by table name.
     *
     * @return array<string, string>
     */
    private function build_ddl_map(): array {
        $this->require_xmldb();

        $collected = $this->collect_tables();
        if ($collected === []) {
            return [];
        }
        $nameset = array_flip(array_keys($collected));

        $map = [];
        foreach ($collected as $name => $info) {
            $map[$name] = $this->render_table_ddl($name, $info, $nameset);
        }
        return $map;
    }

    /**
     * Render one collected table as a dialect-neutral CREATE TABLE statement. Declared FKs are
     * emitted as inline `REFERENCES`, with convention-based inference filling the gaps (mirroring
     * the compact renderer's `→` arrows).
     *
     * @param string $tablename
     * @param array{fields: array<int, array<string, mixed>>, fks: array<string, string>} $info
     * @param array<string, int> $nameset Flipped table-name lookup.
     * @return string
     */
    private function render_table_ddl(string $tablename, array $info, array $nameset): string {
        $lines = [];
        foreach ($info['fields'] as $field) {
            $colname = $field['name'];
            $parts = [$colname, $this->sql_type($field)];
            if (!empty($field['notnull'])) {
                $parts[] = 'NOT NULL';
            }
            if (!empty($field['sequence'])) {
                $parts[] = 'AUTO_INCREMENT';
            } else if (($field['default'] ?? null) !== null && $field['default'] !== '') {
                $parts[] = 'DEFAULT ' . $this->sql_default($field);
            }
            $ref = $info['fks'][$colname] ?? null;
            if ($ref === null && !empty($field['isint'])) {
                $ref = $this->infer_fk($colname, $tablename, $nameset);
            }
            if ($ref !== null) {
                $parts[] = 'REFERENCES ' . $ref . '(id)';
            }
            $lines[] = '  ' . implode(' ', $parts);
        }
        // The id sequence column is the primary key by Moodle convention.
        if (isset($nameset[$tablename])) {
            $lines[] = '  PRIMARY KEY (id)';
        }
        // Unique keys — convey cardinality and natural join keys to the model.
        foreach ($info['uniques'] ?? [] as $ufields) {
            if ($ufields) {
                $lines[] = '  UNIQUE (' . implode(', ', $ufields) . ')';
            }
        }
        return "CREATE TABLE {$tablename} (\n" . implode(",\n", $lines) . "\n);";
    }

    /**
     * Map an XMLDB field to a generic SQL column type. Kept dialect-neutral — the goal is to give
     * the LLM accurate types, not produce DDL executable on one specific database.
     *
     * @param array<string, mixed> $field
     * @return string
     */
    private function sql_type(array $field): string {
        $length = (string) ($field['length'] ?? '');
        $decimals = (string) ($field['decimals'] ?? '');
        switch ($field['type'] ?? null) {
            case XMLDB_TYPE_INTEGER:
                return $length !== '' ? "INT({$length})" : 'INT';
            case XMLDB_TYPE_NUMBER:
                return $decimals !== '' ? "DECIMAL({$length},{$decimals})"
                    : ($length !== '' ? "DECIMAL({$length})" : 'DECIMAL');
            case XMLDB_TYPE_FLOAT:
                return 'FLOAT';
            case XMLDB_TYPE_CHAR:
                return $length !== '' ? "VARCHAR({$length})" : 'VARCHAR(255)';
            case XMLDB_TYPE_TEXT:
                return 'TEXT';
            case XMLDB_TYPE_BINARY:
                return 'BLOB';
            case XMLDB_TYPE_TIMESTAMP:
                return 'TIMESTAMP';
            case XMLDB_TYPE_DATETIME:
                return 'DATETIME';
            default:
                return 'TEXT';
        }
    }

    /**
     * Render a column default as a SQL literal — quoted for non-numeric types.
     *
     * @param array<string, mixed> $field
     * @return string
     */
    private function sql_default(array $field): string {
        $default = (string) $field['default'];
        $numeric = in_array($field['type'] ?? null, [
            XMLDB_TYPE_INTEGER, XMLDB_TYPE_NUMBER, XMLDB_TYPE_FLOAT,
        ], true);
        if ($numeric && is_numeric($default)) {
            return $default;
        }
        return "'" . str_replace("'", "''", $default) . "'";
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
     * `tablename => ['fields' => [['name'=>..,'pk'=>bool], ...], 'fks' => [col=>ref], 'uniques' => [[col,..],..]]`.
     *
     * @return array<string, array{fields: array<int, array{name: string, pk: bool}>, fks: array<string, string>, uniques: array<int, string[]>}>
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
                    if ($name === '' || isset($tables[$name])
                            || in_array($name, self::EXCLUDED_TABLES, true)) {
                        continue;
                    }
                    $fks = [];
                    $uniques = [];
                    foreach ($table->getKeys() as $key) {
                        $type = $key->getType();
                        if ($type === XMLDB_KEY_FOREIGN || $type === XMLDB_KEY_FOREIGN_UNIQUE) {
                            $fields = $key->getFields();
                            $reftable = $key->getRefTable();
                            if ($fields && $reftable) {
                                $fks[$fields[0]] = $reftable;
                            }
                        }
                        // Unique keys give the DDL renderer cardinality hints (e.g. UNIQUE(courseid,
                        // userid) marks a 1:1 relationship and its natural join key). A foreign-unique
                        // key is also a uniqueness constraint, so capture its columns too.
                        if ($type === XMLDB_KEY_UNIQUE || $type === XMLDB_KEY_FOREIGN_UNIQUE) {
                            $ufields = $key->getFields();
                            if ($ufields) {
                                $uniques[] = $ufields;
                            }
                        }
                    }
                    // Moodle usually expresses uniqueness as a unique INDEX rather than a unique
                    // KEY (e.g. user_preferences' UNIQUE(userid, name)), so scan indexes too.
                    foreach ($table->getIndexes() as $index) {
                        if ($index->getUnique()) {
                            $ifields = $index->getFields();
                            if ($ifields) {
                                $uniques[] = $ifields;
                            }
                        }
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
                            // Captured for the DDL renderer (see get_ddl()); the compact
                            // renderer ignores these.
                            'type' => $field->getType(),
                            'length' => $field->getLength(),
                            'decimals' => $field->getDecimals(),
                            'notnull' => (bool) $field->getNotNull(),
                            'default' => $field->getDefault(),
                            'sequence' => (bool) $field->getSequence(),
                        ];
                    }
                    if ($fields === []) {
                        continue;
                    }
                    $tables[$name] = ['fields' => $fields, 'fks' => $fks, 'uniques' => $uniques];
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
