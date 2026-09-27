# 03 — Database Design (PostgreSQL 16)

**Doc version:** 1.0 · Engine: PostgreSQL 16 (+ `pgvector` for Phase 5) · Migrations: forward-only, one table per migration

---

## 1. Conventions

| Decision | Rule |
|---|---|
| Primary keys | `id BIGSERIAL` (or `bigIncrements`) — fastest joins, Laravel default. Sequential ids are **never** exposed in shareable/public URLs (future share links use a random `share_token`) |
| Foreign keys | `foreignId()->constrained()->cascadeOnDelete()` for owned children; `nullOnDelete()` for optional references (e.g. `company_id`, `job_id` on reminders) |
| Money | `numeric(12,2)` + separate `char(3)` currency code — never floats |
| Timestamps | `timestamp with time zone` semantics: **all times stored in UTC**, rendered and scheduled in the user's timezone |
| Timestamps columns | `created_at`, `updated_at`; `deleted_at` (soft delete) on user-visible resources only |
| Enums | stored as `varchar(32)` with a **PHP backed enum cast** and a DB `CHECK`-free approach (validated at app level) → adding a new status never requires a destructive migration |
| Free-form structured data | `jsonb` (+ GIN index where queried). Used for AI confidence maps, raw AI payloads, arrays of responsibilities/benefits |
| Text | `text` for descriptions/notes; `varchar(n)` only where a limit is a real constraint |
| Naming | snake_case tables (plural), snake_case columns, `{singular}_id` foreign keys, pivot tables alphabetically ordered |
| Indexes | every FK gets an index; composite indexes lead with `user_id` because every query is user-scoped |

**Why not UUIDs for everything:** bigint is smaller/faster for hot queries (job lists), and we never expose raw ids publicly. Random tokens are used only where a public identifier is genuinely needed.

---

## 2. Enum contracts (single source of truth: PHP enums + mirrored TS union types)

```php
enum JobStatus: string        { case Saved='saved'; case Preparing='preparing'; case Applied='applied';
                                case Interview='interview'; case Offer='offer'; case Rejected='rejected'; }

enum CaptureSource: string    { case Text='text'; case Pdf='pdf'; case Image='image'; case Url='url'; case Manual='manual'; }

enum ExtractionStatus: string { case Queued='queued'; case Processing='processing'; case Completed='completed';
                                case NeedsReview='needs_review'; case Failed='failed'; }

enum EmploymentType: string   { case FullTime='full_time'; case PartTime='part_time'; case Contract='contract';
                                case Internship='internship'; case Freelance='freelance'; case Unknown='unknown'; }

enum WorkMode: string         { case Onsite='onsite'; case Remote='remote'; case Hybrid='hybrid'; case Unknown='unknown'; }

enum ExperienceLevel: string  { case Entry='entry'; case Junior='junior'; case Mid='mid'; case Senior='senior';
                                case Lead='lead'; case Unknown='unknown'; }

enum SalaryPeriod: string     { case Hourly='hourly'; case Monthly='monthly'; case Yearly='yearly'; case Unknown='unknown'; }

enum ReminderType: string     { case Deadline='deadline'; case Interview='interview'; case FollowUp='follow_up'; case Custom='custom'; }

enum ReminderStatus: string   { case Pending='pending'; case Sent='sent'; case Dismissed='dismissed'; case Failed='failed'; }

enum SkillRequirement: string { case Required='required'; case Preferred='preferred'; }

enum AiFeature: string        { case JobExtraction='job_extraction'; case CvProfile='cv_profile';
                                case JobMatch='job_match'; case CoverLetter='cover_letter'; case InterviewPrep='interview_prep'; }

enum AiRunStatus: string      { case Queued='queued'; case Processing='processing'; case Completed='completed'; case Failed='failed'; }
```

**Allowed job status transitions** (enforced in `ChangeJobStatusAction`):

```
saved      → preparing | applied | rejected
preparing  → applied | rejected
applied    → interview | offer | rejected
interview  → offer | rejected
offer      → (terminal, may be archived)
rejected   → saved (re-open)
any        → archived (via archived_at flag, not a status)
```

---

## 3. Entity relationship overview

