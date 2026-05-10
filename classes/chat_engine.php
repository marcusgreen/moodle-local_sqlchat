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
 * Orchestrates: schema -> prompt -> LLM (via tool_ai_bridge) -> validator.
 *
 * @package    local_sqlchat
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class chat_engine {

    /**
     * Generate SQL for a natural-language question.
     *
     * @param string $question The user's question.
     * @param int|null $contextid Context to pass to the AI bridge; defaults to the system context.
     * @return result
     */
    public function ask(string $question, ?int $contextid = null): result {
        $start = microtime(true);
        $audit = new audit_log();

        $sql = null;
        $raw = '';
        try {
            $compressor = new schema_compressor();
            $schema = $compressor->get_compact();

            $prompt = $this->build_prompt($schema, $question);

            $contextid = $contextid ?? \context_system::instance()->id;
            $bridge = new \tool_ai_bridge\ai_bridge($contextid);
            $purpose = (string) (get_config('local_sqlchat', 'purpose') ?: 'feedback');
            $raw = $bridge->perform_request($prompt, $purpose);

            $sql = $this->extract_sql($raw);
            if ($sql === '') {
                throw new \moodle_exception('error:llmempty', 'local_sqlchat');
            }

            (new sql_validator())->check($sql);
        } catch (\Throwable $e) {
            $latencyms = (int) round((microtime(true) - $start) * 1000);
            $audit->record_generation($question, $sql, false, $e->getMessage(), $latencyms, 0);
            throw $e;
        }

        $latencyms = (int) round((microtime(true) - $start) * 1000);
        $logid = $audit->record_generation($question, $sql, true, null, $latencyms, 0);

        $result = new result();
        $result->sql = $sql;
        $result->raw_response = $raw;
        $result->latency_ms = $latencyms;
        $result->tokens_used = 0;
        $result->logid = $logid;
        return $result;
    }

    /**
     * Build the prompt sent to the LLM.
     *
     * @param string $schema Compressed schema text.
     * @param string $question The user's question.
     * @return string
     */
    private function build_prompt(string $schema, string $question): string {
        global $CFG;
        $dialect = match ($CFG->dbtype ?? 'mariadb') {
            'pgsql' => 'PostgreSQL',
            'sqlsrv' => 'MS SQL Server',
            'oci' => 'Oracle',
            default => 'MariaDB/MySQL',
        };

        return <<<PROMPT
You are a Moodle SQL generator. Output ONLY a single SELECT statement.
No explanation. No markdown fences. No trailing semicolon.

Rules:
- {$dialect} syntax.
- Use UNPREFIXED table names exactly as shown in the schema below
  (e.g. `FROM user`, not `FROM mdl_user`). The runtime adds the prefix
  before execution; output must remain readable to humans.
- SELECT only. Never write.
- Always include LIMIT 100 unless the question specifies a different limit.
- Never reference: user.password, user.secret, user.auth_*token,
  user_password_history.*, oauth2_*.client_secret,
  config.value where name LIKE '%key%' OR '%secret%'.

Schema (table(col1, col2 PK, fkcol→reftable, ...)):
{$schema}

Question: {$question}

SQL:
PROMPT;
    }

    /**
     * Strip markdown fences, leading commentary, and trailing punctuation from the LLM output.
     *
     * @param string $raw Raw LLM response.
     * @return string Cleaned SQL or empty string when nothing recognisable was returned.
     */
    private function extract_sql(string $raw): string {
        $raw = trim($raw);
        if (preg_match('/```(?:sql)?\s*(.*?)```/is', $raw, $m)) {
            $raw = trim($m[1]);
        }
        if (preg_match('/((?:WITH|SELECT)\b.*)/is', $raw, $m)) {
            $raw = trim($m[1]);
        }
        return rtrim($raw, "; \t\n\r");
    }
}
