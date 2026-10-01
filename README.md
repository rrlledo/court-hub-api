# Court Hub API

Booking and membership checkout use real PayMongo hosted sessions, signed webhooks, and provider reconciliation. Read [payment setup and acceptance](../docs/PAYMENTS.md) before enabling it. The payment-intent endpoint accepts exactly one booking or membership. A local/testing-only Xendit mock supports development without a merchant account; it never contacts Xendit or processes a real charge or refund. Merchant credentials, HTTPS callbacks, workers, and sandbox acceptance are required for PayMongo. Older feature inventory below describes the initial baseline; the payment guide takes precedence for the current payment contract.

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

## Demo data for every workflow

For a local database only, create the idempotent Court Hub demo tenant, all seven role accounts, facility hierarchy, bookings, check-ins, memberships, mock payments/refunds, rentals, coaching, tournament registrations/matches, notifications, and reporting records:

```powershell
php artisan court-hub:demo-data
```

From the repository root, Windows users can run the same command through the included script:

```powershell
.\backend\scripts\seed-demo-data.ps1
```

It is safe to rerun and does not contact payment, Firebase, or other third-party services. Every demo account uses the password `DemoPass123!`; use the `demo.*@court-hub.test` addresses shown by the command data, such as `demo.owner@court-hub.test`, `demo.desk@court-hub.test`, and `demo.organizer@court-hub.test`. Do not run it against a shared production database.

`POST /api/v1/auth/register` accepts `tenant_name`, `name`, `email`, `password`, `password_confirmation`, and optional `device_name`. It creates the tenant and assigns the `court-owner` role atomically.

Import `../Court-Hub-API.postman_collection.json` into Postman or APIDog to test every implemented API route. The collection saves the token and resource IDs from the setup flow. Run `php artisan court-hub:demo-data` before its **Advanced non-production workflows** folder: it signs in as seeded demo users and creates only simulated social-login, payment-settlement, coaching, tournament, and platform-invoice records. Run its final “Implemented route coverage” folder after the setup and operations workflows because it finishes with destructive cleanup requests.

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

This includes the `push_devices` migration used for Firebase Cloud Messaging registrations. In staging and production, run migrations as part of the deployment before starting workers:

```powershell
php artisan migrate --force
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

Use `POST /api/v1/payments/intents` to create a pending GCash, Maya, or card checkout with the `paymongo` provider for exactly one booking or membership. Configure `PAYMONGO_SECRET_KEY`, `PAYMONGO_WEBHOOK_SECRET`, and an HTTPS `PAYMONGO_RETURN_URL` before accepting real checkouts. Signed PayMongo webhook events are idempotent, and the worker independently retrieves the provider checkout before settlement. Set `XENDIT_MOCK_ENABLED=true` only in `local` or `testing` to exercise the documented Xendit test double; it is disabled in every other environment.

> **Production notice:** PayMongo checkout verifies the configured webhook secret against the raw request body and derives amounts from booking and membership records. Before a public launch, complete sandbox acceptance, configure real credentials and supervised workers, and extend resource-ownership policy coverage and negative-path authorization tests across all player-accessible resources.

## Simulated advanced workflows

No third-party subscription is required for the following development workflows. `POST /api/v1/auth/social/google`, `/apple`, and `/facebook` accepts a local `mock_subject`; it never contacts an identity provider. New mock-social player accounts must select a facility with registration enabled. The demo seeder creates a Google-linked player account.

`GET /api/v1/payments/settlements` produces a settlement feed from stored payments, including whether payment provider data was simulated. Coach rosters use `/coaches/{coach}/students` and store each student's revenue-share percentage. Tournament teams and check-in are available at `/tournaments/{tournament}/teams` and `/tournaments/{tournament}/registrations/{registration}/check-in` for authorized staff and organizers.

Super Admins can create and settle simulated platform invoices at `/super-admin/tenants/{tenant}/subscription-invoices`. Invoices, tax amounts, due dates, paid state, and overdue/dunning state are local database records only. They do not charge customers, submit taxes, or send collection notices.

## Coaching, tournaments, and rentals

The Postman collection includes a dependent workflow that creates a coach, configures weekly availability, completes and cancels coaching sessions, records coach revenue, schedules a tournament match, and exercises the rental lifecycle. Rental close requests accept an optional `damage_fee` and return the equipment to available inventory. Use the `rentals/overdue` endpoint for front-desk follow-up.

Rental visibility is ownership-safe: a player can list and view only rentals assigned to that player, while court owners, facility managers, and front-desk users can view the tenant's rental inventory. Rental issue, return, extension, and close actions remain staff-only.

## Reporting

Owner and facility-manager tokens can access the `/api/v1/reports/*` endpoints. Each detailed report accepts optional `from` and `to` date query parameters; when omitted, it reports from the start of the current month through today. Tournament revenue is estimated from registered entries and entry fees; other revenue reports use recorded API data.

## Super Admin workspace

Users with the `super-admin` role can use `/api/v1/super-admin/*` to review platform-wide totals, list and inspect tenants, edit tenant settings, suspend or reactivate a tenant, and revoke a tenant user's sessions. Tenant suspension is enforced for non-Super-Admin users at login and on authenticated API requests.

The tenant `subscription_*` fields, platform MRR, and invoice history are a **simulated platform billing ledger** for the current release. Super Admins may manage the `starter`, `growth`, and `enterprise` plan state and create/settle mock invoices, but no provider is charged. Replace this with an audited provider integration, webhook verification, tax handling, and real dunning before selling platform subscriptions. Apply the `add_is_active_to_tenants_table`, `add_platform_subscription_to_tenants_table`, and `add_non_production_advanced_workflows` migrations before using these controls.

## Push notifications

Push delivery uses Firebase Cloud Messaging (FCM). Set these environment variables in `.env` or your deployment secret store:

```dotenv
FIREBASE_PROJECT_ID=your-firebase-project-id
FIREBASE_SERVICE_ACCOUNT_PATH=C:\secure\court-hub-firebase-service-account.json
```

`FIREBASE_SERVICE_ACCOUNT_PATH` must point to a readable Firebase service-account JSON file with permission to send FCM messages. Keep that file outside the repository and out of the web root. `FIREBASE_PROJECT_ID` can be omitted when the service-account JSON includes its `project_id`, though setting it explicitly is recommended. After changing either value, run `php artisan optimize:clear` and restart the queue workers so they load the new configuration.

The mobile app also needs its Firebase-generated `google-services.json` and `GoogleService-Info.plist`; see [`../docs/PUSH_NOTIFICATIONS.md`](../docs/PUSH_NOTIFICATIONS.md) for the full Android and iOS setup.

Every new in-app notification queues FCM delivery to the user's registered devices when their `push_enabled` preference is enabled. Invalid FCM registration tokens are removed automatically. Device registration requires the `push_devices` migration above and a running queue worker.

## Background processing, Redis, notifications, and OpenAPI

Keep these processes running alongside the API:

```powershell
# Processes queued FCM delivery, payment work, email, and test notifications.
php artisan queue:work

# Runs booking and membership expiry plus notification pruning.
php artisan schedule:work
```

Use the database queue locally, or set `QUEUE_CONNECTION=redis` and `CACHE_STORE=redis` after Redis is running for production-like queue and cache storage. In production, supervise one or more `queue:work` processes so failed workers restart, and configure one cron entry to run `php artisan schedule:run` every minute instead of `schedule:work`. The dashboard response is cached per tenant for five minutes.

The notification endpoints provide in-app inbox, read-state, preferences, device registration, and a queued test notification. A queue worker must be running to process `POST /api/v1/notifications/test` and FCM delivery when the queue connection is not `sync`.

OpenAPI JSON is available at `GET /api/v1/openapi.json`. Payment intents support PayMongo for production use and the local/testing Xendit mock described in the payment guide. Configure PayMongo's secret key, webhook secret, and HTTPS return URL from `.env.example` before production use. Webhooks remain idempotent by provider event ID.