```
users ─┬─< jobs ─┬─< job_notes
       │         ├─< job_status_histories
       │         ├─< job_skills >─ skills
       │         ├─< job_attachments
       │         ├─< reminders
       │         └─> companies
       ├─< companies
       ├─< tags ─< job_tag >─ jobs
       ├─< job_extractions ─> job_attachments      (raw capture input)
       ├─< reminders
       ├─< ai_usage_logs
       ├─< notification_preferences     (1:1)
       ├─< notifications                (Laravel database notifications)
       ├─< cvs ─< cv_analyses ─> jobs    (Phase 5)
       │        └─> cover_letters ─> jobs
       └─< personal_access_tokens / sessions   (Sanctum + session driver)
```

Cardinality summary: a user owns many jobs; a job belongs to one user and optionally one company; a job has many skills (via `job_skills`), notes, status-history rows, attachments, reminders and tags. A capture (extraction) produces at most one job and can reference the attachment that was uploaded.

## 4. Table definitions

### 4.1 `users`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | varchar(120) | |
| email | varchar(190) | **unique** (stored lowercase), verified via `email_verified_at` |
| password | varchar(255) | argon2id when available, else bcrypt |
| timezone | varchar(64) | default `Asia/Dhaka`; drives reminder + digest scheduling |
| locale | varchar(8) | default `en` |
| avatar_path | varchar(255) nullable | R2 key, private + signed URL |
| is_active | boolean | default true (soft suspension without deleting data) |
| last_active_at | timestamp nullable | powers "user is away → send email instead of in-app" |
| onboarding_completed_at | timestamp nullable | gates the onboarding flow |
| ai_monthly_quota_override | integer nullable | support/power-user override |
| email_verified_at, created_at, updated_at, deleted_at | | soft delete honoured until purge |

Indexes: unique `email`; index `deleted_at`.

### 4.2 `notification_preferences` (1:1 with users)

| Column | Type | Notes |
|---|---|---|
| user_id | bigint FK → users | **unique** (1:1), cascade delete |
| email_deadline_reminders | boolean | default true |
| email_daily_digest | boolean | default true |
| email_extraction_updates | boolean | default false |
| in_app_enabled | boolean | default true |
| deadline_offsets | jsonb | default `[7,3,1]` — days before deadline |
| digest_hour_local | smallint | default 8 (0–23, user-local) |
| quiet_hours | jsonb nullable | e.g. `{"from":"22:00","to":"07:00"}` |

### 4.3 `companies`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users | cascade delete; companies are **per user** (no cross-tenant leakage, no admin curation needed) |
| name | varchar(190) | as displayed |
| normalized_name | varchar(190) | lowercased, punctuation-stripped, suffix-trimmed (`ltd`, `inc`, `bangladesh`) |
| website | varchar(255) nullable | normalised URL |
| logo_path | varchar(255) nullable | |
| notes | text nullable | |
| created_at, updated_at, deleted_at | | |

Indexes: unique `(user_id, normalized_name)` — this is what makes company de-duplication automatic on extraction; index `(user_id)` for autocomplete.

### 4.4 `jobs` (the job card — the core table)

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users | cascade delete, always present |
| company_id | bigint FK → companies nullable | `nullOnDelete`; raw company text kept in `company_name` |
| company_name | varchar(190) nullable | exactly what the source said |
| title | varchar(190) | required |
| description | text nullable | raw/full job description (used by FTS) |
| location | varchar(190) nullable | as stated ("Dhaka, Bangladesh", "Anywhere") |
| is_remote | boolean | derived from `work_mode`, kept for simple filtering |
| work_mode | varchar(16) | `onsite｜remote｜hybrid｜unknown` (cast to `WorkMode`) |
| employment_type | varchar(16) | `full_time｜part_time｜contract｜internship｜freelance｜unknown` |
| experience_level | varchar(16) | `entry｜junior｜mid｜senior｜lead｜unknown` |
| experience_min_years | smallint nullable | |
| experience_max_years | smallint nullable | |
| education_requirement | varchar(255) nullable | free text ("BSc in CSE or equivalent") |
| salary_min | numeric(12,2) nullable | |
| salary_max | numeric(12,2) nullable | |
| salary_currency | char(3) nullable | ISO-4217 (`BDT`, `USD`) |
| salary_period | varchar(16) nullable | `hourly｜monthly｜yearly｜unknown` |
| salary_is_negotiable | boolean | default false |
| deadline_at | timestamptz nullable | application deadline (UTC) |
| deadline_confidence | varchar(8) nullable | `low｜medium｜high` from AI — drives the "confirm this date" UI |
| posted_at | date nullable | |
| apply_url | varchar(2048) nullable | sanitised to http/https |
| apply_email | varchar(190) nullable | |
| contact_info | text nullable | phone / other contact as stated |
| status | varchar(16) | default `saved`; indexed with `user_id` |
| priority | varchar(8) nullable | `low｜medium｜high` (user-set, optional) |
| is_favorite | boolean | default false |
| applied_at | timestamptz nullable | set automatically when status first becomes `applied` |
| archived_at | timestamptz nullable | hidden from default views, keeps history |
| source_type | varchar(16) | `text｜pdf｜image｜url｜manual` |
| source_url | varchar(2048) nullable | original link, for re-checking |
| capture_id | bigint FK → job_extractions nullable | provenance (`nullOnDelete`) |
| skills_raw | jsonb | AI-extracted skill strings (display, e.g. "React JS") |
| responsibilities | jsonb | array of strings |
| requirements | jsonb | array of strings |
| benefits | jsonb | array of strings |
| ai_confidence | jsonb nullable | `{"title":"high","deadline_at":"low",...}` |
| ai_provider / ai_model | varchar | provenance of the extraction |
| search_vector | tsvector | **generated column** from title + company_name + description + location |
| created_at, updated_at, deleted_at | | |

