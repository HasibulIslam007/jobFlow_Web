# 02 — System Architecture

**Doc version:** 1.0 · Companion to `01-prd.md` (scope) and `03-database.md` (schema)

---

## 1. High-level view

```
        Browser (mobile / desktop)
                  │  HTTPS · JSON (cookies or bearer)
                  ▼
   ┌───────────────────────────────────────────┐
   │ Next.js 16 App Router                     │
   │ Server Components (SSR) · TanStack Query  │
   │ Zustand (UI state) · shadcn/ui            │
   └───────────────┬───────────────────────────┘
                   ▼
   ┌───────────────────────────────────────────┐
   │ Laravel 13 API  /api/v1                   │
   │ Sanctum · Policies · FormRequests         │
   │ Actions · Services · API Resources        │
   └──┬──────────────┬───────────────┬─────────┘
      ▼              ▼               ▼
 ┌──────────┐  ┌──────────┐   ┌───────────────────┐
 │ Postgres │  │  Redis   │   │ Queue workers      │
 │ +pgvector│  │ cache·q  │   │ Horizon: ai,default│
 │ +tsvector│  │ sessions │   └────┬──────────┬───┘
 └──────────┘  └──────────┘        ▼          ▼
                            ┌────────────┐ ┌──────────────┐
                            │ AI provider│ │ Cloudflare R2│
                            │ OpenAI →   │ │ private      │
                            │ Gemini     │ │ bucket       │
                            └────────────┘ └──────────────┘
                                   │
                 Reverb WebSocket + email (SES/Postmark) → browser
```

**Request-path rules**

1. The API is the only writer to the database. The frontend never touches Postgres, R2 credentials or AI providers directly.
2. Anything slow or expensive (AI, PDF/image analysis, bulk import, email) runs in a **queued job**, never inside an HTTP request cycle.
3. Every long-running user action uses the **202 + status (poll or websocket)** pattern: captures, CV analysis, cover letters, bulk import.
4. All user-owned data is scoped by `user_id` and enforced by **policies**, never by frontend filtering.

---

## 2. Frontend architecture

**Stack:** Next.js 16.3 (App Router, Turbopack) · React 19 · TypeScript 7 · Tailwind 4 · shadcn/ui CLI 4 · TanStack Query 5 · Zustand 5 · react-hook-form + Zod (via shadcn `Form`) · lucide-react.

**Rendering strategy per surface**

| Surface | Strategy | Reason |
|---|---|---|
| Marketing (`/`, `/pricing`, `/features`) | Static / ISR | SEO + speed, no personalization |
| Auth pages | Client components | Interactivity, nothing to prerender |
| Dashboard list/detail | Server Component shell + Client islands | Fast first paint with real data, interactive lists |
| Job list, board, capture review | TanStack Query | Cache, infinite scroll, optimistic updates, polling |
| Settings forms | Client + `useMutation` | Optimistic update with rollback |

**Data-flow contract**

- Server Components fetch through one server-side `apiFetch()` helper: forwards the session cookie (`cookies()`), attaches `X-Request-Id`, normalizes errors.
- Client code fetches through `src/lib/api/client.ts` (typed `fetch` wrapper, `credentials: 'include'`), consumed by TanStack Query hooks.
- Payload types come from **generated OpenAPI types** (`openapi-typescript`), so a contract break fails CI type-check instead of reaching production.
- Filters/sort/pagination live in the **URL query string** (shareable, back-button safe, server-readable for the first render). Zustand never stores server data.

**State ownership (strict)**

| State | Owner | Example |
|---|---|---|
| Server data | TanStack Query cache | `useJobsInfinite(filters)` |
| URL state | `useSearchParams` + `router.replace` | `?status[]=applied&sort=deadline_at` |
| Form state | react-hook-form | Edit-job form |
| Global UI state | Zustand | sidebar, capture modal step, board drag, toasts |
| Session | Server Component guard + `/auth/me` query | layout guard, topbar avatar |

**Next.js 16 specifics we must respect**

1. Auth guarding lives in `(dashboard)/layout.tsx` (server) → `redirect('/login')`, plus a lightweight `src/proxy.ts` matcher that only redirects when the session cookie is missing (cheap optimization, never the source of truth).
2. Caching is dynamic by default; `use cache` is applied only to genuinely shared, non-personal data (marketing content, skill catalog).
3. `params` / `searchParams` are async — always `await` them.
4. Extraction status polling uses TanStack Query `refetchInterval` with a stop condition, and switches to a Reverb subscription once broadcasting is enabled.

