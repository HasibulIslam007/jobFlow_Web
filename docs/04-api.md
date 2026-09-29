# 04 — API Specification (REST, `/api/v1`)

**Doc version:** 1.0 · Base URL: `https://api.jobflow.ai/api/v1` · Media type: `application/json` (uploads: `multipart/form-data`)
**Publish as OpenAPI 3.1** via `dedoc/scramble`; TypeScript types generated from it into `jobflow-web/src/types/api.d.ts` (separate repository — see `docs/README.md` → Repository layout).
**Envelope note:** the machine-readable source of truth for field names is `03-database.md`; this document defines the HTTP contract.

---

## 1. Global conventions

| Topic | Rule |
|---|---|
| Versioning | path prefix `/v1`; breaking changes ship a new prefix, additive changes do not |
| Auth | Sanctum SPA cookie (browser) or `Authorization: Bearer <token>` (mobile/CLI). Both accepted on every authenticated route |
| Content type | `application/json`; 406-style errors avoided by setting `Accept: application/json` from the client |
| Request tracing | optional inbound `X-Request-Id`, always echoed in `meta.request_id` |
| Idempotency | mutating POSTs accept `Idempotency-Key`; the key + endpoint + user is cached for 24 h and the original response is replayed |
| Timestamps | ISO-8601 UTC with `Z` (`2026-10-04T09:00:00Z`); the client localizes |
| Dates only | `YYYY-MM-DD` (`posted_at`) |
| Money | `salary: { min, max, currency, period, is_negotiable }` object, never a formatted string |
| Pagination | cursor pagination for job-like lists: `page[size]` (default 20, max 100) + `page[cursor]`; responses include `meta.next_cursor` |
| Sorting | `sort=-deadline_at,created_at` (`-` = descending); allow-listed fields only, never raw column injection |
| Filtering | bracket arrays: `filter[status][]=applied&filter[has_deadline]=true`; unknown filters are ignored (never 500) |
| Sparse fields | `fields[jobs]=id,title,status,deadline_at` for narrow payloads (used by the board and dashboard) |
| Rate limits | 429 + `Retry-After`; `X-RateLimit-Limit`/`Remaining` headers on limited routes |
| Validation errors | 422 with `errors: { field: [messages] }` |
| Not found | 404 for both missing and foreign resources (no enumeration) |
| Soft deletes | excluded from all responses unless `?with_trashed=true` is explicitly allowed |

### 1.1 Response envelopes

```json
// success (single)
{ "data": { "id": 12, "title": "Senior React Developer", "...": "..." },
  "meta": { "request_id": "01J...", "generated_at": "2026-09-28T10:12:03Z" } }

// success (list, cursor)
{ "data": [ { "...": "..." } ],
  "meta": { "request_id": "01J...", "per_page": 20, "next_cursor": "eyJpZCI6MTJ9", "prev_cursor": null, "total_hint": 143 },
  "links": { "next": "/api/v1/jobs?page[cursor]=eyJpZCI6MTJ9" } }

// error
{ "message": "The given data was invalid.",
  "code": "validation_failed",
  "errors": { "url": ["The url field must be a valid URL."] },
  "meta": { "request_id": "01J..." } }
```

### 1.2 Error codes (machine-readable `code` field)

| HTTP | code | Meaning |
|---|---|---|
| 400 | `bad_request` | Malformed request (e.g. bad cursor) |
| 401 | `unauthenticated` | Missing/expired session or token |
| 403 | `forbidden` | Authenticated but not allowed (rare; ownership failures return 404) |
| 404 | `not_found` | Resource missing or not owned |
| 409 | `conflict` | Duplicate resource, invalid status transition, extraction already approved |
| 422 | `validation_failed` | Field-level validation errors |
| 429 | `rate_limited` / `quota_exceeded` | Too many requests / AI quota spent (with `retry_after`) |
| 500 | `server_error` | Unexpected; `request_id` is the support handle |
| 503 | `provider_unavailable` | Upstream AI provider unreachable and no failover succeeded (capture stays queued/needs_review) |