Indexes:

| Index | Purpose |
|---|---|
| `(user_id, status)` | board columns, status filter |
| `(user_id, deadline_at)` | upcoming deadlines, reminder scheduling (partial: `deadline_at IS NOT NULL`) |
| `(user_id, created_at DESC)` | default list ordering |
| `(user_id, applied_at)` partial `status IN ('applied','interview','offer')` | funnel analytics |
| GIN `search_vector` | full-text search |
| GIN `skills_raw` (`jsonb_path_ops`) | "has any of these skills" filters |
| `(user_id, company_id)` | company pages |
| `(user_id, deleted_at)` | soft-delete-aware lists |
| unique `(user_id, lower(title), company_id)` — **deliberately not added** | duplicates are *warned about*, not blocked (a user may legitimately re-post a job after a repost) |

### 4.5 `job_extractions` (a capture + its AI run — the pipeline's audit trail)

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | exposed to the client as `extraction_id` |
| user_id | bigint FK → users | cascade delete |
| job_id | bigint FK → jobs nullable | set on approval; `nullOnDelete` |
| source_type | varchar(16) | `text｜pdf｜image｜url｜manual` |
| input_hash | char(64) | **sha256 of normalised input** (text or file checksum) |
| input_excerpt | text nullable | first ~2,000 chars of text/URL content (debugging + review UI) |
| input_length | integer nullable | chars/bytes processed (cost + truncation visibility) |
| url | varchar(2048) nullable | when `source_type=url` |
| attachment_id | bigint FK → job_attachments nullable | when `source_type=pdf｜image` |
| status | varchar(16) | `queued｜processing｜completed｜needs_review｜failed` |
| review_reason | varchar(255) nullable | human-readable ("deadline not found", "source unreachable") |
| attempts | smallint | default 0 |
| error_message | text nullable | last failure (sanitised, no PII) |
| payload | jsonb nullable | **raw validated AI output** (never trusted directly for DB writes) |
| mapped_job | jsonb nullable | the normalised values we intended to persist (diff-able against user edits) |
| missing_fields | jsonb nullable | e.g. `["deadline_at","salary_min"]` |
| prompt_version | varchar(24) | e.g. `job_extraction.v1` |
| provider / model | varchar nullable | which provider actually answered (failover makes this non-obvious) |
| truncated | boolean | whether the input was cut to fit the budget |
| started_at / finished_at | timestamptz nullable | latency measurement |
| approved_at / discarded_at | timestamptz nullable | human decision |
| created_at, updated_at | | no soft delete (audit/telemetry row; pruned monthly) |

Indexes: unique `(user_id, input_hash)` → idempotency + no double billing; `(user_id, status)` → "my recent imports"; `(status, created_at)` → stuck-job monitoring; `(user_id, created_at DESC)`.

### 4.6 `job_attachments`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users | cascade delete |
| job_id | bigint FK → jobs nullable | `nullOnDelete`; null while a capture is still unapproved |
| extraction_id | bigint FK → job_extractions nullable | links the raw capture file |
| kind | varchar(16) | `capture_pdf｜capture_image｜job_document｜other` |
| disk | varchar(32) | `r2` (never `public`) |
| path | varchar(512) | key inside the private bucket |
| original_name | varchar(255) | sanitised for display only |
| mime_type | varchar(100) | sniffed, not trusted from the client |
| size_bytes | bigint | checked against the limit |
| checksum | char(64) | sha256 → dedupe + "unchanged CV" detection |
| extracted_text | text nullable | when server-side text extraction is cheap (used for FTS/diagnostics) |
| created_at, updated_at, deleted_at | | soft delete keeps restorable jobs intact |