## 3. Backend architecture

**Style:** API-only Laravel 13 with deliberate layering and no ceremonial abstractions.

```
HTTP request
  → routes/api.php  (v1 group: throttle, auth:sanctum, verified)
  → FormRequest     (validate + authorize input shape)
  → Controller      (HTTP only: call Action/Service, return Resource)
  → Action/Service  (the only place business rules live)
  → Model           (persistence, casts, scopes, relationships)
  → API Resource    (serialization contract, single source of truth)
```

**Layer rules (enforced in code review)**

1. Controllers hold no business logic and never call AI, storage or queues directly.
2. Services/Actions never read `request()`; they take explicit values/DTOs so they are unit-testable.
3. Eloquent models hold casts, scopes, relationships and small accessors only.
4. Cross-cutting concerns (quota checks, request ids, JSON error shaping) live in middleware or services — never duplicated per controller.
5. Repositories are **not** introduced generically; a dedicated query object (`JobFilterQuery`) exists only for complex list filtering.

**Folder structure**

```
jobflow-api/                    # this repository — Laravel application root
├── app/
│   ├── Actions/
│   │   ├── Jobs/       CreateJobAction, CreateJobFromExtractionAction, ChangeJobStatusAction, BulkUpdateJobsAction
│   │   ├── Reminders/  CreateReminderAction, ScheduleDeadlineRemindersAction, DismissReminderAction
│   │   └── Captures/   CreateCaptureAction, ApproveExtractionAction, DiscardExtractionAction
│   ├── Ai/
│   │   ├── Agents/     JobExtractionAgent, CvProfileAgent, JobMatchAgent, CoverLetterAgent, InterviewPrepAgent
│   │   ├── Prompts/    versioned text: job_extraction.v1.md, cv_profile.v1.md, match.v1.md
│   │   └── Support/    SkillExtractor, SalaryParser, DeadlineParser, AiUsageRecorder
│   ├── Enums/          JobStatus, EmploymentType, WorkMode, ExperienceLevel, CaptureSource,
│   │                   ExtractionStatus, ReminderType, ReminderStatus, AiFeature
│   ├── Http/
│   │   ├── Controllers/Api/V1/  Auth, Profile, Jobs, JobNotes, Captures, Reminders, Attachments,
│   │   │                        Companies, Skills, Tags, Notifications, Dashboard, Cvs, JobMatches, CoverLetters
│   │   ├── Middleware/   EnsureJsonResponse, AssignRequestId, EnforceAiQuota, IdempotencyKey
│   │   ├── Requests/     Jobs/{Store,Update,Index}JobRequest, Captures/StoreCaptureRequest, ...
│   │   ├── Resources/    JobResource, JobSummaryResource, CaptureResource, ReminderResource, CvResource
│   │   └── Responses/    ApiResponse (success/error envelope helpers)
│   ├── Jobs/           ExtractJobDetailsJob, FetchUrlContentJob, SendDeadlineReminderJob, SendDailyDigestJob,
│   │                   AnalyzeCvJob, MatchCvToJobJob, GenerateCoverLetterJob, PurgeAccountJob
│   ├── Models/         User, Job, JobExtraction, JobAttachment, JobNote, JobStatusHistory, Reminder, Company,
│   │                   Skill, JobSkill, Tag, Cv, CvAnalysis, CoverLetter, AiUsageLog, NotificationPreference
│   ├── Policies/       JobPolicy, JobNotePolicy, ReminderPolicy, JobAttachmentPolicy, CvPolicy
│   ├── Services/
│   │   ├── Captures/   CaptureIntakeService, UrlFetchService (SSRF-safe), DocumentTextService
│   │   ├── Jobs/       JobNormalizer, JobFilterQuery, JobDuplicateDetector, JobStatsService
│   │   ├── Skills/     SkillResolver (alias → catalog), SkillMatcher
│   │   ├── Storage/    FileStorageService (R2 put/sign/delete), UploadValidator
│   │   ├── Ai/         ExtractionRunner, JobCardMapper, AiQuotaService, AiCostCalculator
│   │   ├── Reminders/  ReminderScheduler, ReminderDispatcher
│   │   └── Matching/   MatchScoreCalculator (deterministic; weights from config)
│   ├── Events/         ExtractionCompleted, ExtractionFailed, JobStatusChanged, ReminderDue
│   ├── Listeners/      BroadcastExtractionStatus, InvalidateDashboardCache, LogJobStatusChange
│   └── Notifications/  DeadlineReminderNotification, DailyDigestNotification, ExtractionFailedNotification
├── config/             jobflow.php (quotas, reminder offsets, scoring weights), ai.php, filesystems.php (r2)
├── database/           migrations · factories · seeders
├── routes/             api.php, channels.php, console.php (scheduler)
└── tests/              Feature/Api/V1/**, Unit/Services/**, Fixtures/extractions/**
```

