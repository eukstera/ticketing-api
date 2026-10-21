# Changes

## 2026-10-21 — New dev database (ticketing_api_v2)

By user request, this repo moved to its own database instead of resetting the shared one.

- **Why:** the Laragon copy at `C:\laragon\www\TicketingSystem-API` (vhost `TicketingSystem-API.gbtrghsc.dev`) also uses `ticketing_api` on port 3309. A reset there would have wiped that copy's data too. **That copy still uses `ticketing_api`, which was left untouched.**
- **Backup** of `ticketing_api` taken first: `storage/app/backups/ticketing_api_2026-10-21_0758_pre-v2.sql` (gitignored).
- **New database:** `ticketing_api_v2` (utf8mb4 / utf8mb4_unicode_ci) on 127.0.0.1:3309. Our `.env` and `.env.example` now set `DB_DATABASE=ticketing_api_v2`.
- **Migrations:** `migrate:fresh` ran all 22 migrations. No `--seed`.
- **Seeders restructured:**
  - `DatabaseSeeder` now calls only `ReferenceDataSeeder`.
  - `ReferenceDataSeeder` is new and idempotent. It upserts by id, using the same ids and values as the old database (IT = 1, Department Head = 2, Tech Support = 1, and so on).
  - The demo users and the 50 factory tickets moved to `DemoDataSeeder`, which never runs by default (`php artisan db:seed --class=DemoDataSeeder`).
  - The per-table seeders are unchanged; the test harness uses them.
- **Seeded:** roles 2, departments 2, designations 2, statuses 4, priorities 7 (sla_days 1/2/3/10/1/2/3), date categories 3, concern types 3, nature of work 2.
- **Accounts:** one account was recreated by request (user id 1). There are no tickets, comments, activities or devices.

## 2026-10-20 — Phase 2 (features): API contract (spec, not yet implemented)

These routes are bearer-auth and use the Phase 1 envelope and error codes.

### (a) Search and filters on ticket lists

These apply to `GET /getDepartmentTickets` and `GET /getMyTickets`. All params are optional, and the existing ones keep working.

| Param | Type | Behavior |
|---|---|---|
| `search` | string | Matches `ticket_number`, `name_of_area`, requester full name, and concern (type of concern or nature of work). Case-insensitive `LIKE`. |
| `status_id` | int (status id) | **new and preferred.** Filters by id. |
| `priority_id` | int (priority id) | **new and preferred.** Filters by id. |
| `status` | string (status label) | legacy, still supported as today (filters by label, e.g. dashboard deep-links). Ignored when `status_id` is sent. |
| `priority` | string (priority level) | legacy, still supported as today (filters by level). Ignored when `priority_id` is sent. |
| `date_from`, `date_to` | `Y-m-d` | Filters on the requested date (Requested Date activity), inclusive. `date_to` must be on or after `date_from`. |
| `page` | int | as today |
| `per_page` | int | 1–100, default 10 |
| `sort` | `requested_at` \| `ticket_number` \| `priority` \| `status` | default `requested_at` |
| `dir` | `asc` \| `desc` | default `desc` |

Response changes:
- The paginator and `priority_sla` are unchanged.
- **Breaking change:** `status_counts` becomes an array of `{ id, label, count }`, including statuses with a count of 0, plus `{ id: null, label: "All", count }` as the first entry.
- **Breaking change:** `priority_counts` becomes an array of `{ id, level, sla_days, count }`.
- Each count ignores its own filter, so the badges stay meaningful:
  - `status_counts` respects `search`, the date range and the priority filter, but ignores the status filter.
  - `priority_counts` respects `search`, the date range and the status filter, but ignores the priority filter.
- Every row carries `department_id`, `status_id` and `priority_id` (numbers) next to the existing `status` and `priority` labels. `department_id` ships in Phase 1 (see below).

### (b) Ticket detail

`GET /tickets/{ticket}`. Allowed for members of the ticket's handling department, the requester, or an admin. Otherwise 403; an unknown ticket returns 404.
```
result: { ticket: {
  id, ticket_number, department: { id, name }, status: { id, label }, priority: { id, level, sla_days },
  type: "IT" | "Engineering",
  details: { ...IT fields (type_of_concern, type_of_tech_support, remarks, devices: [{ name, qty }])
             | Engineering fields (nature_of_work, other_nature_of_work, name_of_area, ...) },
  requester: { id, first_name, last_name } | { name: string },   // requestor portal tickets carry text only
  assignee: { id, first_name, last_name } | null,
  is_overdue: boolean, due_at: ISO8601 | null,
  timeline: [{ id, category: "Requested Date" | "Assigned Date" | "Completed Date", user: string | null, created_at }],
  comments: [{ id, comment, user: { id, first_name, last_name }, created_at, updated_at }],
  created_at, updated_at } }
```