Indexes: `(user_id, job_id)`; unique `(user_id, checksum)` → re-uploading the same file reuses the object instead of paying to store/AI-process it again.

### 4.7 `job_notes`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users | cascade delete |
| job_id | bigint FK → jobs | cascade delete |
| body | text | max 5,000 chars validated server-side |
| is_pinned | boolean | default false |
| created_at, updated_at, deleted_at | | |

Index: `(job_id, created_at DESC)`; GIN/trigram index optional for "search my notes".

### 4.8 `job_status_histories` (immutable audit trail / timeline source)

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users | cascade delete (denormalised for scoping) |
| job_id | bigint FK → jobs | cascade delete |
| from_status | varchar(16) nullable | null on creation |
| to_status | varchar(16) | |
| note | varchar(500) nullable | optional user note on the change |
| changed_at | timestamptz | index-friendly (no reliance on `created_at`) |
| created_at | | |

Indexes: `(job_id, changed_at DESC)`; `(user_id, to_status, changed_at)` → funnel/velocity analytics.

**Rule:** rows are append-only. Corrections are new rows; nothing is updated or deleted except via account purge.

### 4.9 `skills` (catalog) + `job_skills` (pivot)

`skills`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| name | varchar(120) | canonical display name ("React", "PostgreSQL", "Docker") |
| slug | varchar(120) | **unique**, normalised key (`react`, `postgresql`) |
| aliases | jsonb | `["reactjs","react.js","react js"]` for AI/text matching |
| category | varchar(64) nullable | `language｜framework｜database｜devops｜design｜soft_skill…` |
| is_verified | boolean | false = auto-created by extraction, awaiting cleanup; verified entries seed the catalog |
| created_at, updated_at | | |

Indexes: unique `slug`; GIN `aliases`; index `(category)`; trigram index on `name` for autocomplete.

`job_skills`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| job_id | bigint FK → jobs | cascade delete |
| skill_id | bigint FK → skills | cascade delete |
| requirement | varchar(16) | `required｜preferred` |
| years_required | smallint nullable | when the ad states it |
| weight | smallint | default 100; AI/manual emphasis used by `MatchScoreCalculator` |
| created_at | | |

Index: **unique `(job_id, skill_id)`** (no duplicates per job); `(skill_id)` for "most demanded skills" analytics.

**Why a pivot instead of only jsonb:** CV matching, "missing skills" reports and "which skills are most requested in my pipeline" all become indexed joins with zero AI calls, while `jobs.skills_raw` keeps the original wording for display.

### 4.10 `tags` + `job_tag`

`tags`: `id`, `user_id` FK, `name varchar(40)`, `color varchar(7)` default `#64748b`, timestamps, soft delete. Unique `(user_id, name)`, index `(user_id)`.
`job_tag`: `job_id` + `tag_id` composite unique, cascade delete on both sides; indexes on both columns.

Tags are user-authored labels ("remote", "urgent", "referral") — deliberately separate from AI-extracted skills.

### 4.11 `reminders`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users | cascade delete |
| job_id | bigint FK → jobs nullable | `nullOnDelete`; a reminder can be free-standing |
| type | varchar(16) | `deadline｜interview｜follow_up｜custom` |
| title | varchar(190) | shown in the UI and email subject |
| body | text nullable | |
| remind_at | timestamptz | **UTC**, indexed — the dispatcher's only hot column |
| status | varchar(16) | `pending｜sent｜dismissed｜failed` |
| source | varchar(16) | `auto_deadline｜auto_interview｜manual` |
| offset_days | smallint nullable | which offset generated it (7/3/1) |
| channels | jsonb | `["mail","database"]` resolved at send time against preferences |
| sent_at | timestamptz nullable | |
| dismissed_at | timestamptz nullable | |
| failure_reason | varchar(255) nullable | |
| created_at, updated_at | | |

Indexes: `(status, remind_at)` → dispatcher sweep; `(user_id, remind_at)` → user's upcoming list; partial unique `(job_id, type, offset_days) WHERE status='pending'` → **never two pending deadline reminders for the same offset**; unique `(user_id, id)` is implicit.