**Why this is not overengineering:** there is no generic repository layer, no CQRS, no event sourcing. `Actions` exist only where an operation has real invariants (approving an extraction, changing status, bulk update). Everything else is a plain controller + Eloquent call.

---

## 4. AI integration flow (System 1 and System 3)

**Provider strategy:** one abstraction (`laravel/ai` v1.0.0), two providers, config-driven.

- Primary: **OpenAI** (vision for screenshots, file input for PDFs).
- Failover: **Gemini** (native PDF understanding — up to 50 MB / 1000 pages, ~258 tokens per page; strong on scanned/photographed circulars).
- Failover per call: `provider: [Lab::OpenAI, Lab::Gemini]`. Only `FailoverableException` (rate limit, provider overload, insufficient credits) triggers it — validation errors never retry blindly.
- Models are named in `config/ai.php`, never hardcoded inside agents, so a model swap is a config change with no code edit.

### 4.1 Capture → job card sequence

```
User                    API                        Queue worker              AI provider
 │ POST /v1/captures     │                              │                        │
 │──────────────────────>│ validate + store file (R2)   │                        │
 │                       │ create job_extractions row   │                        │
 │                       │ quota check (AiQuotaService) │                        │
 │                       │ dispatch(ExtractJobDetailsJob)                       │
 │ 202 {extraction_id}   │─────────────────────────────>│                        │
 │<──────────────────────│                              │ status = processing    │
 │ subscribe private     │                              │ fetch URL text (if any)│
 │ channel / poll 2s     │                              │ prompt + schema        │
 │                       │                              │ attachments (pdf/img)  │
 │                       │                              │───────────────────────>│
 │                       │                              │<── StructuredAgentResponse
 │                       │                              │ normalize → map fields │
 │                       │                              │ create draft job card  │
 │                       │                              │ status = completed     │
 │  ExtractionCompleted  │<── broadcast event ──────────│                        │
 │<──────────────────────│                              │                        │
 │ GET /v1/captures/{id} → review screen (editable, confidence flags)
 │ POST /v1/captures/{id}/approve → creates job + auto-schedules reminders
```

**Terminal states:** `completed` (mandatory fields present, confidence acceptable), `needs_review` (missing mandatory field, low confidence, or unfetchable source), `failed` (provider/queue error after retries). Every terminal state shows a human-readable reason plus a manual-completion path in the UI.

### 4.2 Extraction agent design

- `App\Ai\Agents\JobExtractionAgent implements Agent, HasStructuredOutput`; `instructions()` encodes strict rules: never invent data, `null` for unknown, ISO-8601 dates in UTC, detect Bangla/English, normalise salary to amount + currency + period.
- Schema uses `schema(JsonSchema $schema): array` — scalars, nested `object()` for salary/experience/contact, `array()->items(object())` for skills/responsibilities/benefits, plus a `confidence` object (`low|medium|high` per field) that drives the UI's warning badges.
- Skills are captured twice: raw strings for display, and catalog-mapped ids for matching. `SkillResolver` resolves aliases (`reactjs`, `React JS` → `react`) and creates new catalog entries behind a review flag.
- Prompt versioning: prompt text lives in `app/Ai/Prompts/job_extraction.v1.md`; the version string is stored on the extraction row so accuracy regressions are traceable and rollback is possible.
- Input budget: text truncated to ~20k chars (head + tail with an explicit marker), PDFs capped at 10 MB / ~30 pages, images downscaled client-side before upload. Truncation is recorded on the extraction row, never silent.

### 4.3 URL captures

