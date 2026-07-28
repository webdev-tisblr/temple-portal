# CLAUDE.md — Temple Portal (Laravel)

Guidance for working in this repo. Companion app lives in `../temple_app` (Flutter).

## What this is

Laravel 11 monorepo serving three surfaces for the Shree Patadiya Hanumanji Seva Trust:

- **Public website** — `routes/web.php` + Blade in `resources/views/` (home, donate, seva, events, gallery, blog, store, halls, campaigns, contact, devotee OTP login, `/dashboard/*`).
- **Admin** — Filament at `/admin` (`app/Filament/Resources/`). Devotees, Sevas, Bookings, Donations, Orders, Halls, Events, CMS, Notifications, Settings, etc.
- **API** — `routes/api.php` (`/api/v1/*`, Sanctum) for the Flutter app: auth/otp, /me, sevas+book, donations+receipt, campaigns, content, store, halls, device-tokens, payments/verify, webhooks.

## Non-obvious facts (read before changing related code)

- **MySQL only.** Tables are prefixed `temple_*`. No SQLite. Local dev uses XAMPP MySQL. CI/tests use a `temple_portal_test` MySQL DB (see phpunit.xml).
- **Razorpay live keys live in the DB**, not `.env` — they're a `temple_system_settings` row, admin-editable (`SystemSetting::getValue('razorpay_key_id' / 'razorpay_key_secret')`, falling back to `config/razorpay.php`). The `.env` has placeholder strings on purpose. The **webhook secret** is the one real `.env`/settings value used to verify inbound webhooks.
- **`temple_devotees.id` is a UUID** (`char(36)`). New tables referencing devotees must use `foreignUuid()`, and `devotee_id` columns/params are `string`, not `int`. Several models use the `HasUuid` trait.
- **Images & PDFs live on Cloudflare R2**, not the server. Use the `image_url()` helper (idempotent — passes through absolute URLs). Public bucket → `cdn.patadiyahanumanji.com`; private bucket (`r2_private`) holds regenerable receipts/invoices/cards treated as a short-lived cache (cleanup crons in `routes/console.php`; download controllers regenerate-on-miss). **Admin uploads + user uploads on the public bucket are NOT regenerable** — they must be retained/backed up.
- **`PaymentCaptureService` is the single source of truth** for "payment captured" side effects. All five entry points (Razorpay webhook, `/api/v1/payments/verify`, and the four web success callbacks) funnel through `markCaptured()`, which is transactional, row-locked, and idempotent. Never confirm a booking/order/donation outside it.
- **Notifications**: nothing sends unless an admin has created + enabled a `NotificationTemplate` for that trigger×channel. Dispatch via `NotificationService::dispatch($key, $context, idempotencyKey: ...)` — never call a driver directly. Failed/stalled sends are retried by `notifications:reap` (cron). Seva reminders are pre-computed into `temple_seva_reminder_schedules` on booking confirmation (`SevaReminderScheduler` + `SevaBookingObserver`) and drained by `seva:dispatch-reminders`.
- **Queue is Redis with 2 always-on Supervisor workers** (VPS since 2026-07-25; `--queue=otp,default,broadcast`, OTP has priority). Notification dispatches go through a **transactional outbox** (`temple_notification_outbox` + `notifications:relay-outbox`) when `NOTIFICATIONS_VIA_QUEUE=true` (prod). Contexts carrying `_attachments` stay inline by design. Deploys run `php artisan queue:restart` so workers pick up new code. Guest pages are cached twice: `CacheGuestResponse` middleware (per-locale, 120s) + a Cloudflare edge Cache Rule (cookie-aware). Do not add forms/CSRF surfaces to the cached-path whitelist without removing those paths from both layers.
- **Three auth guards**: `web` (legacy User), `admin` (AdminUser + Spatie/Filament Shield roles), `devotee` (OTP-only). Sanctum tokens for the app (90-day expiry).

## Deploy

GitHub Actions (`.github/workflows/deploy.yml`) on push to `main` → **the VPS** (`deploy@187.127.132.164`, `/var/www/temple-portal/`; server access: `ssh temple-vps`). Old shared hosting is decommissioned (frozen in maintenance; its `artisan` renamed).
- A `test` job (Pint + PHPUnit against a MySQL service) runs first; `deploy` `needs: test`.
  - ⚠️ Pint + PHPUnit steps are currently `continue-on-error` (reporting only) — the codebase predates Pint and the suite is new. To arm the gate: run `vendor/bin/pint` once + commit, confirm tests green in CI, then drop the two `continue-on-error: true` lines.
- Deploy takes a pre-migration DB snapshot (`backup:run --only-db`) BEFORE `migrate --force`, then runs idempotent seeders + the reminder backfill, then clears/rebuilds caches.
- `rsync --delete` EXCLUDES `storage/app/public|private/` so admin uploads/generated files aren't wiped. Don't remove those excludes.
- This project is historically **tested directly in production** (no staging). Be conservative; prefer additive changes; smoke-test money paths (payments, OTP, receipts) after deploy.

## Where things live

- Models `app/Models/` · Services `app/Services/` (PaymentCapture, Razorpay, Receipt/Invoice/GreetingCard, Notifications + drivers, Otp, SevaSlot, SevaReminderScheduler) · Web controllers `app/Http/Controllers/Web/` · API `app/Http/Controllers/Api/V1/` · Filament `app/Filament/` · Migrations `database/migrations/` (all `temple_*`) · Scheduled tasks `routes/console.php`.

## ⚠️ Manual operator steps still required (not doable in code)

These were set up in code but need credentials/infra you must provide:

1. ~~Offsite backup bucket~~ **DONE 2026-07-26** — `temple-backups` R2 bucket live; nightly 02:00 `backup:run`, 02:30 `backup:clean`, 10:00 `backup:monitor`. Creds in prod `.env` (copy in `/root/.temple-backup-creds` on the VPS).
2. **Error tracking (Sentry)** — package installed + wired (2026-07-26); INERT until `SENTRY_LARAVEL_DSN` is set in prod `.env`. User: create sentry.io project, paste DSN.
3. **Uptime monitor** — point UptimeRobot at `https://patadiyahanumanji.com/up/deep` (checks MySQL+Redis, never edge-cached). Do NOT monitor `/` (Cloudflare serves stale cache on origin 5xx — hid the 2026-07-28 outage) and not plain `/up` (doesn't touch the DB).
4. **CI test DB** — the CI MySQL service uses `temple_portal_test`. Locally: `mysql -uroot -e "CREATE DATABASE temple_portal_test"` to run `php artisan test`.
5. **Arm the CI gate** — see Deploy section (run Pint once, confirm tests green, remove `continue-on-error`).
6. **Rotate the Firebase service-account key** — it was briefly exposed during an early upload. Firebase Console → Service Accounts → generate new key → replace `FIREBASE_CREDENTIALS` file → delete the old key.
7. **App force-update values** — set `app_min_version` / `app_latest_version` / store URLs in System Settings to drive the `GET /api/v1/app-config` gate the Flutter app reads.
8. **Real mailer in production** — confirm prod `.env` `MAIL_MAILER` is a real SMTP transport (not `log`), or 80G receipts and OTP emails are silently dropped.
</content>