**Idempotency rule:** the dispatcher only processes `status='pending'` rows inside a transaction with `FOR UPDATE SKIP LOCKED`, flipping them to `processing`-equivalent semantics by setting `status='sent'` before handing off to the mailer; a crash between the two steps at worst re-sends nothing because the mail job re-checks status.

### 4.12 `ai_usage_logs`

| Column | Type | Notes |
|---|---|---|
| id | bigserial PK | |
| user_id | bigint FK → users nullable | null for system/ops calls |
| feature | varchar(32) | `job_extraction｜cv_profile｜job_match｜cover_letter｜interview_prep｜embedding` |
| subject_type / subject_id | varchar(64) / bigint nullable | polymorphic link (extraction, cv_analysis, job) |
| provider | varchar(24) | `openai｜gemini` (the one that actually served the request) |
| model | varchar(64) | |
| input_tokens / output_tokens | integer | |
| cost_micros | bigint | stored in micro-USD to avoid float money |
| latency_ms | integer | |
| status | varchar(16) | `success｜failed｜failover` |
| error_code | varchar(64) nullable | |
| created_at | | no updates, ever |

Indexes: `(user_id, created_at)` → monthly quota aggregation; `(feature, created_at)` → cost dashboards; `(provider, status)` → failover monitoring.
Retention: 90 days raw, then a monthly roll-up table/command (pruned by `ai:prune-usage-logs`) so billing history survives without unbounded growth.

### 4.13 Phase 5 tables (designed now, migrated in Phase 5)

`cvs`

| Column | Type | Notes |
|---|---|---|
| id, user_id | | cascade delete |
| title | varchar(120) | "Backend CV (2026)" |
| attachment_id | bigint FK → job_attachments | the stored file (private) |
| is_primary | boolean | at most one primary per user (partial unique index) |
| parsed_profile | jsonb nullable | skills[], experiences[], education[], projects[], summary |
| parsed_at | timestamptz nullable | |
| source_text_hash | char(64) nullable | avoids re-parsing/re-embedding unchanged content |
| embedding | vector(1536) nullable | pgvector; HNSW index (`vector_cosine_ops`) |
| derived_from_cv_id | bigint FK → cvs nullable | "improved version of" lineage |
| prompt_version | varchar(24) nullable | |
| created_at, updated_at, deleted_at | | |

`cv_analyses` (one row per analysis run; also the result cache)

| Column | Type | Notes |
|---|---|---|
| id, user_id | | cascade delete |
| cv_id | bigint FK → cvs | cascade delete |
| job_id | bigint FK → jobs nullable | null for a general CV review |
| type | varchar(24) | `skill_gap｜general_review｜ats_check｜roadmap` |
| status | varchar(16) | `queued｜processing｜completed｜failed` |
| match_score | smallint nullable | 0–100, deterministic; null when incomputable (never a fake 0) |
| score_breakdown | jsonb nullable | per-signal contributions → explainable UI |
| matched_skills / missing_skills | jsonb | |
| strengths / weaknesses / suggestions | jsonb | |
| provider, model, prompt_version, source_hash | | cache key + provenance |
| error_message | text nullable | |
| created_at, updated_at | | |

Index: unique `(cv_id, job_id, type, prompt_version, source_hash)` → caching without duplication; `(user_id, created_at DESC)`.

`cover_letters`

| Column | Type | Notes |
|---|---|---|
| id, user_id, job_id, cv_id nullable | | cascade / `nullOnDelete` |
| tone | varchar(16) | `formal｜friendly｜concise` |
| body | text | editable by the user; the edited copy is authoritative |
| provider, model, prompt_version | | provenance |
| created_at, updated_at | | |

`interview_preps` (Phase 5, thin): `id, user_id, job_id, questions jsonb, topics jsonb, company_summary text, provider, model, created_at`.

**Framework tables** (created by Laravel/Sanctum migrations, not redefined here): `sessions`, `password_reset_tokens`, `personal_access_tokens`, `notifications`, `jobs`/`failed_jobs`/`job_batches` (queue internals — note the name clash with our domain `jobs` table: the domain keeps the name, and queue tables are never queried directly), `cache`, `cache_locks`, plus `agent_conversations` / `agent_conversation_messages` published by `laravel/ai`.

---

## 5. Migration order (FK-safe)