1. `UrlFetchService` fetches server-side with SSRF protection: scheme allow-list (`http`, `https`), DNS-resolution check against private/link-local ranges, ≤ 3 redirects, 10 s timeout, 2 MB body cap, content-type allow-list (`text/html`, `application/pdf`).
2. HTML → readable text (script/style/tags stripped, boilerplate trimmed) stored as `job_extractions.input_excerpt`.
3. Pages that need JavaScript or block bots (LinkedIn, Facebook groups) produce `needs_review` with the reason, and the UI immediately offers the paste-text path prefilled with whatever was obtained.
4. Phase 5: a provider URL-context / web-search tool as a second attempt before falling back to `needs_review`.

### 4.4 System 3 — CV matching (Phase 5)

Deterministic scoring first, AI explanation second:

```
match_score = Σ weighted signals          weights live in config/jobflow.php
  required-skill overlap    60%
  preferred-skill overlap   15%
  experience-years fit      15%
  education requirement      5%
  location / work mode       5%
```

- `MatchScoreCalculator` is pure PHP, unit-tested and reproducible (same inputs ⇒ same score).
- `JobMatchAgent` then explains: strong matches, missing skills ranked by requirement type and by how often they appear across the user's pipeline, experience/education gaps, ATS keywords, and 3 tailored résumé bullet suggestions.
- Guardrails: never fabricate experience; label all generated content; no auto-submission; embeddings are a secondary similarity signal only (pgvector + HNSW index), never the sole basis of a score.
- Caching: results cached per `(cv_id, job_id, type, prompt_version)` against a source-text checksum, so repeat views cost nothing.

### 4.5 Cost, quota and reliability controls

| Control | Implementation |
|---|---|
| Monthly quota per user | `AiQuotaService` aggregates `ai_usage_logs`; checked in middleware before dispatch **and** again inside the worker |
| Cost visibility | every call writes `ai_usage_logs`: feature, provider, model, tokens, estimated micro-cost, latency, status |
| Idempotency | `job_extractions.input_hash` is unique per user → re-submitting identical input returns the previous result instead of paying twice |
| Embedding reuse | content-hash cache so unchanged CV/job text is never re-embedded |
| Failure containment | queue tries 3, backoff `[10, 30, 60]`, timeout 120 s, `failed_jobs` + in-app alert; the user always keeps a manual path |
| Testability | `Agent::fake([...])` for happy paths and failures; `Queue::fake()`, `Storage::fake('r2')`; the test suite makes no network calls |

---

## 5. File upload flow

**Storage:** Cloudflare R2 (S3-compatible), **private bucket**. Files are never public; access is only through short-lived signed URLs.

### 5.1 Capture upload (PDF / image)




**Storage:** Cloudflare R2 (S3-compatible), **private bucket**. Files are never public; access is always via short-lived signed URLs.

### 5.1 Capture upload (PDF / image)

```
Client                         API                              R2
 │ POST /v1/captures (multipart)
 │──────────────────────────────>│ UploadValidator: MIME sniff, size cap, page cap
 │                                │ sha256 checksum → dedupe lookup
 │                                │ FileStorageService->put(captures/{userId}/{uuid})
 │                                │────────────────────────────────>│  (private)
 │                                │ store job_attachments + job_extractions rows
 │                                │ dispatch(ExtractJobDetailsJob)
 │  202 { extraction_id }         │
 │<───────────────────────────────│
```

- v1 uses **direct multipart upload to the API** (≤ 10 MB PDF, ≤ 8 MB image): one code path, server-side validation, checksum dedupe, no CORS surface. R2's presigned `PUT` (1 s–7 days, GET/HEAD/PUT/DELETE only — no POST form uploads) is a Phase 5 optimization for large files.
- Files are keyed `captures/{user_id}/{yyyy}/{mm}/{ulid}.{ext}` so per-user lifecycle rules and deletion are trivial.
- Validation: MIME sniffing (not extension), explicit allow-list (`application/pdf`, `image/jpeg`, `image/png`, `image/webp`, `image/heic`), max size enforced in FormRequest *and* storage config, PDF page count checked before AI dispatch.
- Rejected uploads return `422` with a machine-readable reason and never create rows.

### 5.2 Attachment upload (files attached to a job)

Same pipeline, keyed `attachments/{user_id}/{job_id}/{ulid}.{ext}`, with `job_attachments` as the metadata record (original name, mime, size, checksum, extracted text for search if cheap).

### 5.3 Reading files back