---

## 2. Authentication APIs

| Method | Path | Auth | Purpose |
|---|---|---|---|
| POST | `/v1/auth/register` | – | Create account → 201 + `{ token, user, authenticated }` |
| POST | `/v1/auth/login` | – | Email + password → `{ token, user, authenticated }` |
| POST | `/v1/auth/logout` | ✔ | Invalidate current session/token |
| GET | `/v1/auth/me` | ✔ | Current user + prefs + quota usage + onboarding state |
| POST | `/v1/auth/forgot-password` | – | Email a reset link (always 200, no user enumeration) |
| POST | `/v1/auth/reset-password` | – | token + email + new password; invalidates other sessions |
| POST | `/v1/auth/email/verification-notification` | ✔ | Resend verification mail (throttled 1/min) |
| GET | `/v1/auth/email/verify/{id}/{hash}` | – (signed) | Verify email address |
| PUT | `/v1/auth/password` | ✔ | Change password (requires `current_password`) |
| POST | `/v1/auth/confirm-password` | ✔ | Confirm password for sensitive actions |
| GET | `/v1/auth/sessions` | ✔ | List active browser sessions |
| DELETE | `/v1/auth/sessions/{id}` | ✔ | Revoke a session |
| GET/POST | `/v1/auth/tokens` | ✔ | List / create API tokens (name + abilities) — token shown once |
| DELETE | `/v1/auth/tokens/{id}` | ✔ | Revoke a token |

**Example — register**

```http
POST /api/v1/auth/register
{ "name": "Rakib Hasan", "email": "rakib@example.com",
  "password": "correct-horse-battery", "password_confirmation": "correct-horse-battery",
  "timezone": "Asia/Dhaka" }
```

```json
201 { "data": { "id": 41, "name": "Rakib Hasan", "email": "rakib@example.com",
        "email_verified": false, "timezone": "Asia/Dhaka",
        "onboarding": { "completed": false },
        "quota": { "extractions_used": 0, "extractions_limit": 30, "resets_at": "2026-10-01T00:00:00Z" } } }
```

**Registration rules:** email lowercased + unique; password ≥ 8 chars with a breach check (warn-only if the checker is unavailable); timezone must be a valid IANA name (default `Asia/Dhaka`); default `notification_preferences` row created in the same transaction.

**Quota header on AI routes:** `X-AI-Quota: used=12; limit=30; resets=2026-10-01T00:00:00Z`.

---

## 3. Job APIs

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/jobs` | List with filters/sort/search/cursor pagination |
| POST | `/v1/jobs` | Create manually (no AI) |
| GET | `/v1/jobs/{job}` | Full job card incl. skills, tags, counts, next reminder |
| PATCH | `/v1/jobs/{job}` | Partial update (any user-editable field) |
| DELETE | `/v1/jobs/{job}` | Soft delete |
| POST | `/v1/jobs/{job}/restore` | Restore a soft-deleted job |
| PATCH | `/v1/jobs/{job}/status` | Change status (+ optional note) → writes history |
| GET | `/v1/jobs/{job}/timeline` | Merged chronological events (status, notes, reminders, attachments, AI runs) |
| POST | `/v1/jobs/{job}/duplicate-check` | Advisory duplicate check for a title+company pair |
| POST | `/v1/jobs/bulk` | Bulk `status` / `tag` / `delete` / `archive` with per-item results |
| GET | `/v1/jobs/stats` | Per-status counts + deadline buckets (also used by the board header) |
| GET | `/v1/jobs/export` | CSV/JSON export of the filtered set (streamed) |

### 3.1 List filters (all optional, all combinable)

```http
GET /api/v1/jobs
  ?filter[status][]=saved&filter[status][]=applied
  &filter[has_deadline]=true
  &filter[deadline_before]=2026-10-15T23:59:59Z
  &filter[deadline_after]=2026-10-01T00:00:00Z
  &filter[source_type][]=pdf
  &filter[company_id]=9
  &filter[work_mode][]=remote
  &filter[employment_type][]=full_time
  &filter[skill_ids][]=12&filter[skill_ids][]=31
  &filter[tag_ids][]=4
  &filter[is_favorite]=true
  &filter[archived]=false
  &q=react%20dhaka
  &sort=deadline_at
  &fields[jobs]=id,title,company_name,status,deadline_at,is_favorite
  &page[size]=30
