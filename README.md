# Court Hub API

Booking checkout now uses real PayMongo hosted sessions, signed webhooks, and provider reconciliation. Read [payment setup and acceptance](../docs/PAYMENTS.md) before enabling it. The payment-intent endpoint now accepts booking checkout only; Xendit and membership checkout are unavailable, and the previous generic webhook payload is no longer accepted. Merchant credentials, HTTPS callbacks, workers, and sandbox acceptance are required. Older feature inventory below describes the initial baseline; the payment guide takes precedence for the current payment contract.

Laravel 13 API foundation for the multi-tenant Court Hub platform.

## First slice

- Versioned JSON API at `/api/v1`
- Sanctum bearer-token registration, login, and logout
- Spatie role-based access control
- Tenant, organization, facility, branch, and court schema
- Tenant-scoped organization, facility, branch, and court management
- Court availability, 10-minute reservation holds, confirmation, and cancellation
- Tenant-scoped profile and staff administration, role visibility/assignment, audit logs, and avatar uploads
- Email verification, password recovery, token/session management, and authenticator-app two-factor authentication
- Court types, branch hours/holidays, court maintenance, and time-based pricing rules
- Booking history, walk-in and QR bookings, waitlists, rescheduling, refund requests, recurring reservation schedules, QR validation/check-in, and automatic expiry of unpaid holds
- Membership plans, member cards, session packages, freeze/resume, renewal and expiry, plus payment intents, webhooks, refunds, invoices, and reconciliation summaries
- Coach profiles, weekly availability, coaching sessions and revenue; tournament registration and match scheduling; and rental inventory, extensions, damage fees, returns, and overdue monitoring
- Date-ranged revenue, occupancy, peak-hour, court-utilization, membership, coach, tournament, rental, and refund reporting

## Local setup

Configure MySQL and Redis in `.env`, then run:

```powershell
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

Check `GET /api/v1/health`, then register a tenant owner with `POST /api/v1/auth/register`.

`POST /api/v1/auth/register` accepts `tenant_name`, `name`, `email`, `password`, `password_confirmation`, and optional `device_name`. It creates the tenant and assigns the `court-owner` role atomically.

Import `../Court-Hub-API.postman_collection.json` into Postman or APIDog to test every implemented API route. The collection saves the token and resource IDs from the setup flow; run its final “Implemented route coverage” folder after the setup and operations workflows because it finishes with destructive cleanup requests.

> The scaffold defaults to SQLite, but this Windows PHP installation has no PDO SQLite driver. Use the MySQL configuration from the project specification for migrations.

## Restarting the API

From the `backend` directory, stop any existing Laravel server with `Ctrl+C`, then start it again:

```powershell
php artisan serve
```

The default local address is `http://127.0.0.1:8000`. Confirm the service is running with `GET /api/v1/health`.

If configuration, routes, or cached services changed, clear local caches before restarting:

```powershell
php artisan optimize:clear
php artisan serve
```

## Applying database migrations

After pulling backend changes or adding a migration, apply outstanding migrations and seed the default roles:

```powershell
php artisan migrate --seed
php artisan storage:link
```

To inspect migration status without changing the database:

```powershell
php artisan migrate:status
```

For a disposable local development database only, rebuild the schema and seed data from scratch. This deletes all local records:

```powershell
php artisan migrate:fresh --seed
```

Never run `migrate:fresh` against staging or production. Use `php artisan migrate --force` only as part of an approved deployment process.

## Running tests

Run all unit, feature, and integration tests from the `backend` directory:

```powershell
php artisan test
```

Run an individual suite or file when iterating:

```powershell
php artisan test --testsuite=Unit
php artisan test --testsuite=Integration
php artisan test tests/Feature/Api/HealthEndpointTest.php
```

The integration route-inventory test reaches every registered `/api/v1` route and verifies that it is either guarded by Sanctum or validates the unauthenticated request rather than returning a missing-route response. Database workflow tests cover successful tenant, facility, booking, membership, payment-adjacent, and notification flows. The local test configuration uses in-memory SQLite. If PHP does not include the PDO SQLite driver, run the suite with a PHP build that includes `pdo_sqlite`, or point the testing environment at a disposable MySQL database; never run tests against production data.