- `GET /v1/attachments/{attachment}` → authorizes ownership → returns `302` to a **presigned GET URL valid for 5 minutes** (or `{ url, expires_at }` JSON when the client wants to control navigation). Never a permanent URL, never a public bucket domain.
- Thumbnails for images are generated client-side where possible; no server-side image service in v1.

### 5.4 Deletion and retention

| Event | Action |
|---|---|
| Attachment deleted by user | delete row immediately, delete object via queued cleanup (tolerates R2 errors) |
| Job soft-deleted | files retained (restore must work) |
| Job force-deleted | files + rows purged |
| Account deleted | `PurgeAccountJob` removes all objects under `captures/{user_id}` and `attachments/{user_id}`, then anonymizes/deletes rows |
| Orphan sweep | scheduled weekly command deletes R2 objects with no DB reference older than 24 h (failed uploads) |

---

## 6. Notification flow

### 6.1 Deadline reminders

```
job.deadline_at (UTC) ──► ScheduleDeadlineRemindersAction
        offsets: 7 / 3 / 1 days before, at 09:00 user-local time
        → creates reminders rows (type=deadline, remind_at UTC, status=pending)
                        │
   scheduler (every minute): php artisan reminders:dispatch-due
                        │  SELECT ... WHERE status='pending' AND remind_at <= now()
                        │  LIMIT 200 FOR UPDATE SKIP LOCKED  (safe for overlap/parallel workers)
                        ▼
   SendDeadlineReminderJob (per reminder, queue: default)
     ├── respects user preferences (email on/off) and quiet hours
     ├── sends mail + database notification (in-app bell)
     ├── broadcasts ReminderDue on the user's private channel
     └── marks status=sent + sent_at  (unique guard ⇒ never sends twice)
```

- Dispatcher command is registered with `withoutOverlapping()`; failures retry 3× with backoff, then set `status=failed` and raise an in-app alert. Re-running the dispatcher is always safe (idempotent by status + unique index on `(reminder_id, channel, sent_on_date)`).
- Timezone changes reschedule only *future* pending reminders (`remind_at > now()`).
- Deadline moved by the user → stale pending deadline reminders are superseded and recreated.

### 6.2 Other notifications

| Trigger | Channel(s) | Notes |
|---|---|---|
| Extraction completed / needs review / failed | in-app (realtime toast), optional email | only emails when the user is away (no websocket session in last 5 min) |
| Daily digest (08:00 local) | email | jobs closing this week, follow-ups due, pipeline summary; skipped when empty |
| Status change (self) | in-app only | keeps the timeline feeling alive without inbox spam |
| Quota at 80% / exhausted | in-app | proactive, prevents surprise failures |
| Security events (password change, new token) | email | mandatory, not toggleable |

### 6.3 Realtime transport

- **Laravel Reverb** (WebSocket) private channels: `App.Models.User.{id}` (base), plus `user.{id}.extractions` and `user.{id}.notifications`.
- Channel authorization goes through Sanctum in `routes/channels.php`; a user can only subscribe to their own channels.
- The frontend degrades gracefully: if the socket fails, TanStack Query polling (2 s, stopping at terminal state) keeps the UX working. Nothing depends solely on a socket.

---

## 7. Security architecture

### 7.1 Authentication & session (Decision #2, assumed approved)

**Chosen:** Sanctum **personal access-token authentication**.

- Registration and login issue a token from `personal_access_tokens`.
- The Next.js client stores the token in `localStorage` and sends `Authorization: Bearer <token>`.
- Logout deletes only the current token; `/auth/me` and all protected routes use `auth:sanctum`.
- Tokens are hashed at rest by Sanctum, scoped by abilities, and revocable.
- Cross-origin cookies, `SANCTUM_STATEFUL_DOMAINS`, and CSRF bootstrap requests are not part of API authentication.

### 7.2 Authorization

- One policy per user-owned model; every controller action calls `authorize()` (or `#[Authorize]` attributes in Laravel 13) — no manual `if ($model->user_id !== auth()->id())` scattered around.
- Foreign resources return **404** (`ModelNotFoundException`), never 403, to prevent ID enumeration.
- Query-level scoping: every list query starts from `auth()->user()->jobs()` relationships; global scopes are avoided (too implicit), but policies + query scoping are mandatory.
- Admin/ops surface (if ever added) is a separate route group with its own guard — never a boolean flag on the normal user.

### 7.3 Input, output and file safety