```

**Response item (list shape):**

```json
{ "id": 218, "title": "Senior React Developer", "company": { "id": 9, "name": "Brain Station 23" },
  "location": "Dhaka, Bangladesh", "work_mode": "hybrid", "employment_type": "full_time",
  "status": "applied", "is_favorite": false, "deadline_at": "2026-10-04T09:00:00Z",
  "deadline_confidence": "high", "days_to_deadline": 6, "applied_at": "2026-09-20T05:12:00Z",
  "skills": [{ "id": 12, "name": "React", "requirement": "required" }],
  "tags": [{ "id": 4, "name": "referral", "color": "#2563eb" }],
  "next_reminder": { "id": 77, "remind_at": "2026-10-01T03:00:00Z", "type": "deadline" },
  "has_notes": true, "attachments_count": 2,
  "created_at": "2026-09-18T11:00:00Z", "updated_at": "2026-09-20T05:12:00Z" }
```

**Full item (detail shape)** adds `description`, `requirements[]`, `responsibilities[]`, `benefits[]`, `salary{}`, `experience{level,min_years,max_years}`, `education_requirement`, `apply_url`, `apply_email`, `contact_info`, `posted_at`, `source{}` (type, url, capture id), `ai{provider,model,confidence,extracted_at}`, `notes[]`, `reminders[]`, `attachments[]`, `status_history[]`.

### 3.2 Create / update rules

- Required on create: `title`. Everything else optional (manual entry must be fast).
- `apply_url`/`source.url` sanitised to `http(s)` — other schemes rejected with 422.
- `salary.currency` must be a 3-letter ISO code; unknown currency → 422 (never silently coerced).
- `deadline_at` must be in the future on manual create (past deadlines are allowed via import/AI so historical cards can be recorded, flagged with `deadline_passed: true`).
- Setting `status` via PATCH is **rejected** (409 `conflict`, `use_status_endpoint`) to guarantee history is always written.
- `PATCH` is partial; `null` clears a field, omitting a key leaves it unchanged.

### 3.3 Status change

```http
PATCH /api/v1/jobs/218/status
{ "status": "interview", "note": "HR call scheduled for 2 Oct, 11:00", "applied_at": "2026-09-20T05:12:00Z" }
```

```json
200 { "data": { "id": 218, "status": "interview", "applied_at": "2026-09-20T05:12:00Z",
        "history_entry": { "from": "applied", "to": "interview", "changed_at": "2026-09-27T07:00:00Z" },
        "reminders_created": [ { "id": 91, "type": "interview", "remind_at": "2026-10-01T03:00:00Z" } ] } }
```

- Invalid transition → 409 with `allowed_transitions: [...]` so the UI can render only legal actions.
- Moving to `applied` sets `applied_at` once (never overwritten).
- Moving to `interview` may create a reminder if an interview time is supplied.

### 3.4 Bulk operations

```http
POST /api/v1/jobs/bulk
{ "action": "status", "job_ids": [218, 219, 240], "payload": { "status": "rejected", "note": "no response" } }
```

```json
200 { "data": { "succeeded": [218, 219], "failed": [ { "id": 240, "code": "not_found" } ] } }
```

Capped at 200 ids per call; each item is authorized individually; partial failure never aborts the batch.

---

## 4. Capture APIs (System 1)

| Method | Path | Purpose |
|---|---|---|
| POST | `/v1/captures` | Submit text / file / URL → **202** + `extraction_id` |
| GET | `/v1/captures` | Recent captures for this user (history + failure debugging) |
| GET | `/v1/captures/{extraction}` | Status + (when ready) the extracted draft + confidence + missing fields |
| POST | `/v1/captures/{extraction}/approve` | Confirm the (possibly edited) draft → creates the job |
| POST | `/v1/captures/{extraction}/discard` | Discard a draft (source file retained per retention policy) |
| POST | `/v1/captures/{extraction}/retry` | Re-run a failed/needs_review extraction (counts against quota) |

### 4.1 Submit

```http
POST /api/v1/captures                       Content-Type: multipart/form-data
type=text|pdf|image|url
# type=text  → text=<raw pasted job description>            (≤ 20,000 chars)
# type=pdf   → file=<pdf ≤ 10 MB>                            (+ optional source_url)
# type=image → file=<jpg|png|webp|heic ≤ 8 MB>              (+ optional source_url)
# type=url   → url=<https://…>                               (≤ 2048 chars, http(s) only)