### My profile

Both routes require auth, are throttled to 10 requests per minute per user (429 beyond that), and use the standard envelope.

| Method | Path | Body | Response |
|---|---|---|---|
| PATCH | /me | `first_name` (string, max 100), `last_name` (string, max 100). Both are optional; send at least one. | 200 `{ user: User }` (Phase 1 `User` shape) |
| PATCH | /me/password | `current_password`, `password` (min 8, mixed case and a number, different from the current one), `password_confirmation` | 200 `{}`. A wrong `current_password` returns 422 with `errors.current_password`. |

- `role_id`, `department_id`, `designation_id`, `status` and `email` are **not** editable through `/me`. If sent, they are ignored. Only admins can change them, through `/admin/users`.
- A successful password change revokes all of the user's **other** tokens. The token used for the request stays valid.

### Comments

`DELETE /tickets/comments/{comment}` deletes a comment. Only the author or an admin may do this; anyone else gets 403. On success it returns 200 `{ success, message, result: {} }`.

### (c) Admin CRUD

Every route in this section requires the `admin` gate, meaning the caller's role is "Department Head"; anyone else gets 403. Lists are paginated with `?page`, `per_page` (default 15) and `search`, and the paginator sits under `result.<plural>`. `DELETE` is a **soft deactivate** (`status=false`), because existing tickets reference these rows. It returns 200, and creates return 201.

| Resource | Routes | Writable fields |
|---|---|---|
| Users | `GET/POST /admin/users`, `GET/PATCH /admin/users/{user}`, `DELETE /admin/users/{user}` (deactivates the user and revokes their tokens) | first_name, last_name, email, password (optional on PATCH), department_id, designation_id, role_id, status |
| Roles | `GET /roles` (bearer, any user; active roles only: `{ roles: [{ id, name, status }] }`), `POST /admin/roles`, `PATCH /admin/roles/{role}`, `DELETE /admin/roles/{role}` | name, status. `roles.status` already exists in the schema, so no migration is needed. The "Department Head" role cannot be renamed or deactivated (409). Deactivating a role that active users still hold returns 409 `{ message, errors: { role: ["N active users hold this role"] } }`. |
| Departments | `GET/POST /admin/departments`, `PATCH/DELETE /admin/departments/{department}` | name, status |
| Designations | `GET /admin/designations?department_id`, `PATCH/DELETE /admin/designations/{designation}` (create stays `POST /designations`) | designation, department_id, status |
| Priorities | `GET/POST /admin/priorities`, `PATCH/DELETE /admin/priorities/{priority}` | level, sla_days (int ≥ 0), department_id, status |
| Concern types | `GET/POST /admin/concern-types`, `PATCH/DELETE /admin/concern-types/{id}` | label, status |
| Nature of work | `GET/POST /admin/nature-of-work`, `PATCH/DELETE /admin/nature-of-work/{id}` | label, status |

**List conventions (confirmed for the frontend):**
- Result keys: `result.users`, `result.roles`, `result.departments`, `result.designations`, `result.priorities`, `result.concern_types`, `result.nature_of_work`.
- Each key holds a Laravel paginator: `{ data: [...], current_page, last_page, per_page, total, from, to, links, ... }`. The frontend may rely on `data`, `current_page`, `last_page`, `per_page` and `total`.
- Query params on every admin list:
  - `search` (case-insensitive, matched on the name, label or level field; for users, on first name, last name or email)
  - `status=active|inactive|all`. Omitting `status` is the same as `all`.
  - `page`
  - `per_page` (1–100, default 15)
- `/admin/designations` and `/admin/priorities` also accept an optional `department_id` filter.
- Row shapes:
  - designation `{ id, designation, department_id, status }`
  - priority `{ id, level, sla_days, department_id, status }` (`level` is text)
  - department `{ id, name, status }`
  - role `{ id, name, status }`
  - concern type and nature of work `{ id, label, status }`
  - admin user rows use the Phase 1 `User` shape: `department { id, name }`, `designation { id, name }`, `role { id, name }`
- `GET /lookups/departments` (any authenticated user, active only) returns `{ departments: [{ id, name }] }`. Use it for department dropdowns, including on admin screens.