| Threat | Control |
|---|---|
| Mass assignment | `$fillable` on every model; AI output never passed directly to `create()` — always mapped through `JobCardMapper` |
| SQL injection | Eloquent/query builder only; raw SQL restricted to reviewed FTS/vector expressions with bindings |
| XSS | API returns JSON; the frontend renders user/AI text as text (React escapes by default). Any rich text must be sanitised server-side |
| Prompt injection | scraped/AI-read content is treated as **data**, never instructions: it is placed in a delimited user message, the schema forbids extra fields, and model output can never trigger a fetch, a shell command or a DB write without validation |
| Malicious uploads | MIME sniffing, size caps, extension allow-list, private bucket (no direct public serving), no server-side execution/rendering of uploaded files |
| SSRF via URL capture | private/link-local range blocking after DNS resolution, redirect cap, timeout, body cap, content-type allow-list |
| Stored-URL abuse | apply URLs sanitised to `http`/`https` only before persistence (no `javascript:`/`data:`) |
| CSRF | API authentication uses bearer tokens; no cross-origin cookie dependency |
| CORS | explicit origin allow-list (`app.jobflow.ai`, `localhost:3000`), `supports_credentials: false` |
| Rate limiting | `auth` 5/min per IP+email, `api` 60/min per user, `ai` 10/min + monthly quota, `upload` 20/hour |
| Secret leakage | keys only in server env; `NEXT_PUBLIC_*` restricted to genuinely public values (API base URL); no AI keys in the browser, ever |
| Enumeration/scraping of our API | cursor pagination limits, per-user quotas, generic 404s, request-id logging |
| Data leakage in logs | PII (CV text, emails) redacted in logs; AI prompts logged only as hashes + lengths in production |

### 7.4 Data protection

- CVs and job descriptions are private by default; no public share links in v1 (a signed, expiring share token is designed but disabled).
- Account deletion queues `PurgeAccountJob`: R2 objects removed, rows deleted/anonymised, a deletion receipt email sent. Soft-deleted rows are hard-purged after 30 days.
- Backups: nightly Postgres dump to R2 (separate bucket, 30-day retention) + PITR if the host supports it; restore drill documented before launch.

---

## 8. Infrastructure & deployment

| Environment | Frontend | Backend | Data |
|---|---|---|---|
| Local | `next dev` on :3000 | `php artisan serve`/Herd on :8000 | Postgres 16 (Homebrew) + Redis in Docker |
| Staging | Vercel (preview/prod branch) | small VPS or Forge (api.staging…) | managed Postgres + Redis |
| Production | Vercel (`app.jobflow.ai`) | 2 vCPU / 4 GB VPS (nginx + php-fpm) or Laravel Cloud/Forge (`api.jobflow.ai`) | managed Postgres 16 + Redis + R2 |

**Repositories & CI:** two repositories are in play — `jobflow-api` (this repo; `.github/workflows/api.yml`) and `jobflow-web` (`.github/workflows/web.yml`). The API repo publishes `openapi.json` as an artifact; the web repo fetches it and regenerates TypeScript types, so contract drift fails CI instead of production.

**Processes on the API host:** nginx, php-fpm, `queue:work` (Horizon, queues: `ai`, `default`, `mail`), `schedule:work` (dispatcher + digests), Reverb (WebSocket).

**Deployment (zero-drama):** GitHub Actions → Pint/Larastan/Pest (backend) + ESLint/tsc/build (frontend) → deploy with `php artisan migrate --force` → `config:cache`/`route:cache`/`view:cache` → Horizon restart signal → smoke test `/api/v1/health` + `/up`. Migrations are **forward-only**; destructive changes go through a two-step (add new column → backfill → switch → drop later).

**Environments & secrets:** `.env.example` is the contract (every key documented); no secrets in git; R2 keys scoped to one bucket; separate AI keys per environment so staging spend is visible and capped.

**Scheduled jobs:** `reminders:dispatch-due` (every minute, `withoutOverlapping`), `jobs:send-daily-digest` (hourly check, sends at 08:00 local), `attachments:sweep-orphans` (weekly), `ai:prune-usage-logs` (monthly), `model:prune` (weekly).

---

## 9. Observability & operations