Headers: Idempotency-Key: <uuid>            (optional but recommended)
```

```json
202 { "data": { "extraction_id": 512, "status": "queued", "source_type": "text",
        "estimated_seconds": 12, "queue_position": 1 },
      "meta": { "quota": { "used": 13, "limit": 30, "resets_at": "2026-10-01T00:00:00Z" } } }
```

### 4.2 Poll status

```http
GET /api/v1/captures/512
```

```json
// processing
200 { "data": { "extraction_id": 512, "status": "processing", "started_at": "2026-09-28T10:00:02Z" } }

// completed — draft is ready for review
200 { "data": { "extraction_id": 512, "status": "completed",
        "draft": {
          "title": "Senior React Developer", "company_name": "Brain Station 23",
          "location": "Dhaka, Bangladesh", "work_mode": "hybrid", "employment_type": "full_time",
          "experience": { "level": "senior", "min_years": 4, "max_years": null },
          "education_requirement": "BSc in CSE or equivalent",
          "salary": { "min": 120000, "max": 180000, "currency": "BDT", "period": "monthly", "is_negotiable": true },
          "deadline_at": "2026-10-04T09:00:00Z", "posted_at": "2026-09-14",
          "apply_url": "https://…/careers/senior-react", "apply_email": null, "contact_info": null,
          "skills": [ { "name": "React", "requirement": "required" },
                      { "name": "TypeScript", "requirement": "required" },
                      { "name": "Docker", "requirement": "preferred" } ],
          "responsibilities": ["…"], "requirements": ["…"], "benefits": ["…"]
        },
        "confidence": { "title": "high", "deadline_at": "low", "salary": "medium" },
        "missing_fields": ["contact_info"],
        "duplicate_warning": { "job_id": 190, "title": "React Developer (Senior)", "similarity": 0.86 },
        "provider": "openai", "model": "…", "prompt_version": "job_extraction.v1",
        "duration_ms": 8940 } }

// needs_review
200 { "data": { "extraction_id": 513, "status": "needs_review",
        "review_reason": "deadline_not_found", "review_message": "We could not find an application deadline. Add it before saving.",
        "draft": { "…": "partial values where available" },
        "missing_fields": ["deadline_at", "title"] } }
```

### 4.3 Approve (human-in-the-loop)

```http
POST /api/v1/captures/512/approve
{ "job": { "title": "Senior React Developer", "company_name": "Brain Station 23",
           "deadline_at": "2026-10-04T00:00:00Z", "status": "saved", "skills": [ { "name": "React", "requirement": "required" } ] },
  "create_reminders": true,
  "reminder_offsets": [7,3,1] }
```

```json
201 { "data": { "job": { "id": 218, "title": "Senior React Developer", "status": "saved", "deadline_at": "2026-10-04T00:00:00Z" },
        "reminders_created": [ { "id": 77, "type": "deadline", "remind_at": "2026-09-27T03:00:00Z" },
                               { "id": 78, "type": "deadline", "remind_at": "2026-10-01T03:00:00Z" },
                               { "id": 79, "type": "deadline", "remind_at": "2026-10-03T03:00:00Z" } ],
        "company_reused": true } }