**Validation details (Phase 2):**
- These all return 422:
  - `per_page` outside 1–100
  - an unknown `status` value on admin lists
  - an invalid `sort`, `dir` or date on ticket lists
- An unknown id on any route returns 404 `{ success: false, message }`.
- `is_overdue` is true when the ticket's status is Overdue, or when the ticket is open or in progress and past `due_at`. It is false for Completed tickets.
- `due_at` = the requested date + `sla_days` days, taken at the start of that day. This is the same calendar-day rule `tickets:promote-overdue` uses. `due_at` is null when there is no requested date or when `sla_days` is 0.
- The detail `timeline` is ordered by category (Requested, Assigned, Completed), with one row per category. A reassignment updates the Assigned row in place.
- The profile throttle counts failed attempts too.

**Status on PATCH:** every admin resource accepts `status` (boolean) on `PATCH`.

**Reactivate:** `PATCH` with `{ status: true }` reactivates any deactivated row (users, roles, departments, designations, priorities, concern types, nature of work). Admin list endpoints accept `?status=active|inactive|all` (default `all`). Deactivated rows are excluded from the public and lookup endpoints (`/designations`, `/priority`, `/lookups/*`, `/employees`). A deactivated user cannot log in (403).

Admin resource shapes are `{ id, name|label|level..., status: boolean, created_at, updated_at }`. A user row uses the Phase 1 `User` shape.

## 2026-10-20 — Phase 1 (stabilize): decisions and API contract

### Decisions (user-approved)

- **Admin:** the role named "Department Head" is the admin, checked by role name through Gate `admin`, never by id. Admin rights are currently GLOBAL, meaning not scoped to the head's own department; this may be narrowed later. No existing user's `role_id` is changed.
- **Registration:** public `POST /register` is removed. Accounts are created through `POST /admin/users` (admin only).
- **Assignment:** any member of the ticket's handling department (`tickets.department`) may assign the ticket to any member of that department, including themselves ("take ticket"). Completed tickets cannot be assigned. A reassignment updates the existing Assigned activity instead of adding a new row. `tickets.assigned_user` is kept in sync.
- **Tokens:** Sanctum tokens expire after 7 days.
- **CORS:** allowed origins come from `CORS_ALLOWED_ORIGINS` (comma-separated). The dev defaults are `http://localhost:3000` and `https://snaking-easing-mystify.ngrok-free.dev`. `Content-Disposition` is exposed and credentials are false. The prod origin is set on deploy.
- **Migrations:** additive only. The dev database was backed up with mysqldump to `storage/app/backups/` (gitignored) before the first migrate.

### Data changes on dev DB (approved)

- Backup: `storage/app/backups/ticketing_api_2026-10-20_pre-phase1.sql` (gitignored).
- Duplicate ticket numbers renumbered so the unique index could be added. Only these 2 rows were touched:
  - ticket id 50: `10-906-2026` → `10-1000-2026`
  - ticket id 46: `10-637-2026` → `10-1001-2026`
  - The migration's `down()` drops only the index.
- `tickets:promote-overdue` was run once to verify it. It promoted 22 tickets to Overdue, the same change the old middleware would have made on the next request.

- A dev-only account was created for the user by request (user id 13).

### Behavior changes

- Ticket number sequence: the number has no department in it, so IT and Engineering would collide under the new unique index. The sequence is now **global per year and month** (new `ticket_sequences` table), and `TicketNumberGenerator::generate()` ignores its department argument. The `MM-XXX-YYYY` format with 3-digit padding is unchanged. The next dev ticket will be `10-1002-2026`.
- New `cache` and `cache_locks` tables, needed by the scheduler's `withoutOverlapping()`. `sessions`, `jobs` and `password_reset_tokens` are still missing; they're unused by the bearer-token API.

- 2026-10-21: the dev CORS origin `http://192.168.117.86:3000` (LAN frontend) was added to `.env` and `.env.example`, by user request.

### Dev serving notes

- This repo is served by `php artisan serve` on `127.0.0.1:8000`, so it is reachable only from this machine. For LAN access, run `php artisan serve --host=0.0.0.0 --port=8000` and allow port 8000 through the Windows firewall. The API is then at `http://192.168.117.86:8000/api`.
- The Laragon vhost `TicketingSystem-API.gbtrghsc.dev` (nginx ports 90 and 443) points at a **different copy**, `C:/laragon/www/TicketingSystem-API`, not this repo. It does not serve this code.

### Known risks (accepted by user)

