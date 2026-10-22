<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Deployment on Windows

The API needs a scheduler for `tickets:promote-overdue` (it marks tickets Overdue every 5 minutes). On Windows the scheduler runs as a service managed by [NSSM](https://nssm.cc).

### Prerequisites

- PHP 8.3+ with the extensions Laravel needs (`mbstring`, `openssl`, `pdo_mysql`, `fileinfo`, `zip`), and Composer.
- MySQL 8 reachable from the server.
- A web server (nginx or IIS) pointing at `public/`.
- `nssm.exe`, on `PATH` or at a known location.

### Configuration

Copy `.env.example` to `.env`, run `php artisan key:generate`, and set at least:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.example.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ticketing_api
DB_USERNAME=...
DB_PASSWORD=...

# Comma-separated list of frontend origins allowed by CORS
CORS_ALLOWED_ORIGINS=https://tickets.example.com
```

### Deploy steps

```powershell
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan db:seed --class=ReferenceDataSeeder --force
php artisan config:cache
php artisan route:cache
php artisan event:cache
```

The service account that runs PHP and the scheduler needs read/write access to `storage\` and `bootstrap\cache\`.

### Scheduler service

From an elevated PowerShell, in the application folder:

```powershell
.\deploy\windows\install-scheduler-service.ps1 `
    -PhpPath "C:\php\php.exe" `
    -AppPath "C:\nginx\www\Ticketing System\TicketingSystem-API" `
    -NssmPath "C:\tools\nssm\nssm.exe" `
    -StartService
```

`-ServiceName` defaults to `TicketingScheduler` and `-NssmPath` to `nssm`. Quote paths that contain spaces. The script runs `php artisan schedule:work` as an auto-start service, rotates its logs at about 10 MB and restarts it 5 seconds after an exit. Running it again updates an existing service instead of reinstalling it.

Verify it:

```powershell
php artisan schedule:list
Get-Content "storage\logs\scheduler-out.log" -Tail 20
Get-Content "storage\logs\scheduler-err.log" -Tail 20
```

To remove the service, run `.\deploy\windows\uninstall-scheduler-service.ps1` (it does nothing if the service is not installed).

### After each deploy

Restart the scheduler so it loads the new code:

```powershell
nssm restart TicketingScheduler
# or ask the running scheduler to exit and let NSSM restart it:
php artisan schedule:interrupt
```

### Linux alternative

Use the standard cron entry instead of the service:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

## Completion confirmation and email

A technician completes a ticket with notes; the ticket becomes **Pending Confirmation** and the requester is asked to confirm or dispute it (emailed link, or Track Ticket) and then fills in the Customer Satisfaction Survey. A Department Head can close a waiting ticket on the requester's behalf. Tickets nobody answers close automatically after `TICKET_AUTOCLOSE_DAYS` calendar days (default 30; `tickets:auto-close-confirmations` runs daily at 01:00 Asia/Manila through the scheduler).

### Settings (`.env` on the server)

Fill these in on the server; never commit real values.

| Variable | Purpose |
|---|---|
| `MAIL_MAILER` | `smtp` to send real mail (`log` only writes mails to `storage/logs`) |
| `MAIL_HOST`, `MAIL_PORT` | SMTP server and port (587 for STARTTLS) |
| `MAIL_SCHEME` | `smtp` for STARTTLS on 587, `smtps` for implicit TLS on 465 |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | SMTP login (for Gmail use an app password) |
| `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | Sender shown to requesters |
| `REQUESTOR_PORTAL_URL` | Base URL of the requestor portal, no trailing path; the link is `{REQUESTOR_PORTAL_URL}/confirm/{token}` |
| `TICKET_AUTOCLOSE_DAYS` | Days before an unanswered completion closes by itself (default 30) |

The server must be able to reach the SMTP host. Run `php artisan migrate --force` and `php artisan db:seed --class=ReferenceDataSeeder --force` after deploying (adds the status and the timeline categories).

### How mail is sent without a queue worker

No queue worker runs today and none is needed. Each email is a `SendTicketMail` job dispatched with `dispatchAfterResponse()`: it runs in the same process right after the HTTP response, records the result in `ticket_mail_log` (pending, sent, failed or gave_up, with attempts and the last error) and never blocks the request. Failed sends are retried by the scheduler (`tickets:retry-mail`, every 5 minutes, waiting 5, 15, 30 and 60 minutes, at most 5 attempts). A retry issues a fresh link, because only a hash of each token is stored. Staff can also resend from the ticket (`POST /api/tickets/{ticket}/confirmation/resend`), and the Track Ticket page keeps working if email fails.

The job class implements `ShouldQueue` and the `jobs` and `failed_jobs` tables exist, so a real worker can be switched on later without code changes: keep `QUEUE_CONNECTION=database`, dispatch normally and run `php artisan queue:work` as a service. Note that with a worker the link token is part of the queued payload.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