## Authentication setup

Email verification and password-reset endpoints use Laravel's configured mailer. The local default is the `log` mailer, so messages are written to `storage/logs/laravel.log`. For real delivery, configure `APP_URL`, `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and `MAIL_FROM_ADDRESS` in `.env` (Resend is the recommended provider in the project specification).

The Postman collection includes dependent authentication requests for password reset, email verification, session revocation, and two-factor authentication. Populate the `reset_token`, `verification_hash`, `session_token_id`, and `two_factor_code` collection variables from the email, second device session, or authenticator app before sending those requests.

Two-factor authentication uses standard TOTP. Scan the `provisioning_uri` returned by `POST /api/v1/auth/two-factor/setup` with an authenticator app, then submit its six-digit code to confirm or disable 2FA.

## Booking lifecycle and expiry

The collection's **Booking lifecycle** and **QR check-in** folders cover booking history, walk-in and QR-originated reservations, waitlists, recurring reservation schedules, rescheduling, refunds, QR codes, and QR-based check-in. QR check-in accepts a confirmed booking's `qr_code` and is idempotent: scanning the same code again returns the original check-in. Walk-in, refund, QR-validation, and QR check-in endpoints require a `court-owner`, `facility-manager`, or `front-desk` token.

Reserved bookings expire automatically ten minutes after creation unless confirmed. Start Laravel's scheduler alongside the API during local development:

```powershell
php artisan schedule:work
```

For production, configure one system cron entry to run `php artisan schedule:run` every minute. The scheduled `bookings:expire` command then marks expired unpaid reservation holds as `expired`.

## Memberships and payments

Membership plans support standard, family, corporate, and session-package types. A session package can include `session_count`; front-desk staff consume sessions through the membership session-use endpoint. The scheduler runs `memberships:expire` daily to expire memberships after their end date. Auto-renew is stored as a member preference; charge collection and renewal should be triggered only after a successful provider payment webhook.

Use `POST /api/v1/payments/intents` to create a pending GCash, Maya, or card payment with the PayMongo or Xendit provider identifier. Configure `PAYMONGO_WEBHOOK_SECRET` and/or `XENDIT_WEBHOOK_SECRET` in `.env` (mapped in `config/services.php`) before accepting real provider webhooks. The webhook endpoints are idempotent by provider event ID; their payload requires `event_id` and may include `data.provider_reference` and `data.status` (`paid` or `failed`).

> **Security notice:** payment-provider support is a development integration scaffold. Before production use, enforce a configured secret for every webhook request, validate each provider's documented signature over the raw request body, derive amounts from server-side records, and add authorization policies for resource ownership. Do not expose the payment webhook endpoint to a real provider until these controls are implemented.

## Coaching, tournaments, and rentals

The Postman collection includes a dependent workflow that creates a coach, configures weekly availability, completes and cancels coaching sessions, records coach revenue, schedules a tournament match, and exercises the rental lifecycle. Rental close requests accept an optional `damage_fee` and return the equipment to available inventory. Use the `rentals/overdue` endpoint for front-desk follow-up.

## Reporting

Owner and facility-manager tokens can access the `/api/v1/reports/*` endpoints. Each detailed report accepts optional `from` and `to` date query parameters; when omitted, it reports from the start of the current month through today. Tournament revenue is estimated from registered entries and entry fees; other revenue reports use recorded API data.

## Background processing, Redis, notifications, and OpenAPI

Use the database queue locally with `php artisan queue:work`, or set `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` after Redis is running for production-like queues and cache storage. The dashboard response is cached per tenant for five minutes. Run `php artisan schedule:work` locally; it expires booking holds and memberships, and prunes read notifications daily.

The notification endpoints provide in-app inbox, read-state, preferences, and a queued test notification. A queue worker must be running to process `POST /api/v1/notifications/test` when the queue connection is not `sync`.

OpenAPI JSON is available at `GET /api/v1/openapi.json`. Payment intents support PayMongo and Xendit identifiers; set each provider's secret key and webhook secret variables from `.env.example` before production use. Webhooks remain idempotent by provider event ID.
