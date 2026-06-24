# local_sqlchat

LLM-driven SQL generator for Moodle. Ask a natural-language question, get a
validated SELECT statement against the live Moodle DB schema.

## Status

Alpha (MVP). Single page, no RAG, full compressed schema sent each call.

## Architecture

```
question  →  schema_compressor (walks every install.xml; MUC cached)
             [retrieval mode: full | bm25 | ddl | ddl_bm25]
          →  chat_engine builds prompt (dialect-aware, unprefixed names)
          →  tool_ai_bridge\ai_bridge::perform_request($prompt, $purpose)
          →  sql_validator (SELECT-only, no stacked statements)
          →  api::execute  →  sql_executor (read-only conn optional,
                              prefix injection, LIMIT, statement timeout)
          →  result table
audit_log records every generation and execution outcome.
```

## Dependencies

- `tool_ai_bridge` — backend selector for `core_ai_subsystem`,
  `local_ai_manager`, or `tool_aimanager`.
- No external schema file. `schema_compressor` discovers tables by
  parsing `lib/db/install.xml` plus every plugin and subplugin
  `db/install.xml` via `core_component`. Result is cached in MUC
  (definition `schema` in `db/caches.php`, key `compressed_v3`).

## Settings (Site administration → Plugins → Local plugins)

| Setting | Default | Purpose |
|---|---|---|
| `maxrows` | 1000 | Cap injected as `LIMIT` when none present. |
| `timeoutsec` | 5 | Per-session statement timeout (PG / MariaDB / MySQL). |
| `purpose` | `feedback` | `purpose` string passed to `tool_ai_bridge`. |
| `backend` | `core_ai_subsystem` | AI backend selector. |
| `retrieval` | `full` | Schema sent to the LLM: `full` / `bm25` (compact one-liners, all vs relevant tables) or `ddl` / `ddl_bm25` (CREATE TABLE statements with types, FKs and unique keys, all vs relevant tables). DDL costs more tokens but gives the model exact column types. |
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

## Phase 2

- ~~BM25 retrieval (drop full-schema send).~~ Done — see the `retrieval` setting (`bm25`, `ddl_bm25`). Embedding-based retrieval still open.
- Self-correction loop on EXPLAIN error.
- AJAX modal for `local_reportsources` integration.
- Token telemetry, per-user quotas.
- Saved queries.
