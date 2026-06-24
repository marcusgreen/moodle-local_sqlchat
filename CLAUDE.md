# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Plugin identity

Moodle local plugin: `local_sqlchat` (component name used everywhere in PHP, lang strings, capabilities, DB).
Requires Moodle ≥ 4.5 (`2024100700`) and the `tool_ai_bridge` plugin.
Alpha / MVP — no tests yet, no JS AMD modules, single page UI.

## Development commands

Run from the Moodle root (`/var/www/mdl52/public`), not from this directory.

```bash
# Upgrade/install the plugin after schema changes
php admin/cli/upgrade.php --non-interactive

# Purge MUC caches (required after schema_compressor changes)
php admin/cli/purge_caches.php

# PHPUnit (run from Moodle root)
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter local_sqlchat

# Single test file
vendor/bin/phpunit local/sqlchat/tests/SomeTest.php
```

Plugin lives at `/var/www/mdl52/public/local/sqlchat/` relative to the server; Moodle root is `../..` from here.

## Architecture

Request flow (see README.md for ASCII diagram):

1. **`index.php`** — single admin page, POST form. Two actions: `generate` (calls `api::generate_sql`) then `execute` (calls `api::execute`). Uses sesskey. No AJAX.

2. **`api`** (static façade) — capability check (`local/sqlchat:use`), then delegates to `chat_engine` or `sql_executor`. Entry point for external callers such as `local_reportsources`.

3. **`chat_engine`** — picks the schema text for the configured **retrieval mode** (see below), builds the LLM prompt (dialect-aware, unprefixed table names, format-aware legend), calls `tool_ai_bridge\ai_bridge::perform_request()`, extracts SQL from raw response, runs `sql_validator`. Carries the built prompt back on `result->prompt`.

4. **`schema_compressor`** — walks every `install.xml` (core + all plugins + subplugins) via `core_component`, infers FKs by convention. Two output formats:
   - **Compact** (`get_compact()`) — one line per table, `table(col, col PK, fkcol→reftable, ...)`. Cached in MUC (`local_sqlchat/schema`, key `compressed_v3`).
   - **DDL** (`get_ddl(?array $only)`) — `CREATE TABLE` statements with exact column types/lengths, `NOT NULL`, defaults, `AUTO_INCREMENT`, `PRIMARY KEY (id)`, inferred `REFERENCES`, and `UNIQUE(...)` from unique keys **and** unique indexes. Built as a per-table map cached under key `ddl_map_v2`; `$only` filters it to a table subset (empty subset falls back to full DDL).

   Invalidate either with `purge_caches.php`.

4b. **`bm25_retriever`** — reduces the schema to the tables relevant to the question via Okapi BM25 over the compact lines (synonym + anchor + FK expansion). `retrieve()` returns compact lines; `retrieve_tables()` returns the selected table-name list so the DDL path can pair the same selection with `get_ddl()`.

### Retrieval modes (admin setting `local_sqlchat/retrieval`)

| Mode | Schema sent | Tokens |
|---|---|---|
| `full` | compact, every table | high |
| `bm25` | compact, relevant tables only | low |
| `ddl` | CREATE TABLE DDL, every table | highest |
| `ddl_bm25` | CREATE TABLE DDL, relevant tables only | medium |

`chat_engine::retrieve_schema()` dispatches on the mode and returns `[schemaText, $isddl]`; `$isddl` switches the prompt's schema legend between the compact-format key and "CREATE TABLE statements".

5. **`sql_validator`** — strips string literals and comments before keyword scan to avoid false positives; blocks DML/DDL, stacked statements, and data-exfil patterns.

6. **`sql_executor`** — injects table prefix (longest-match regex substitution), appends `LIMIT` when absent, sets per-session statement timeout (PG: `statement_timeout`; MariaDB/MySQL: `max_statement_time`), uses read-only connection when `$CFG->dbreadonly_user`/`dbreadonly_pass` are set.

7. **`audit_log`** — two-phase: `record_generation` inserts a row, `record_execution` updates it with row count / error. `logid` threads between both.

8. **`result`** — plain DTO: `sql`, `raw_response`, `prompt`, `latency_ms`, `tokens_used`, `logid`. `prompt` carries the exact text sent to the LLM (surfaced when `local_sqlchat/showprompt` is on, for reuse on another model).

## Key design constraints

- **LLM outputs unprefixed table names.** `sql_executor::apply_prefix` adds `$CFG->prefix` at runtime. Never store or display prefixed SQL to users.
- **`api::generate_sql` does not execute.** Callers own execution so they can use their own render path. `api::execute` re-validates before running.
- **Backend is pluggable.** `tool_ai_bridge` abstracts `core_ai_subsystem`, `local_ai_manager`, and `tool_aimanager`. Backend selected by admin setting `local_sqlchat/backend`.
- **Schema cache keys are `compressed_v3` (compact) and `ddl_map_v2` (DDL).** Bump the relevant constant in `schema_compressor` if that output format changes incompatibly.

## Settings

| Config key | Default | Notes |
|---|---|---|
| `local_sqlchat/maxrows` | 1000 | LIMIT injected when none present |
| `local_sqlchat/timeoutsec` | 5 | Per-session statement timeout |
| `local_sqlchat/purpose` | `feedback` | Passed to `tool_ai_bridge` |
| `local_sqlchat/backend` | `core_ai_subsystem` | AI backend selector |
| `local_sqlchat/retrieval` | `full` | Schema retrieval mode: `full` / `bm25` / `ddl` / `ddl_bm25` (see Retrieval modes) |
| `local_sqlchat/showprompt` | off | Render the prompt sent to the LLM beneath the generated SQL, for reuse on another model |
| `$CFG->dbreadonly_user` / `dbreadonly_pass` | — | In `config.php`, not admin UI |

## Moodle conventions enforced here

- All lang strings in `lang/en/local_sqlchat.php`.
- Capability `local/sqlchat:use` defined in `db/access.php`; risk bits `RISK_PERSONAL | RISK_DATALOSS`; default to manager only.
- DB schema in `db/install.xml`; table `local_sqlchat_log`. Schema changes need `upgrade.php` entry in `db/upgrade.php` (file not yet created).
- No `define('MOODLE_INTERNAL')` check needed in `classes/` — autoloaded by Moodle.