- `POST /saveTicketIT`, `POST /saveTicketEngineering` and `GET /getTicketRequests` stay public and unchanged. `getTicketRequests?requestorId=` lets anyone read any requestor's tickets (IDOR). The ticket-number lock and the unique index still apply to these routes.

### API contract (Phase 1)

All routes are prefixed with `/api`. Protected routes require the `Authorization: Bearer <token>` header.

**Envelope**
- Success: `{ success: true, message: string, result: {...} }`. Reads return 200 and creates return 201.
- Error: `{ success: false, message: string, errors?: { field: string[] } }`.
  - 401: unauthenticated or expired token. The frontend should clear the token and redirect to login.
  - 403: forbidden (policy or gate).
  - 404: not found.
  - 409: invalid state, such as assigning or completing a completed ticket.
  - 422: validation, with `errors`.
  - 429: throttled.

**Auth**

| Method | Path | Auth | Params | Response `result` |
|---|---|---|---|---|
| POST | /login | public, throttled to 5/min per email+IP | `email`, `password` | `{ user: User, token: string, expires_at: ISO8601 }` |
| POST | /logout | bearer | none | `{}` (revokes the current token) |
| GET | /me | bearer | none | `{ user: User }` |
| POST | /admin/users | admin | `first_name`, `last_name`, `email` (unique), `password` (min 8), `department_id`, `designation_id` (in that department), `role_id` | 201 `{ user: User }` |
| ~~POST~~ | ~~/register~~ | none | none | **removed** |

`User` shape, used by `/me`, `/login` and `/admin/users`:
```
{ id: number, first_name, last_name, email, status: boolean,
  department_id: number, designation_id: number,
  role: { id: number, name: string },          // name is "Normal User" | "Department Head"
  department: { id, name }, designation: { id, name } }
```
The frontend checks admin rights with `user.role.name === "Department Head"`.

**Lookups**