| Concern | Tool / approach |
|---|---|
| Request tracing | `AssignRequestId` middleware (accepts inbound `X-Request-Id`, generates otherwise); the id is added to every log line and returned in error responses (`meta.request_id`) so support can trace a user complaint |
| App logs | structured JSON logs (channel `stack` → daily + stderr); explicit context for user id, job id, extraction id; CV text and prompts redacted in production |
| Errors | Laravel exception handler renders consistent JSON; integrations (Sentry or equivalent) capture unhandled exceptions with the request id attached |
| Queues | Horizon dashboard: wait times, throughput, failed jobs per queue; alert when `ai` queue wait > 60 s or failure rate > 5% |
| AI spend/quality | `ai_usage_logs` aggregated daily: cost per feature, tokens per extraction, failure rate per provider; a monthly review drives prompt/model changes |
| Uptime | `/up` (Laravel health) + `/api/v1/health` (DB, Redis, R2, queue heartbeat) polled externally every minute |
| DB health | slow-query log (> 200 ms) reviewed weekly; `pg_stat_statements` on staging for index tuning |
| Backups | nightly dump → R2, 30-day retention; restore rehearsal documented in `docs/` before launch |
| Alerts | threshold alerts for: queue backlog, failed jobs > N, AI error rate, 5xx rate, disk > 80%, reminder delivery failures |

**Health endpoint must not leak internals:** it returns `{status, checks:{db, cache, queue, storage}}` with booleans only, and requires no auth but no details.

---

## 10. Testing strategy

**Backend (Pest 5 + PostgreSQL)**

| Layer | What is tested | Examples |
|---|---|---|
| Feature (HTTP) | every endpoint: happy path, 422 validation, 401 unauth, 404 cross-user, rate limit | `JobIndexTest`, `CaptureStoreTest`, `ReminderDispatchTest` |
| Unit | pure logic: `MatchScoreCalculator`, `JobNormalizer`, `SkillResolver`, `ReminderScheduler` (timezone/DST) | fixture-driven, no DB |
| AI | agents are faked — never a real API call in tests | `JobExtractionAgent::fake([[...]]);` + a failing-provider test asserting `needs_review` |
| Contract | `assertJsonStructure` on every resource + `assertExactJson` for critical payloads | catches accidental breaking changes before the frontend breaks |
| Isolation | a second user attempting access to every resource type | global negative-test file per model |
| Queue/schedule | `Queue::fake()`, `Bus::fake()`, `Notification::fake()`, `Event::fake()`, `Storage::fake('r2')` | proves dispatch happens without side effects |

Test database: PostgreSQL (not SQLite) because we rely on `jsonb`, `tsvector`, `pgvector` and real constraint behaviour. `RefreshDatabase` + factories with states (`->applied()`, `->withDeadline()`, `->needsReview()`). Fixtures in `tests/Fixtures/extractions/` store real-ish ad texts (LinkedIn-style, Facebook-style, Bangla/English mixed, scanned-circular transcript) for regression testing of prompt/schema changes.

**Frontend**

| Layer | Tool | Scope |
|---|---|---|
| Type/contract | `tsc --noEmit` + generated OpenAPI types | a backend field rename breaks the build |
| Unit/component | Vitest + Testing Library | formatters (deadline countdown, salary), filter reducer, status badge, job card |
| E2E smoke | Playwright | register → capture text → approve → change status → see reminder; runs on staging in CI |
| Accessibility | axe checks in Playwright on key pages | keyboard + labels + contrast |

**Coverage gates:** ≥ 80% on `app/Services`, `app/Actions`, `app/Http/Controllers`; 100% of policy methods exercised (allow + deny).

---

## 11. What we deliberately do NOT build (anti-overengineering guard)

| Rejected | Why |
|---|---|
| Microservices / separate AI service | One deployable API + workers is enough for 10k users and far cheaper to operate |
| Generic repository layer | Eloquent already is the data layer; a repository would only add passthrough methods |
| CQRS / event sourcing | No audit or replay requirement that justifies the complexity |
| Multi-tenancy (orgs/teams) | The product is personal; adding tenancy later is a scoped migration, not a rewrite (every table already has `user_id`) |
| Scraping engine for job boards | Legal/ToS risk, brittle; URL capture handles user-initiated links only |
| Meilisearch/Elasticsearch in v1 | Postgres FTS + trigram indexes cover the corpus; adding a second datastore doubles ops |
| Redis-only everything / no Postgres | We need relational integrity + FTS + pgvector in one place |
| A mobile app in v1 | A mobile-first PWA-style web app ships 3× faster; the token API keeps a native app possible |
| Auto-apply bots | Unsafe, violates third-party ToS, destroys trust if it misfires |




