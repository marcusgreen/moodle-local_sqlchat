# Model comparison — local_sqlchat SQL generation

Results of running the CLI batch harness (`cli/batch_test.php`) over the
100-question set (`cli/questions.txt`) against several LLM backends, on this
Moodle instance's real database.

- **OK** — SQL generated, passed security + schema validation, and executed
  without error.
- **gen-fail** — generation failed: the model produced SQL referencing a
  table/column that does not exist (caught by the dry-run gate; a single repair
  attempt was made and also failed), or non-SELECT output.
- **exec-fail** — validated SQL that still errored at execution. **Zero across
  every model** — the validation gate stops invented-identifier SQL before it
  reaches the database.

All runs used retrieval mode `full` (compact schema, every table).

## Leaderboard (100 questions)

| Model | Size / type | Host | OK | gen-fail | exec-fail | Speed / query |
|---|---|---|---:|---:|---:|---|
| DeepSeek-V4-Flash | large | remote (DeepSeek) | **100** | 0 | 0 | ~2–3 s |
| Groq gpt-oss-120b | 120B | remote (Groq) | **100** | 0 | 0 | ~2 s |
| Groq gpt-oss-20b | 20B | remote (Groq) | **98** | 2 | 0 | ~1 s |
| XiYanSQL-QwenCoder-14B-2504 (Q4_K_M) | 14B, SQL-specialist | local (Ollama) | **84** | 16 | 0 | ~25 s |
| qwen2.5-coder:7b-instruct | 7B, general coder | local (Ollama) | **73** | 27 | 0 | ~1.5 s |

Groq gpt-oss-20b: the 2 gen-fails were both transient **rate-limit** rejections
(`--delay=2` not quite enough for two of Groq's bursts), **not** SQL errors — so
on SQL quality it is effectively perfect (98/98 responses valid, 0 exec-fail).
An unthrottled run of the same model rejected ~76/100 purely from the rate
limit, which is why throttling is required to measure it.

Earlier smaller runs (same trend): DeepSeek 15/15 and 50/50; qwen-7B 10/15 and
37/50.

## Notes per model

- **DeepSeek-V4-Flash / Groq gpt-oss-120b** — perfect on all 100. Resolve every
  Moodle join indirection (enrolment via `enrol`, roles via `context`, activity
  modules via `course_modules`) and non-obvious FK naming from the same schema
  and rules the smaller models get.
- **XiYanSQL-QwenCoder-14B** — SQL-specialist 14B. Beats the general 7B (84 vs
  73) but ~15× slower on local hardware for +11 points, and still short of the
  big models. Fails on the same identifier-prior family (see below).
- **qwen2.5-coder-7B** — fastest, but ~27% of queries invent identifiers.
- **Groq gpt-oss-20b** — with the `--delay=2` throttle: 98/100, and both losses
  were transient rate-limit rejections, not SQL errors — effectively perfect on
  quality, and the fastest responder measured (~1 s). Unthrottled it rejected
  ~76/100 purely from Groq's free-tier rate limit, so throttling is mandatory to
  measure it.

## Failure analysis

Every failure across every model is a **prior-driven identifier invention** —
the model overrides what the schema shows with a naming convention it expects.
Providing more/better schema (DDL mode was tested) does not fix it; a stronger
model does. This is model-capacity-bound, not a prompt defect.

Dominant classes:

1. **`user_enrolments.courseid`** — models assume enrolment links directly to a
   course. It does not: `user_enrolments.enrolid → enrol.id`, then
   `enrol.courseid → course.id`. The single most common failure.
2. **FK column naming (`<reftable>id`)** — e.g. `forum_posts.forumid`,
   `quiz_attempts.quizid`. Moodle often drops the `id` suffix
   (`forum_posts.discussion`, `quiz_attempts.quiz`).
3. **Role/context shortcuts** — `contextlevel`/`instanceid` referenced on
   `role_assignments`; they live on `context`.
4. **Invented tables** — pluralised real names (`events`, `cohorts` for `event`,
   `cohort`), or wrong log table (`log_display`, `log_queries` instead of
   `logstore_standard_log`).
5. **Template placeholders** — XiYan sometimes emits literal `<course_id>`
   rather than a concrete query, producing a syntax error.

## The validation gate

`sql_executor::dry_run()` EXPLAINs each generated statement (placeholders
neutralised) to detect invented tables/columns; `chat_engine::ask()` runs it
after security validation and, on failure, feeds the database error back to the
model for one repair attempt. Result: **exec-fail = 0 for every model at every
scale** (~465 total test queries). Strong models pass it untouched; weak models
degrade to a clear pre-execution error instead of a raw database failure.

## Operational notes

- **Provider switching** was done at site level via the core AI subsystem
  (`ai_providers` table: toggle `enabled`, set the per-action model in
  `actionconfig`), then restored. The `tool_ai_bridge` layer selects the
  provider by which instance serves `generate_text`.
- **Core column-width limit:** Moodle core's `ai_action_register.model` column
  is `VARCHAR(50)`. Ollama model tags longer than 50 characters (common with
  `hf.co/...` Hugging Face tags, e.g.
  `hf.co/mradermacher/XiYanSQL-QwenCoder-14B-2504-GGUF:Q4_K_M`, 54 chars) make
  **every** `generate_text` call throw `Data too long for column 'model'`.
  Worked around by registering a short Ollama alias (`ollama cp <long-tag>
  xiyansql:14b`) and pointing the provider at it. Candidate for a core bug
  report.
- **Rate limits:** use `batch_test.php --delay=SECS` to stay under a provider's
  request cap (e.g. `--delay=2` for Groq free tier).