```
1  users                             9  job_notes
2  framework defaults (cache,        10 job_status_histories
   jobs/queue, sessions)             11 skills
3  personal_access_tokens            12 job_skills
4  password_reset_tokens             13 tags
5  notifications                     14 job_tag
6  notification_preferences          15 reminders
7  companies                         16 ai_usage_logs
8  job_attachments                   17 agent_conversations (+messages) [laravel/ai]
   8a job_extractions                18 cvs (+ CREATE EXTENSION vector)
   8b jobs (with nullable FKs)       19 cv_analyses
   (add the jobs.capture_id FK in a  20 cover_letters
    follow-up migration to avoid a   21 interview_preps
    circular dependency)             22 generated tsvector column + GIN indexes
                                     23 pgvector HNSW indexes
```

Rules: one logical change per migration; every migration has a working `down()` except destructive data migrations (documented as irreversible); generated columns and GIN/HNSW indexes are added in dedicated migrations so a failed index build can be retried alone.

---

## 6. Seeding (development only)

| Seeder | Purpose |
|---|---|
| `SkillCatalogSeeder` | ~300 real skills with aliases/categories — autocomplete and matching are meaningless before this exists |
| `DemoUserSeeder` | 1 demo user + ~40 realistic jobs across every status, with deadlines, notes, status history, tags and reminders, so UI work never needs fake data inside components |
| `ExtractionFixtureSeeder` | imports `tests/Fixtures/extractions/*` as past extractions for reviewing the pipeline UI |

Never run demo seeders in production (guard on `APP_ENV`). Production starts with `SkillCatalogSeeder` only.

---

## 7. Query patterns & optimisations (what the schema is optimised for)

| User-facing action | Query shape | Index used |
|---|---|---|
| Board with all columns | one query grouped by status, selecting only card columns | `(user_id, status)` |
| "Closing soon" list | `whereNotNull('deadline_at')->whereBetween(...)->orderBy('deadline_at')` | partial `(user_id, deadline_at)` |
| Search "react developer dhaka" | `whereFullText('search_vector', $q)` (+ trigram fallback for short prefixes) | GIN `search_vector` |
| Filter by skills | `whereHas('skills', fn ($q) => $q->whereIn('skill_id', $ids))` | `job_skills (skill_id)` |
| Reminder sweep | `where('status','pending')->where('remind_at','<=',now())->lockForUpdate()` | `(status, remind_at)` |
| Monthly AI quota | `sum(cost_micros)` + `count(*)` for the period | `(user_id, created_at)` |
| Dashboard counters | one aggregate query using `FILTER (WHERE status = ...)`, cached 60 s per user and invalidated on write | `(user_id, status)` |
| Semantic search (Phase 5) | `whereVectorSimilarTo('embedding', $text)` | HNSW `vector_cosine_ops` |

**Anti-N+1 rules:** every list resource declares its `with()` relations explicitly; `Model::preventLazyLoading()` is enabled outside production; list endpoints never `select *` on `jobs` (they select the ~12 fields a card needs).

**Cursor vs offset pagination:** job lists use cursor pagination (`cursorPaginate`) because it stays fast and stable while the user mutates data mid-scroll; admin-ish/reporting lists may use offset pagination.

---

## 8. Retention & deletion policy

| Data | Retention |
|---|---|
| Active jobs / notes / reminders | until deleted by the user |
| Soft-deleted jobs & notes | 30 days, then hard-purged by a scheduled command (their files purge with them) |
| `job_extractions` (incl. raw AI payload) | 90 days; payload/`input_excerpt` then nulled, counters kept for analytics |
| `ai_usage_logs` | 90 days raw + monthly roll-up retained indefinitely (aggregated, no PII) |
| `failed_jobs` | 14 days |
| Uploaded files | life of their owning row + weekly orphan sweep |
| Account deletion | immediate soft delete, then `PurgeAccountJob` removes objects, rows and tokens; anonymised aggregate counters may remain |

**Consistency guarantees this schema relies on**

1. `jobs.user_id` is non-nullable and indexed everywhere → no cross-user leakage is possible without an explicit bug in scoping.
2. Money is `numeric`, never float; currency is explicit so comparisons across currencies never silently mix.
3. Timestamps are UTC; the user's timezone is applied only at render/schedule time, so DST changes cannot corrupt stored data.
4. AI output is stored raw (`payload`) **and** normalised (`mapped_job`), so user corrections can later be diffed to improve prompts without a schema change.