```

- Approval is **idempotent**: a second call returns `409 conflict` with the already-created `job_id` (never a second job).
- Reminder times are computed in the user's timezone at 09:00 local, then stored as UTC.
- Approving with a *past* deadline is allowed but returns a `warning` and creates no deadline reminders.
- Any field the user changed is diffed against `mapped_job` and stored for prompt-accuracy analytics.

### 4.4 Quota behaviour

| Situation | Response |
|---|---|
| Under quota | 202, `X-AI-Quota` header reflects usage |
| At 80% | 202 + `meta.warning: "quota_80_percent"` (UI shows a soft nudge) |
| Exhausted | **429** `quota_exceeded`, `resets_at` in `meta`, nothing queued, no row created |
| Duplicate input (same hash) | 200 with the **existing** extraction (no new charge) |

---

## 5. Reminder APIs

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/reminders` | List (filters: `scope=upcoming｜past｜all`, `type`, `job_id`, `status`) |
| POST | `/v1/reminders` | Create manual reminder (job optional) |
| PATCH | `/v1/reminders/{reminder}` | Update title/body/time (only while `pending`) |
| DELETE | `/v1/reminders/{reminder}` | Delete a pending reminder (sent reminders are archived, not deleted) |
| POST | `/v1/reminders/{reminder}/dismiss` | Mark dismissed (stops notifications, keeps the record) |
| POST | `/v1/reminders/{reminder}/snooze` | Shift by `{ "days": 1 }` or `{ "until": "…" }` |
| POST | `/v1/jobs/{job}/reminders/reschedule` | Recompute deadline reminders after a deadline change |
| GET | `/v1/reminders/calendar` | Month view payload (`from`,`to`) for the calendar page |

```http
POST /api/v1/reminders
{ "job_id": 218, "type": "follow_up", "title": "Follow up with HR",
  "body": "Ping Ms. Rahman if no reply", "remind_at": "2026-10-06T04:00:00Z", "channels": ["mail","database"] }
```

```json
201 { "data": { "id": 92, "job_id": 218, "job_title": "Senior React Developer", "type": "follow_up",
        "title": "Follow up with HR", "remind_at": "2026-10-06T04:00:00Z",
        "remind_at_local": "2026-10-06T10:00:00+06:00", "status": "pending",
        "channels": ["mail","database"], "source": "manual" } }
```

Rules: `remind_at` must be in the future (422 otherwise); reminders are capped at 100 pending per user (409 `too_many_reminders`); a reminder whose job is deleted becomes free-standing (`job_id: null`) rather than vanishing silently; `PATCH` on a `sent` reminder returns 409.

---

## 6. Supporting resource APIs

### 6.1 Notes

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/jobs/{job}/notes` | Newest first, cursor paginated |
| POST | `/v1/jobs/{job}/notes` | `{ body, is_pinned? }` → 201 |
| PATCH | `/v1/notes/{note}` | Edit body/pin |
| DELETE | `/v1/notes/{note}` | Soft delete |

### 6.2 Tags

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/tags` | All tags + usage counts |
| POST | `/v1/tags` | `{ name, color }`; unique per user (409 on duplicate name) |
| PATCH/DELETE | `/v1/tags/{tag}` | Rename/recolour/delete (delete detaches from jobs) |
| PUT | `/v1/jobs/{job}/tags` | Replace the job's tag set `{ tag_ids: [4,7] }` |

### 6.3 Attachments

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/jobs/{job}/attachments` | Metadata only (no URLs) |
| POST | `/v1/jobs/{job}/attachments` | multipart upload (≤ 10 MB) |
| POST | `/v1/jobs/{job}/attachments/link` | Attach an existing upload by `attachment_id` (used after extraction) |
| GET | `/v1/attachments/{attachment}` | 302 redirect to a 5-minute signed R2 URL |
| DELETE | `/v1/attachments/{attachment}` | Soft delete + queued object cleanup |

### 6.4 Companies, skills, autocomplete

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/companies?q=brain` | Autocomplete (name match, trigram) + `jobs_count`, `applied_count` |
| GET | `/v1/companies/{company}` | Company detail with its jobs grouped by status |
| PATCH | `/v1/companies/{company}` | Edit name/website/notes (renaming updates `normalized_name`) |
| GET | `/v1/skills?q=rea&limit=10` | Skill autocomplete over catalog `name`/`aliases` (cached 24 h — this is the one place `use cache`-style caching is safe) |