| Method | Path | Auth | Params | Response `result` |
|---|---|---|---|---|
| GET | /designations | public | `department_id` (required, exists) | `{ designations: [{ id, department_id, designation, status: boolean }] }` (200, was 201) |
| POST | /designations | admin | `department_id`, `designation` | 201 `{ designation }` |
| GET | /priority | bearer | `department_id` (optional, defaults to the caller's department) | `{ priorities: [{ id, level, sla_days: number, department_id: number }] }` (was a bare array) |
| GET | /lookups/concern-types | bearer | none | `{ concern_types: [{ id, label }] }` (active only) |
| GET | /lookups/nature-of-work | bearer | none | `{ nature_of_work: [{ id, label }] }` (active only) |
| GET | /employees | bearer | `department_id` (optional, defaults to the caller's department) | `{ users: [...] }` (same row shape as before, now 200 instead of 201). Users without a designation are included, and email is removed. |

**Tickets**

| Method | Path | Auth | Params | Notes |
|---|---|---|---|---|
| POST | /saveRequestTicketIT | bearer | as before, plus `exists:` rules on priority, service_department and assigned_user. `devices` is optional for non-Hardware concerns. | 201. Validation errors return 422. |
| POST | /tickets/{ticket}/assign | bearer, member of the ticket's handling department | `user_id` (exists, same department as the ticket; may be the caller, for "take ticket") | 200 `{ ticket }`. Completed tickets return 409. |
| POST | /tickets/{ticket}/complete | bearer, the assignee or a member of the handling department | none | 200 `{ ticket }`. Unassigned or already-completed tickets return 409. |
| GET | /tickets/{ticket}/comments | bearer, member of the handling department or the requester | none | 200 (was 201) |
| POST | /tickets/{ticket}/comments | same as GET | `comment` (max 2000) | 201 `{ comment }` |
| PATCH | /tickets/comments/{comment} | bearer, author only | `comment` | 200 `{ comment }`. A non-author gets 403 (was 200). |
| GET | /dashboard | bearer | `date` (`Y-m`, validated; a bad value returns 422), `priority` (optional, now applied as a filter) | shape unchanged |
| GET | /overdueTickets | bearer | none | read-only now, `{ ticket: number }` count unchanged |
| GET | /reports/accomplishment | bearer | `date_from`, `date_to` (on or after date_from), `user_id` (admin only, defaults to self) | `.docx`. `Content-Disposition` is now readable. Another user's id returns 403 for non-admins. |

**Shape changes**
- The comment `user` is always `{ id, first_name, last_name }` on index, create and update.
- Rows from `getDepartmentTickets` and `getMyTickets` gain `department_id` (number, the handling department). The frontend shows "Take ticket" when `status === "Open"` and `user.department_id === row.department_id`.
- Casts: `status` fields are booleans, ids are numbers, and `priority.sla_days` is an int.
- The overdue status is set by the scheduled command `tickets:promote-overdue` (every 5 minutes), no longer per request.

## 2026-10-20 — Baseline health scan

Read-only audit by the API team (be-apicoder, be-coder, be-coder2). No code or database changes were made.

### Versions

- PHP 8.5.5 (AGENTS.md documents 8.3), Laravel 13.26.1, Sanctum 4, PHPUnit 12
- MySQL 127.0.0.1:3309 / `ticketing_api` (dev); tests run on sqlite `:memory:` (phpunit.xml)
- Git: branch `main`, single commit `d3c3392 first commit`

### Test and tooling status

- `php artisan test`: **1 of 2 failing**. `ExampleTest` gets a 500 (`no such table: tickets`) because the global `CheckOverdueTickets` middleware runs a MySQL-only UPDATE on every request.
- Only the two example tests exist. No test uses `RefreshDatabase`.
- Pint: 41 files are not compliant (style only).
- `composer validate`: valid.

### Findings

#### Critical

- **Self-registration as any role.** `POST /register` is public and accepts `role_id`, `department_id` and `designation_id` from the client, then returns a token (`routes/api.php:12`, `AuthController.php:27`, `AuthService.php:15-19`). Anyone can register as Department Head of any department.
- **Public ticket routes and IDOR.** `/saveTicketIT`, `/saveTicketEngineering` and `/getTicketRequests` have no auth and no throttle (`routes/api.php:21-25`). `getTicketRequests` filters on a client-supplied `requestorId` (`TicketingController.php:521`), so anyone can read any requestor's tickets.
- **MySQL-only SQL breaks sqlite tests and portability:**
  - `CheckOverdueTickets.php:22-29` (DATEDIFF/NOW, UPDATE…JOIN)
  - `TicketingController.php:253-268` (same logic)
  - `TicketNumberGenerator.php:29,38,51` (GET_LOCK, SUBSTRING_INDEX, RELEASE_LOCK). Every ticket-create endpoint depends on this.
- **Ticket create returns 500 without devices.** `Device::insert(array_map(..., null))` fails when `devices` is omitted for non-Hardware concerns (`TicketingController.php:75-83,153-161`). Multi-table writes are not wrapped in a transaction.

#### High

- **No authorization anywhere.** There are no Policies, Gates or role checks. Any authenticated user can assign or complete any ticket and comment on any ticket (`TicketingController.php:579,603`, `CommentController.php:12,24`).
- **Completing a ticket has no state guard** (`TicketingController.php:603-631`):
  - On an unassigned ticket it dereferences null and returns a 500 (`:615`).
  - Completing an already-completed ticket creates a duplicate activity, which inflates `top_performers`.
- **Assigning a ticket has no state guard** (`TicketingController.php:579-600`):
  - A completed ticket can be re-opened to In-progress, and each reassignment adds another Assigned activity.
  - `tickets.assigned_user` is never updated, so the in-progress count in `top_performers` stays at 0.
  - The assignee's department is not checked.
- **Accomplishment report:**
  - Any user can export any other user's report through `user_id` (IDOR, `AccomplishmentReportController:22`).
  - The date filter uses `tickets.created_at` instead of `comments.created_at`, so comments are dropped or duplicated (`:40-57`).
- **Filename case mismatch.** `Accomplishmentreportcontroller.php` contains `AccomplishmentReportController`, so PSR-4 autoload fails on Linux.
- **Duplicate ticket numbers under concurrency.** `TicketNumberGenerator` releases `GET_LOCK` before the caller inserts the ticket (`TicketingController.php:56-61`), and `tickets.ticket_number` has no unique index, so two concurrent requests can receive the same number.
- **Public `POST /designations`** lets anyone write to the database (`routes/api.php:18`, `UserController.php:39-75`).
- **Overdue middleware runs on every request.** It is registered globally (`bootstrap/app.php:16-17`), so it writes on every request, including `/up` and OPTIONS preflights. It hardcodes status ids 1, 2 and 4, uses DB-server time instead of the app timezone (Asia/Manila), and compares against `priority.SLA`, which is a string column.
- **Dashboard date handling.** `Carbon::createFromFormat('Y-m')` returns a 500 on bad input and rolls over the month on the 29th–31st (`TicketingController.php:636-659`).
- **Login and tokens:**
  - No rate limiting on `/login` or `/register`.
  - Tokens never expire (`sanctum.php:53`).
  - There is no logout or token-revocation route.
- **Seeding is broken.** `Device` fillable lacks `qty` (a NOT NULL column), so the `TicketFactory` and `DatabaseSeeder` ticket seeding fails under MySQL strict mode.

#### Medium

- **Error status codes.** Validation and authorization errors return HTTP 200 with `success:false` (`AuthController.php:36,56`, `TicketingController.php:51,126,207`, `UserController.php:61`, `CommentController.php:57-61`). GET endpoints return 201.
- **CORS** allows origin `*` together with `supports_credentials=true`, which browsers reject (`cors.php:22,32`). The app uses bearer tokens only.
- **Auth plumbing.** `AuthService::login` uses the session guard through `Auth::attempt`. The `hashed` cast is disabled and passwords are hashed manually (`User.php:34`).
- **Schema gaps:**
  - Missing FKs or indexes: `ticket_date_activities.user_id`, `comments.user_id`, `tickets.department`, `priority.department_id` (string), `ticket_date_activities.requestorId`.
  - `tickets.ticket_number` is not unique.
  - No cascade rules.
  - Redundant `users.role_id` alongside the unused `role_user` table.
- **Missing migrations.** There are no `sessions`, `cache`, `jobs` or `password_reset_tokens` tables, although `.env.example` uses the database drivers for them.
- **Models.** Most relationships and all casts are missing. `Ticket` has both `status` and `status_id`. Activity relations match on category name through `whereHas`, which is slow and prone to N+1 queries.
- **N+1 queries** in the dashboard's `priorityStats` and `topPerformers` (`TicketingController.php:668-719`).
- **GROUP BY.** `groupBy('tickets_it.id')` selects non-aggregated columns (`:405,417,552,567`). Postgres would reject it.
- **Side effects and response shapes:**
  - `GET /overdueTickets` updates data.
  - `/priority` returns a bare array.
  - `/employees` exposes email and drops users with no designation.
  - The comment create and update responses return the `user` in different shapes.
- **No Form Requests, API Resources or versioning.** `TicketingController` is 748 lines. `ApiResponseTrait` exists but is unused.

#### Low

- `whereRaw` with an interpolated `$userId` (`TicketingController.php:450`). It is not exploitable today, but it should be bound.
- Magic ids for status, date category and department (`?? 1` fallback at `:638`).
- The report dereferences `$preparedByUser` without a null check (`AccomplishmentReportController:26-35,245`). Every user has a designation, so this only fails if the user row is missing.
- Each login issues a new token without revoking old ones. A `GET_LOCK` timeout returns a 500 instead of a clean error.
- Hardcoded approver name in the report. The temporary file from `tempnam` is never deleted.
- Factories:
  - Only User and Ticket exist.
  - `TicketFactory` depends on seeded lookup data.
  - `UserFactory` can pick a designation from a different department.
- `DatabaseSeeder` seeds fixed credentials.
- `.env.example` sets `APP_DEBUG=true`.
- Dead code and typos; controller methods lack return types.

### Frontend/API mismatches

- `createTicket` (`lib/tickets.ts:80`) doesn't check `success`, and validation failures come back as 200. Errors therefore fail silently, and a non-Hardware ticket sent without devices gets a 500.
- Comment edit by a non-author returns 200 with no `result`, so the frontend reads `result.comment` as undefined.
- The dashboard sends `priority`, but the API ignores it (`TicketingController.php:641`).
- The frontend has no global 401 handler and no logout endpoint to call.
- The report filename is built on the client. CORS `exposed_headers` is empty, so the frontend can't read `Content-Disposition`.
- These routes exist but the frontend doesn't use them: `/priority`, `/overdueTickets`, `/saveTicketIT`, `/saveTicketEngineering`, `/getTicketRequests` (likely a separate requestor portal).
- Date-activity `user` serializes as a string through `TicketDateActivity::toArray()`, which matches the frontend. It can be null, but the frontend type `ActivityInfo.user` is non-null.
- `User.status` arrives as `1`/`0` (no casts), but the frontend types it as boolean. `User.designation_id` arrives as a number, but the frontend types it as a string.
- The comment update response (`CommentController.php:69`, `load('user')`) exposes the commenter's email, `department_id`, `role_id` and `status`. Passwords are hidden in every payload.

### Planned

Pending user decision. A phased plan has been proposed to master-orchestrator: Phase 1 stabilize, Phase 2 features.
