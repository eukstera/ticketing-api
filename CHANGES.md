# Changes

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
  - A user without a designation causes a null dereference and a 500 (`:26-35,245`).
  - The date filter uses `tickets.created_at` instead of `comments.created_at`, so comments are dropped or duplicated (`:40-57`).
- **Filename case mismatch.** `Accomplishmentreportcontroller.php` contains `AccomplishmentReportController`, so PSR-4 autoload fails on Linux.
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
- Still being checked: whether `user` on date activities serializes as a string or an object, and whether the `status` fields on users and designations exist.

### Planned

Pending user decision. A phased plan has been proposed to master-orchestrator: Phase 1 stabilize, Phase 2 features.