### 6.5 Notifications (in-app centre)

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/notifications` | Database notifications, newest first, `filter[unread]=true` |
| GET | `/v1/notifications/unread-count` | Badge count (cheap, polled or pushed) |
| POST | `/v1/notifications/{id}/read` | Mark one read |
| POST | `/v1/notifications/read-all` | Mark all read |
| DELETE | `/v1/notifications/{notification}` | Dismiss |

### 6.6 Dashboard

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/dashboard` | One call: per-status counts, upcoming deadlines (7/30 d), this week's activity, funnel, recent captures, quota |
| GET | `/v1/dashboard/activity?range=30d` | Time series for the analytics chart (Phase 3/5) |

```json
200 { "data": {
  "counts": { "saved": 12, "preparing": 3, "applied": 21, "interview": 4, "offer": 1, "rejected": 9, "archived": 5 },
  "upcoming_deadlines": { "next_7_days": 4, "next_30_days": 11, "overdue_unhandled": 1 },
  "this_week": { "applied": 6, "interviews": 2, "captures": 9, "approved": 7 },
  "funnel": [ { "from": "saved", "to": "applied", "rate": 0.64 }, { "from": "applied", "to": "interview", "rate": 0.19 } ],
  "next_deadline": { "job_id": 218, "title": "Senior React Developer", "deadline_at": "2026-10-04T09:00:00Z" },
  "quota": { "extractions_used": 13, "extractions_limit": 30, "resets_at": "2026-10-01T00:00:00Z" } },
  "meta": { "request_id": "01J…", "cached_at": "2026-09-28T10:00:00Z" } }
```

---

## 7. Profile, preferences and data rights

| Method | Path | Notes |
|---|---|---|
| GET | `/v1/profile` | Same payload as `/auth/me` (kept separate for the settings page) |
| PATCH | `/v1/profile` | name, timezone, locale |
| PUT | `/v1/profile/avatar` | multipart ≤ 2 MB, square-cropped client-side; old avatar object deleted |
| DELETE | `/v1/profile/avatar` | Revert to initials |
| GET | `/v1/profile/notification-preferences` | Current preferences |
| PATCH | `/v1/profile/notification-preferences` | deadline offsets, digest hour, quiet hours, channel toggles |
| GET | `/v1/profile/usage` | AI usage this period: calls, tokens, estimated cost, quota, per-feature breakdown |
| GET | `/v1/profile/export` | Queued export (all jobs/notes/reminders as JSON+CSV links, signed 24 h) |
| DELETE | `/v1/profile` | Request account deletion (`{ password, confirm: "DELETE" }`) → 202, purge job, session invalidated |

Timezone change rule: updates `users.timezone` and reschedules **pending future** reminders/digests only; already-sent records are untouched (documented in the response as `reminders_rescheduled: 6`).

---

## 8. Future AI APIs (Phase 5 — designed now, built later)

