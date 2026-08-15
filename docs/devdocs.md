# local_sqlchat — Developer Documentation

Internal reference for developers working on `local_sqlchat`. For install and
admin usage see `README.md`; for the AI-assistant working agreement see
`CLAUDE.md`. This document explains *how the plugin is built* and *why*, and
where to extend it.

- **Component:** `local_sqlchat`
- **Requires:** Moodle ≥ 4.5 (`2024100700`) and the `tool_ai_bridge` plugin
- **Maturity:** Beta — single-page UI, no AMD modules
- **Location:** `local/sqlchat/` under the Moodle web root

---

## 1. What the plugin does

Turns a natural-language question into a validated, read-only `SELECT`
against the *live* Moodle database schema, runs it, and returns rows.

It is usable two ways:

1. **Standalone** — the admin page `index.php` (generate → execute).
2. **As a library** — the `local_sqlchat\api` static façade, called by other
   plugins (notably `local_reportsources`) that own their own execution and
   rendering path.

The key idea is that the plugin never ships a hand-maintained schema file.
`schema_compressor` discovers every table at runtime by parsing all
`install.xml` files (core + every plugin + subplugin) and caches the result.

---

## 2. Request flow

```
index.php (POST, sesskey)                      caller (e.g. local_reportsources)
        │  action=generate                              │  api::generate_sql(q, ctx, extrarules)
        ▼                                                ▼
        └───────────────► api::generate_sql ◄────────────┘
                                │  capability check: local/sqlchat:use
                                ▼
                          chat_engine::ask
                                │  1. retrieve_schema()  → schema text (+ isddl flag)
                                │  2. build_prompt()     → dialect-aware prompt
                                │  3. ai_bridge::perform_request($prompt, $purpose)
                                │  4. extract SQL from raw response
                                │  5. sql_validator::check()
                                │  6. sql_executor::dry_run()
                                │       └─ on failure → sql_executor::diagnose()
                                ▼
                             result  (sql, raw_response, prompt, latency_ms,
                                      tokens_used, logid)

index.php (POST, action=execute)  OR  caller's own exec path
        ▼
   api::execute
        │  re-validate (sql_validator)
        │  adhoc_placeholder_processor  (resolve this plugin's own %% tokens)
        │  sql_executor::run  (prefix injection, LIMIT, statement timeout,
        │                      optional read-only connection)
        ▼
   rows  +  audit_log::record_execution
```

`audit_log` is two-phase: `record_generation` inserts a row and returns a
`logid`; `record_execution` later updates that row with the row count or
error. The `logid` threads through `result` so a caller can execute against
the same audit row it generated.

---

## 3. Classes (`classes/`, PSR-4 autoloaded)

| Class | Responsibility |
|---|---|
| `api` | Static façade. Capability check, then delegate to `chat_engine` / `sql_executor`. The only entry point external callers should use. |
| `chat_engine` | Picks schema text for the retrieval mode, builds the LLM prompt, calls the backend, extracts + validates SQL, dry-runs and diagnoses. Carries the prompt back on `result->prompt`. |
| `schema_compressor` | Walks every `install.xml` via `core_component`, infers FKs by convention, emits Compact / DDL / Slim-DDL schema text. MUC-cached. |
| `bm25_retriever` | Okapi BM25 over the compact lines to reduce the schema to the tables relevant to the question. |
| `sql_validator` | Rejects anything that is not a single safe `SELECT`. Strips string literals and comments before the keyword scan to avoid false positives. |
| `sql_executor` | Runs the SQL: prefix injection, `LIMIT`, per-session timeout, optional read-only connection. Also `dry_run()` and `diagnose()`. Holds no token logic. |
| `dialect_checker` | Flags SQL syntax incompatible with the active DB driver. |
| `adhoc_placeholder_processor` | Resolves this plugin's *own* standalone `%%…%%` placeholders before execution. |
| `audit_log` | Two-phase logging into `local_sqlchat_log`. |
| `result` | Plain DTO returned by generation. |

### Design constraints (do not break)

- **The LLM outputs unprefixed table names.** `sql_executor::apply_prefix`
  adds `$CFG->prefix` at runtime via a longest-match regex. Never store or
  display prefixed SQL to users.
- **`api::generate_sql` does not execute.** Callers own execution so they can
  use their own render path. `api::execute` re-validates before running.
- **The backend is pluggable.** `tool_ai_bridge` abstracts
  `core_ai_subsystem`, `local_ai_manager`, and `tool_aimanager`. The backend
  is selected by the `local_sqlchat/backend` admin setting.
- **The prompt is token-agnostic.** See §7.

---

## 4. Schema compression & retrieval modes

`schema_compressor` produces three formats, each cached in the MUC `schema`
store (`db/caches.php`) under its own key:

| Format | Method | Cache key | Shape |
|---|---|---|---|
| Compact | `get_compact()` | `compressed_v4` | One line per table: `table(col, col PK, fkcol→reftable, …)` |
| DDL | `get_ddl(?array $only)` | `ddl_map_v3` | `CREATE TABLE` with exact types/lengths, `NOT NULL`, defaults, `AUTO_INCREMENT`, `PRIMARY KEY (id)`, inferred `REFERENCES`, `UNIQUE(...)` |
| Slim DDL | `get_ddl_slim(?array $only)` | `ddl_slim_map_v1` | The DDL losslessly compressed (see below) |

DDL and Slim DDL are built as a **per-table map** (`ddl_map`, `ddl_slim_map`)
and cached; `$only` filters the map to a table subset. An `$only` that matches
nothing falls back to the full schema rather than an empty one.

### Slim DDL

Same information as `get_ddl()` with the repeated boilerplate factored into a
one-time preamble (`DDL_SLIM_PREAMBLE`):

- the `id` primary-key column, `NOT NULL`, `AUTO_INCREMENT` and
  `PRIMARY KEY (id)` are stated once in the preamble, not per table;
- nullable columns are marked with a `?` suffix (`NOT NULL` is the unmarked
  default);
- trivial `0` / `''` defaults are dropped;
- integer display widths are dropped (`INT(10)` → `INT`) — they never affect
  query correctness; `VARCHAR`/`DECIMAL` keep length/precision;
- foreign keys render as `col REF table` (or `REF table(col)` for a non-`id`
  target);
- one line per table.

The output is reconstructable → **lossless for query generation** while being
~1.9× cheaper in tokens (measured ~61k → 33k on the full core+plugins schema)
and still valid-looking SQL. It targets SQL-specialised models (e.g. XiYanSQL
on Ollama) on a tight context window.

### The six retrieval modes (`local_sqlchat/retrieval`)

| Mode | Schema sent | Tokens |
|---|---|---|
| `full` | compact, every table | high |
| `bm25` | compact, relevant tables only | low |
| `ddl` | DDL, every table | highest |
| `ddl_bm25` | DDL, relevant tables only | medium |
| `ddl_slim` | Slim DDL, every table | ~½ of `ddl` |
| `ddl_slim_bm25` | Slim DDL, relevant tables only | low |

`chat_engine::retrieve_schema()` dispatches on the mode and returns
`[$schemaText, $isddl]`. `$isddl` switches the prompt's schema legend between
the compact-format key and "CREATE TABLE statements". The `*_bm25` modes call
`bm25_retriever::retrieve_tables($question)` to get the relevant table names,
then pair that selection with `get_ddl()` / `get_ddl_slim()`.

### Cache invalidation

Purge after any change to the compressed output:

```bash
php admin/cli/purge_caches.php
```

If a format changes *incompatibly*, bump its key constant in
`schema_compressor` (`compressed_v4` / `ddl_map_v3` / `ddl_slim_map_v1`) so
stale cache entries are ignored rather than mis-read.

---

## 5. BM25 retrieval

`bm25_retriever` scores each compact schema line against the question using
Okapi **BM25** (Best Matching 25 — a TF-IDF-family ranking function from
information retrieval). It expands the query with synonyms, anchors, and one
hop of FK expansion so that a joined-to table is not dropped.

- `retrieve()` → the selected **compact lines**.
- `retrieve_tables()` → the selected **table-name list**, so the DDL and Slim
  DDL paths can pair the same selection with `get_ddl()` / `get_ddl_slim()`.

BM25 trades tokens for recall: on unusual phrasing it can miss a table. Pair it
with a slim mode when running a small SQL model on a tight context window.

---

## 6. Validation, dry-run & diagnosis

1. **`sql_validator::check()`** — blocks DML/DDL, stacked statements, and
   data-exfil patterns (`INTO OUTFILE`, `LOAD_FILE`, `INFORMATION_SCHEMA`, …).
   String literals and comments are stripped before the keyword scan.
2. **`sql_executor::dry_run()`** — runs the query under `EXPLAIN` (with
   `%%…%%` placeholders and named params neutralised to `NULL`) so a bad table
   or column is caught before real execution. The neutralising regex matches
   both bare `%%TOKEN%%` and argument forms `%%TOKEN(...)%%`.
3. **`sql_executor::diagnose()`** — when a dry-run fails and the automatic
   repair retry also fails, this turns the raw driver error (reason line + the
   entire failing `EXPLAIN` SQL + a params dump) into a clean, *verified*
   message. It strips to the reason line, then confirms the missing
   table/column against the live schema and, for a missing column, lists the
   columns the table actually has (and names related tables that do carry that
   column name). Surfaces via lang strings `error:nocolumn`, `error:notable`,
   `error:columnelsewhere`, `error:tableelsewhere`, wrapped by
   `error:schemainvalid`.

---

## 7. Prompt tokens — who owns what

