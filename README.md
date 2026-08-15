# local_sqlchat

LLM-driven SQL generator for Moodle. Ask a natural-language question, get a
validated SELECT statement against the live Moodle DB schema.

## Origin

`local_sqlchat` was created as part of the
[`local_reportsources`](https://github.com/marcusgreen/local_reportsources)
plugin — the natural-language-to-SQL engine that plugin needed was split out
into this standalone component. The two are decoupled and can be used
**separately or together**:

- **Alone** — `local_sqlchat` works on its own via its single admin page, and
  `local_reportsources` works on its own without AI.
- **Together** — `local_reportsources` calls `local_sqlchat\api::generate_sql()`
  to turn a question into SQL, passing its own `%%…%%` prompt-token rules and
  resolving those tokens in its own report views. `local_sqlchat` holds no
  knowledge of those tokens (see "Caller-supplied prompt rules" below), so it
  neither requires nor depends on `local_reportsources` being installed.

## Status

Beta. Single-page UI. Six schema retrieval modes
(full / bm25 / ddl / ddl_bm25 / ddl_slim / ddl_slim_bm25). Failed dry-runs
are diagnosed against the live schema before the error reaches the user.

## Architecture

```
question  →  schema_compressor (walks every install.xml; MUC cached)
             [retrieval mode: full | bm25 | ddl | ddl_bm25 |
                             ddl_slim | ddl_slim_bm25]
          →  bm25_retriever narrows to relevant tables (*_bm25 modes)
          →  chat_engine builds prompt (dialect-aware, unprefixed names)
          →  tool_ai_bridge\ai_bridge::perform_request($prompt, $purpose)
          →  sql_validator (SELECT-only, no stacked statements)
          →  chat_engine dry-runs the SQL; on failure sql_executor::diagnose
             verifies the missing table/column against the live schema
          →  api::execute  →  adhoc_placeholder_processor (standalone %% tokens)
                           →  sql_executor (read-only conn optional, prefix
                              injection, LIMIT, statement timeout)
          →  result table
audit_log records every generation and execution outcome.
```

## Dependencies

- `tool_ai_bridge` — backend selector for `core_ai_subsystem`,
  `local_ai_manager`, or `tool_aimanager`.
- No external schema file. `schema_compressor` discovers tables by
  parsing `lib/db/install.xml` plus every plugin and subplugin
  `db/install.xml` via `core_component`. Result is cached in MUC
  (definition `schema` in `db/caches.php`): the compact schema under key
  `compressed_v4`, the DDL map under key `ddl_map_v3`, and the slim
  (losslessly compressed) DDL map under key `ddl_slim_map_v1`. The plugin's own
  `local_sqlchat_log` table is excluded from the schema sent to the LLM.

## Settings (Site administration → Plugins → Local plugins)

| Setting | Default | Purpose |
|---|---|---|
| `maxrows` | 1000 | Cap injected as `LIMIT` when none present. |
| `timeoutsec` | 5 | Per-session statement timeout (PG / MariaDB / MySQL). |
| `purpose` | `feedback` | `purpose` string passed to `tool_ai_bridge`. |
| `backend` | `core_ai_subsystem` | AI backend selector. |
| `retrieval` | `full` | Schema sent to the LLM. Compact one-liners: `full` / `bm25` (all vs relevant tables). CREATE TABLE statements with types, FKs and unique keys: `ddl` / `ddl_bm25`. The same DDL losslessly compressed (shared conventions stated once in a preamble, one line per table, ~½ the tokens): `ddl_slim` / `ddl_slim_bm25`. `*_bm25` narrows to the tables relevant to the question. DDL costs more tokens but gives the model exact column types; slim modes suit SQL-specialised models on a tight context window. |
| `showprompt` | off | Show the prompt sent to the LLM beneath the generated SQL, for reuse on another model. |

Read-only DB credentials live in `config.php`, not the admin UI:
`$CFG->dbreadonly_user` and `$CFG->dbreadonly_pass`. Without them the
default `$DB` connection is used.

## Public API

```php
use local_sqlchat\api;

$result = api::generate_sql('Show me users with no logins in 90 days');
// $result->sql, ->raw_response, ->prompt, ->latency_ms, ->tokens_used, ->logid

api::validate($somesql);          // throws if not a single safe SELECT
$rows = api::execute($somesql, $result->logid); // logid optional
```

`api::generate_sql()` does NOT execute — callers (e.g. `local_reportsources`)
keep their existing exec/render path. `api::execute()` re-validates,
applies the table prefix to unprefixed names, enforces `LIMIT`, sets the
statement timeout, and records the execution outcome against the supplied
log row.

## Caller-supplied prompt rules (`%%…%%` tokens)

This plugin is **token-agnostic**. `api::generate_sql($question, $contextid, $extrarules)`
takes a third `$extrarules` string (default `''`) that is appended verbatim to the prompt's
Rules block. Standalone use passes nothing, so the LLM emits plain SQL with no special tokens.

`local_reportsources` owns its `%%…%%` tokens (dates → `%%TIMESTAMP%%`, text case →
`%%CASE%%`, `%%EPOCH%%`, `%%NOW%%`, `%%WWWROOT%%`, `%%CONTEXT_*%%`, `%%COURSEID%%`,
`%%COURSECONTEXT%%`). It passes `\local_reportsources\local\sql\view::ai_prompt_rules()`
as `$extrarules` so the generated SQL uses them, and resolves them itself when the report
view is built. If `local_reportsources` is not installed, no caller supplies token rules and
the whole token concern is absent — this plugin neither emits nor resolves them.

(Separately, `api::execute` runs `adhoc_placeholder_processor` over SQL before execution to
resolve this plugin's own standalone placeholders — `%%USERID%%`, `%%STARTTIME%%`,
`%%ENDTIME%%`, `%%WWWROOT%%`, `%%C%%`/`%%S%%`/`%%Q%%`. That is unrelated to the reportsources
tokens above and needs no external plugin.)

## Security

- `local/sqlchat:use` capability gates all entry points
  (`riskbitmask = RISK_PERSONAL | RISK_DATALOSS`, manager archetype).
- `sql_validator` rejects DML, DDL, stacked statements, and obvious
  exfil patterns (`INTO OUTFILE`, `INTO DUMPFILE`, `LOAD_FILE`,
  `LOAD DATA`, `LOAD XML`, `INFORMATION_SCHEMA`, `PERFORMANCE_SCHEMA`,
  `MYSQL.`). String literals and comments are stripped before keyword
  scan to avoid false positives.
- Hard `LIMIT` injection in `sql_executor` when none present.
- Per-session statement timeout where supported (PG: `statement_timeout`;
  MariaDB/MySQL: `max_statement_time`).
- Optional read-only DB connection via `$CFG->dbreadonly_user` /
  `$CFG->dbreadonly_pass`.
- Prompt instructs LLM to never reference sensitive columns
  (`user.password`, auth tokens, `oauth2_*.client_secret`,
  secret-like `config.value`).
- Audit log table `local_sqlchat_log` records userid, question, SQL,
  success, error message, rows returned, tokens, latency, timestamp.