| Method | Path | Purpose |
|---|---|---|
| GET | `/v1/cvs` | List CVs (primary first) |
| POST | `/v1/cvs` | Upload a CV → 202 (parse + embed in background) |
| GET | `/v1/cvs/{cv}` | Parsed profile + analysis history |
| PATCH | `/v1/cvs/{cv}` | Edit `title`, `is_primary`, parsed profile corrections |
| DELETE | `/v1/cvs/{cv}` | Delete CV + file + analyses |
| POST | `/v1/cvs/{cv}/analyses` | Start `{ type: general_review｜ats_check｜roadmap, target_role? }` → 202 |
| POST | `/v1/jobs/{job}/matches` | Start a match `{ cv_id? }` (default: primary CV) → 202 |
| GET | `/v1/jobs/{job}/matches` | Latest match result(s) for the job (cached) |
| GET | `/v1/cvs/{cv}/matches` | Ranked job list by score for this CV |
| POST | `/v1/jobs/{job}/cover-letters` | Generate `{ cv_id?, tone }` → 202 |
| GET/PATCH/DELETE | `/v1/cover-letters/{letter}` | Read/edit (user edits are authoritative) / delete |
| POST | `/v1/jobs/{job}/interview-prep` | Generate a prep pack → 202 |
| GET | `/v1/career/roadmap?target_role=` | Roadmap: skills → projects → timeline |
| GET | `/v1/jobs?q=…&mode=semantic` | Phase 5 search mode using pgvector similarity |
| GET | `/v1/ai/usage` | Alias of `/profile/usage` for AI-specific dashboards |

**Match response contract (the key one):**

```json
200 { "data": {
  "cv_id": 5, "job_id": 218, "type": "skill_gap", "status": "completed",
  "match_score": 75, "score_breakdown": { "required_skills": 0.55, "preferred_skills": 0.8,
      "experience": 1.0, "education": 0.0, "location": 1.0 },
  "matched_skills": [ { "name": "React", "requirement": "required" },
                      { "name": "TypeScript", "requirement": "required" } ],
  "missing_skills": [ { "name": "Docker", "requirement": "required", "priority": "high" },
                      { "name": "AWS", "requirement": "preferred", "priority": "medium" } ],
  "experience": { "required_min_years": 4, "cv_years": 3, "verdict": "slightly_below" },
  "education": { "required": "BSc in CSE", "cv": "BSc in CSE", "verdict": "match" },
  "strengths": ["Strong React+TypeScript project history with measurable impact"],
  "suggestions": ["Add containerisation experience or a Docker side project",
                  "Quantify your last role's impact (users, latency, revenue)"],
  "provider": "openai", "prompt_version": "match.v1", "generated_at": "2026-09-28T10:00:00Z" } }
```

Rules: `match_score` is `null` (never 0) when the job has no extractable skills; `score_breakdown` is always present so the number is explainable; results are cached by `(cv_id, job_id, prompt_version, source_hash)` so repeated views are free; every AI surface is rate-limited and quota-checked like captures.

---

## 9. Realtime channels (Reverb)

| Channel | Auth | Events |
|---|---|---|
| `private-App.Models.User.{id}` | Sanctum | `notification.created` (generic in-app bell) |
| `private-user.{id}.extractions` | Sanctum | `ExtractionQueued`, `ExtractionProcessing`, `ExtractionCompleted`, `ExtractionFailed` (payload: ids + status + reason, never full PII) |
| `private-user.{id}.jobs` | Sanctum | `JobStatusChanged`, `ReminderDue` (so multiple tabs stay consistent) |

Payloads are minimal (`{ id, status, review_reason, updated_at }`) because the client re-fetches the authoritative resource through the API. Polling remains a supported fallback for every event.

---

## 10. Contract rules (how the API stays clean)

1. **No business logic in resources** — a resource maps fields; if a value needs computation, it is computed in a service (e.g. `days_to_deadline`) and passed in.
2. **Additive-only within `/v1`** — new optional fields are fine; renaming/removing/retyping requires `/v2` or a documented deprecation window with `Sunset` headers.
3. **Never leak internals** — no stack traces, no SQL, no provider keys, no other users' ids in errors.
4. **Every list is bounded** — default 20, hard max 100; no "return everything" endpoints.
5. **Every mutation is auditable** — status changes and approvals write history rows; AI calls write usage rows.
6. **Errors are actionable** — `code` tells the client what to do (`quota_exceeded` → upgrade prompt; `validation_failed` → field errors; `conflict` → re-fetch).
7. **Timezones are explicit** — responses include both UTC and, where user-facing, a `*_local` variant for the user's timezone.
8. **Idempotency for anything expensive** — capture submission, approve, cover-letter generation.