The plugin is deliberately **token-agnostic**. Two *unrelated* token systems
exist; keep them straight:

**Caller-supplied prompt rules (`$extrarules`).**
`api::generate_sql($question, $contextid, $extrarules)` threads `$extrarules`
(default `''`) through `chat_engine::ask` → `build_prompt`, appending it
verbatim to the Rules block. Standalone use passes nothing.
`local_reportsources` passes
`\local_reportsources\local\sql\view::ai_prompt_rules()`, which describes its
own `%%…%%` tokens (`%%TIMESTAMP%%`, `%%CASE%%`, `%%EPOCH%%`, `%%NOW%%`,
`%%WWWROOT%%`, `%%CONTEXT_*%%`, `%%COURSEID%%`, `%%COURSECONTEXT%%`).
`local_reportsources` resolves those itself when its report view is built.
**This plugin holds no knowledge of those tokens.** When no `$extrarules` is
supplied, `build_prompt` adds a rule *forbidding* `%%…%%` tokens (the tool
cannot resolve them) — but only then, so it never fights a caller that taught
its own tokens.

**This plugin's own standalone placeholders.**
`adhoc_placeholder_processor` (run inside `api::execute`, before validation)
resolves `%%USERID%%`, `%%STARTTIME%%`, `%%ENDTIME%%`, `%%WWWROOT%%`,
`%%C%%`/`%%S%%`/`%%Q%%`. These are unrelated to the reportsources tokens and
need no external plugin.

---

## 8. Security model

- Capability `local/sqlchat:use` (`db/access.php`) gates every entry point;
  `riskbitmask = RISK_PERSONAL | RISK_DATALOSS`, manager archetype only.
- `index.php` is an admin page and uses sesskey on POST.
- `sql_validator` enforces single safe `SELECT`.
- `sql_executor` injects a hard `LIMIT` when none is present and sets a
  per-session statement timeout (PG: `statement_timeout`; MariaDB/MySQL:
  `max_statement_time`).
- Optional read-only DB connection when `$CFG->dbreadonly_user` /
  `$CFG->dbreadonly_pass` are set in `config.php` (not the admin UI).
- The prompt instructs the model never to reference sensitive columns
  (`user.password`, auth tokens, `oauth2_*.client_secret`, secret-like
  `config.value`).
- Every generation and execution is audited in `local_sqlchat_log`.

---

## 9. Settings (`settings.php`)

| Config key | Default | Notes |
|---|---|---|
| `local_sqlchat/maxrows` | 1000 | `LIMIT` injected when none present |
| `local_sqlchat/timeoutsec` | 5 | Per-session statement timeout |
| `local_sqlchat/purpose` | `feedback` | Passed to `tool_ai_bridge` |
| `local_sqlchat/backend` | `core_ai_subsystem` | AI backend selector |
| `local_sqlchat/retrieval` | `full` | One of the six retrieval modes (§4) |
| `local_sqlchat/showprompt` | off | Render the prompt beneath the SQL, for reuse on another model |
| `$CFG->dbreadonly_user` / `dbreadonly_pass` | — | In `config.php`, not the admin UI |

---

## 10. Extending the plugin

- **New retrieval mode** — add a `case` to `chat_engine::retrieve_schema()`, a
  producer method on `schema_compressor` (with its own cache key), the option
  string in `lang/en/local_sqlchat.php`, and the entry in `settings.php`.
- **New backend** — extend `tool_ai_bridge`; select via `local_sqlchat/backend`.
- **New standalone placeholder** — add resolution to
  `adhoc_placeholder_processor`; document it in §7 and `README.md`.
- **New validator rule** — extend `sql_validator`; remember it also runs again
  inside `api::execute`.

When you change any cached schema output incompatibly, bump the relevant cache
key constant (§4).

---

## 11. Developer commands

Run from the Moodle root (`/var/www/mdl52/public`), not this directory.

```bash
# Install / upgrade after schema changes
php admin/cli/upgrade.php --non-interactive

# Purge MUC caches — required after schema_compressor changes
php admin/cli/purge_caches.php

# PHPUnit (init once, then run)
php admin/tool/phpunit/cli/init.php
vendor/bin/phpunit --filter local_sqlchat
vendor/bin/phpunit local/sqlchat/tests/SomeTest.php

# Coding standards
vendor/bin/phpcs --standard=moodle local/sqlchat
```

There is a batch harness under `cli/` for comparing models / retrieval modes
across a question set (see the CLI commit history).

---

## 12. Moodle conventions enforced here

- All user-facing strings in `lang/en/local_sqlchat.php` (no hard-coded text).
- Capability in `db/access.php`; DB schema in `db/install.xml`
  (`local_sqlchat_log`); schema changes need an `upgrade.php` step.
- No `defined('MOODLE_INTERNAL')` guard in `classes/` — those files are
  autoloaded.
- Bump `$plugin->version` in `version.php` on any DB or install change.
