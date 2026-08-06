<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## Tenant Management Commands

A tenant is one restaurant business. It sits *above* `locations`: a tenant owns many
locations (branches), and every other domain row belongs to exactly one tenant. The
tenant's `slug` doubles as the **restaurant code** typed on the login form and the value
accepted in the `X-Tenant-ID` header.

Artisan runs inside the container:

```bash
docker exec restoraerp_core_api php artisan <command>
```

Every command below documents its own options — `php artisan help tenants:create` prints
the full option list plus worked examples.

### `tenants:create` — create and provision a tenant

```bash
php artisan tenants:create <name> [--slug=] [--plan=] [--owner-email=] [--owner-name=] [--owner-password=] [--owner-phone=]
```

| Parameter | Required | Value | Description |
| --- | --- | --- | --- |
| `<name>` | yes | string | The restaurant name, e.g. `"Bangla Bistro"`. Quote it if it contains spaces. Also seeds the head-office location name, `site_name` and `meta_title`. |
| `--slug=` | no | string | URL-safe restaurant code / `X-Tenant-ID` value, e.g. `bangla-bistro`. Derived from `<name>` when omitted, with a numeric suffix (`-2`, `-3`, …) if that slug is taken. Must be unique. |
| `--plan=` | no | `starter` \| `growth` \| `business` \| `enterprise` | Subscription tier, from `config/plans.php`. Outlet cap: `starter` = 1, `growth` = 1, `business` = 3, `enterprise` = unlimited. Starter gets the six core modules; the rest get all twelve. Defaults to `starter`. An unknown value aborts before anything is created. |
| `--owner-email=` | no | email | Creates a `restaurant_admin` user with this email and stores it as the tenant's contact email. Omit it and the tenant is created with no users. |
| `--owner-name=` | no | string | Display name for the owner. Defaults to `<name> Owner`. Ignored without `--owner-email`. |
| `--owner-password=` | no | string | Owner password. When omitted a 16-character password is generated and printed once. Ignored without `--owner-email`. |
| `--owner-phone=` | no | string | Owner phone number, e.g. `+8801700000000`. Ignored without `--owner-email`. |

Creating a tenant also provisions it, because a tenant with no roles, location or
categories is not a usable account: per-tenant role copies, a head-office location,
product categories, tags, starter website settings and pages, loyalty settings, and an
**inactive** VAT rule (restaurant VAT varies, so it is never enabled by guesswork). The
tenant starts on a 14-day trial. Provisioning is idempotent — re-running tops up what is
missing without duplicating what is there.

```bash
# Slug derived from the name, shared plan, no owner user
php artisan tenants:create "Bangla Bistro"

# Explicit restaurant code, 5-outlet plan
php artisan tenants:create "Bangla Bistro" --slug=bangla-bistro --plan=dedicated

# Creates the owner too, printing a generated password
php artisan tenants:create "Dhaka Grill House" --owner-email=owner@dhakagrill.com

# Everything set explicitly
php artisan tenants:create "Dhaka Grill House" --plan=cloud \
    --owner-email=owner@dhakagrill.com --owner-name="Rahim Uddin" \
    --owner-password='s3cret!' --owner-phone=+8801700000000
```

A generated owner password is printed **once**. Copy it before the output scrolls away.

### `tenants:list` — list existing tenants

```bash
php artisan tenants:list [--status=] [--plan=] [--with-trashed] [--json]
```

Takes no arguments — every option is a filter or an output switch.

| Parameter | Required | Value | Description |
| --- | --- | --- | --- |
| `--status=` | no | `trialing` \| `active` \| `suspended` \| `cancelled` | Show only tenants in this status. An unknown value aborts with the accepted list. |
| `--plan=` | no | `starter` \| `growth` \| `business` \| `enterprise` | Show only tenants on this tier. An unknown value aborts with the accepted list. |
| `--with-trashed` | no | flag, no value | Include soft-deleted tenants; their name is suffixed `(deleted)`. Excluded by default. |
| `--json` | no | flag, no value | Print the tenant records as pretty JSON instead of a table. Filters still apply. |

Columns: ID, name, restaurant code, plan, status, outlets used against the plan cap
(`∞` when unlimited, `!` when the tenant is at or over it), user count, contact email
and trial end date. Counts
come from a single `withCount`, so the listing stays one query regardless of tenant
count. An unknown `--status` or `--plan` fails with the accepted values.

```
+----+---------------+--------------------+--------+----------+---------+-------+-----------------------+------------+
| ID | Name          | Code (X-Tenant-ID) | Plan   | Status   | Outlets | Users | Contact               | Trial ends |
+----+---------------+--------------------+--------+----------+---------+-------+-----------------------+------------+
| 1  | RestoraERP    | default            | cloud  | active   | 1/2     | 1     | admin@restauraerp.com | -          |
| 2  | Bangla Bistro | bangla-bistro      | cloud  | active   | 5/2     | 47    | demo@restauraerp.com  | -          |
+----+---------------+--------------------+--------+----------+---------+-------+-----------------------+------------+
  2 tenant(s).
```

### `tenants:plan` — change a tenant's subscription tier

```bash
php artisan tenants:plan <id|slug> <tier> [--keep-outlet-limit] [--dry-run]
```

| Parameter | Required | Value | Description |
| --- | --- | --- | --- |
| `<id\|slug>` | yes | integer or string | Which tenant to move. All-digit values match `tenants.id`, anything else matches the slug. |
| `<tier>` | yes | `starter` \| `growth` \| `business` \| `enterprise` | The tier to move to. Unknown values abort with the accepted list. |
| `--keep-outlet-limit` | no | flag, no value | Leave `max_outlets` alone instead of resetting it to the tier default. For a support exception — a restaurant granted more outlets than its tier normally allows. |
| `--dry-run` | no | flag, no value | Print the before/after table and change nothing. |

**Editing the `plan` column by hand is not enough**, which is why this exists: the
outlet cap is stored per tenant and role permissions are synced from the tier, so a
hand-edited row leaves a customer paying for Growth with Starter's permissions and
Starter's cap. This command updates all three.

Downgrades never delete data. A restaurant dropping to a smaller tier keeps the outlets
it has — the cap only refuses the next one — and its CRM and HR records stay in the
database, simply out of reach until it upgrades again.

```bash
php artisan tenants:plan acme-bistro growth --dry-run
php artisan tenants:plan acme-bistro growth
php artisan tenants:plan 7 enterprise
```

### `tenants:subscribe` — start or renew a paid subscription

```bash
php artisan tenants:subscribe <id|slug> [--monthly] [--yearly] [--until=YYYY-MM-DD] [--dry-run]
```

| Parameter | Required | Value | Description |
| --- | --- | --- | --- |
| `<id\|slug>` | yes | integer or string | Which tenant. All-digit values match `tenants.id`, anything else the slug. |
| `--monthly` | one of these | flag | Extends by one month and records a monthly cycle (7-day grace). |
| `--yearly` | one of these | flag | Extends by one year and records a yearly cycle (14-day grace). |
| `--until=` | one of these | `YYYY-MM-DD` | Explicit end date instead of a cycle length. A past date is refused. |
| `--dry-run` | no | flag | Show the before/after table and change nothing. |

**This is the one command for every paid transition** — converting a trial, renewing
before expiry, renewing after expiry, and reviving a tenant the nightly sweep has already
suspended. It sets `status=active`, records `billing_cycle`, moves `subscription_ends_at`,
and drops the tenant's cached entitlement so access is restored on the very next request
rather than when the cache expires.

**Renewing early never costs paid days.** The new period runs from the later of today and
the current end date, so paying a week early buys a month from the old end date. Renewing
after expiry runs from today, since the old period is gone.

```bash
php artisan tenants:subscribe acme-bistro --monthly     # trial → paid, or renew
php artisan tenants:subscribe acme-bistro --yearly
php artisan tenants:subscribe 7 --until=2027-03-31
```

### The subscription lifecycle

A billing problem makes a restaurant **read-only**; it does not lock it out. A manager
whose invoice is late can still log in, see every order and read every setting — they
just cannot save anything new, and they are told why and who to call.

| State | Login | Read | Write |
| --- | --- | --- | --- |
| Trial running (7 days from creation) | ✓ | ✓ | ✓ |
| Trial ended | ✓ | ✓ | **✗** — immediately, trials get no grace |
| Subscription running | ✓ | ✓ | ✓ |
| Monthly expired, within 7-day grace | ✓ | ✓ | ✓ (with a countdown warning) |
| Yearly expired, within 14-day grace | ✓ | ✓ | ✓ (with a countdown warning) |
| Past grace | ✓ | ✓ | **✗** |
| `suspended` | ✓ | ✓ | **✗** |
| `cancelled` | **✗** | **✗** | **✗** |

Enforcement is by HTTP method, not endpoint: `GET`/`HEAD`/`OPTIONS` always pass,
`POST`/`PUT`/`PATCH`/`DELETE` are refused in read-only states. New controllers are covered
the day they are written. `auth/login` and `auth/logout` are the deliberate exceptions —
blocking login would stop a customer reaching the message telling them to pay.

Refusals return `403` with a machine-readable `error` (`trial_expired`,
`subscription_expired`, `account_suspended`, `subscription_cancelled`), prose explaining
that existing data is safe, and a `contact` block from `config/support.php`:

```json
{
  "error": "subscription_expired",
  "message": "Your monthly subscription ended on Jul 28, 2026 and the 7-day grace period ran out on Aug 4, 2026, so new data cannot be saved. Everything you have already entered is still here and fully readable - renew and saving resumes immediately.",
  "read_only": true, "reads_allowed": true, "writes_allowed": false,
  "contact": { "email": "support@restauraerp.com", "url": "https://restauraerp.com/#pricing" }
}
```

While a subscription is **in grace**, successful writes carry a `subscription_warning`
object with `days_remaining`, so a client can nag before anything actually stops working.

State is resolved on every request and cached in Redis (`config/billing.php`). The entry
is never allowed to outlive the next transition, and every command that changes billing
forgets it outright. Trial length and both grace windows are config, not code.

### `tenants:expire` — suspend lapsed tenants

```bash
php artisan tenants:expire [--dry-run]
```

Moves tenants past their `trial_ends_at` (while trialing) or `subscription_ends_at`
(once active) to `suspended`. **Tenants still inside their grace window are left alone**,
since grace is full access, not a stale status. Runs daily from the scheduler.

**Enforcement does not depend on it running.** State is evaluated at request time, so a
tenant goes read-only the moment its date passes. This command exists so the `status`
column stops saying "trialing" about an account that has been read-only for a week.
Suspending changes nothing about access — suspended and past-grace are both read-only.

A tenant with no end date recorded is open-ended, not expired, and is left alone —
otherwise every tenant created before billing dates were tracked would be locked out.

⚠️ This needs `php artisan schedule:run` in cron. core-api had no scheduler before
this; see the core-api scheduler section in `infra/templates/user_data.sh.tpl`.

### Plans and entitlement

Tiers live in `config/plans.php` — outlet caps, module lists, prices — and are the one
source of truth the API reads. `docs/Pricing.md` mirrors the marketing site.

| Tier | Outlets | Modules |
| --- | --- | --- |
| `starter` | 1 | The six core: POS, Orders, Catalog, Inventory, Accounting, Reporting |
| `growth` | 1 | All 12 |
| `business` | 3 | All 12 |
| `enterprise` | unlimited | All 12 |

Two independent enforcement points:

- **`module:<name>` middleware** on route groups in `routes/api.php` → `403
  module_not_in_plan`. This checks the *tenant's tier*, deliberately separate from the
  permission system: roles are editable by the tenant, so permissions alone would be an
  entitlement check the customer controls. It is also the only API-side gate — the
  permission catalogue currently drives the front's navigation, not route access.
- **Role permissions capped by the tier** in `TenantProvisioner::createRoles()`, so the
  admin front hides modules the tenant has not bought without knowing anything about
  plans.

`view_locations` and `update_location` are granted on every tier regardless: a
restaurant must be able to correct its own address. What Location Management sells is
*multi-branch*, and that is the outlet cap's job.

### `tenants:remove` — permanently erase a tenant

```bash
php artisan tenants:remove <id|slug> [--force] [--keep-assets] [--dry-run]
```

| Parameter | Required | Value | Description |
| --- | --- | --- | --- |
| `<id\|slug>` | yes | integer or string | Which tenant to erase. An all-digit value is matched against `tenants.id`, anything else against `tenants.slug` (the restaurant code). Soft-deleted tenants are matched too. No match aborts with a non-zero exit code. |
| `--force` | no | flag, no value | Skip the confirmation prompt and delete immediately. Required in scripts: run non-interactively (`-n`, or with no TTY) without it and the prompt defaults to "no", so the command aborts having changed nothing. |
| `--keep-assets` | no | flag, no value | Delete every row but leave the uploaded files on disk. The summary reports how many files were kept. |
| `--dry-run` | no | flag, no value | Print the full report and exit without deleting anything. Overrides `--force`. |

**This is a force delete, not a soft delete. There is no undo.** Without `--force` the
command prints the full breakdown and asks for confirmation first; run `--dry-run`
beforehand if you want the report without the prompt.

What it removes:

- **Domain rows** — every table carrying the tenant's `tenant_id`. Most of this is the
  database's own cascade (every domain table has a cascading FK to `tenants`), so
  deletion order is the database's problem, not the command's.
- **Auth leftovers** — `sessions` and `personal_access_tokens` belong to the tenant's
  users but have no foreign key back to the tenant, so the cascade never reaches them.
  They are deleted first, while the user IDs still exist.
- **Pivot leftovers** — Spatie's `model_has_roles` / `model_has_permissions` carry
  `tenant_id` without a cascading FK of their own, so a sweep clears them afterwards.
- **Uploaded assets** — the files the tenant's rows point at.

The table list is discovered from the schema (any table with a `tenant_id` column) rather
than hardcoded, so a table added in a later migration is covered without touching this
command.

Uploads land in shared folders on the `public` disk (`foods/`, `users/`, `locations/`,
…) rather than a per-tenant prefix, so there is no directory to drop — asset paths are
resolved row by row from `images.url`, `location_media.url`, `product_media.url`,
`product_categories.image_url`, `inventory_items.image`, `users.image_url`,
`expenses.receipt_url` and the `logo_url` / `favicon_url` / `cover_image_url` rows in
`website_settings`. Remote URLs are skipped. `cctv_cameras.stream_url`, `locations.map_url`
and `social_links.url` are excluded — they hold third-party URLs, not uploads.

Two safeguards worth knowing:

- **Shared assets survive.** Seeded and demo data reuse the same file across tenants, so
  a path another tenant still references is kept and reported rather than deleted.
- **Files go last.** Row deletion runs in one transaction and files are removed only
  after it commits, so a failed delete cannot leave rows pointing at missing files.

Every run ends with a summary of the rows removed per table and the asset totals:

```
Tenant #7 "Throwaway QA" (code: throwaway-qa, plan: shared, status: trialing)

+--------------------+--------------+
| Table              | Rows removed |
+--------------------+--------------+
| website_settings   | 19           |
| product_categories | 15           |
| tags               | 13           |
| roles              | 6            |
| images             | 4            |
| users              | 1            |
+--------------------+--------------+
  65 row(s) removed across 11 table(s).
  2 asset file(s) removed.
  1 asset(s) kept - still referenced by another tenant.
  1 referenced asset(s) were already absent from disk.
```

```bash
# Report only — deletes nothing
php artisan tenants:remove 7 --dry-run

# Prompt, then remove
php artisan tenants:remove throwaway-qa

# No prompt (scripts)
php artisan tenants:remove 7 --force

# Drop the rows, keep the files
php artisan tenants:remove 7 --keep-assets
```

### `demo:refresh` — rebuild the demo restaurant

```bash
php artisan demo:refresh [--force] [--if-demo] [--dry-run] [--keep-assets] [--skip-baseline]
```

Takes no arguments — which tenant is the demo comes from `DEMO_TENANT_SLUG`.

| Parameter | Required | Value | Description |
| --- | --- | --- | --- |
| `--force` | no | flag, no value | Skip the confirmation prompt. Required from cron and CI: with no TTY the prompt defaults to "no" and the refresh silently does nothing. |
| `--if-demo` | no | flag, no value | On a box where `DEMO_MODE` is not true, print a notice and exit 0 instead of failing. Lets one deploy pipeline and one cron line be safe on every box. |
| `--dry-run` | no | flag, no value | Report what would be rebuilt and change nothing — no migrations, no seeding, no deletion. |
| `--keep-assets` | no | flag, no value | Leave the demo tenant's uploaded files on disk while its rows are rebuilt. |
| `--skip-baseline` | no | flag, no value | Skip the `migrate` + baseline seed step. Rarely needed; they are cheap and idempotent. |
| `--isolated` | no | flag, no value | Standard Laravel isolation lock. Stops the 04:00 cron colliding with a release tagged at 04:00. |

**This is the only supported way to (re)import demo data.** `demodata.sh`, the deploy
workflow and the production cron all funnel through it.

What it does, in order:

1. `migrate --force` — **never** `migrate:fresh`. Applies what is pending, drops nothing.
2. `db:seed --force` — the baseline (global permissions, the install tenant, the platform
   admin). All find-or-create, so it is safe on a populated database; `AdminUserSeeder`
   only sets a password on first creation, so a changed one survives.
3. `tenants:remove <demo-slug> --force` — the demo tenant's rows, sessions, tokens and
   uploaded files, plus any legacy demo tenant listed in `DEMO_LEGACY_TENANT_SLUGS`.
4. `db:seed --class=DemoSeeder --force` — rebuilds Bangla Bistro from scratch.

**It never drops a table and never touches a tenant other than the demo one.** That is
the entire point: this replaced `migrate:fresh --seed`, which dropped every table in the
database and destroyed a real customer tenant created with `tenants:create`.

Step 3 deletes rather than re-seeding over the top because `OrderSeeder` derives its IDs
from `MAX(id)+1` and bulk-inserts two years of orders — seeding a tenant that already has
data doubles it. A clean tenant is what `migrate:fresh` was really providing.

#### The DEMO_MODE gate

The command only runs where `DEMO_MODE=true`. That is deliberately a property of the
**box**, not of the command line: a cron entry, a deploy script or a copy-pasted command
cannot carry the permission with it to a customer install. `DEMO_MODE` already means
exactly this — `config/app.php` documents it as "must never be true on a customer
install", it defaults to `false`, and `GET /api/v1/demo-config` 404s without it.

`--force` stays orthogonal: it means "no TTY, don't prompt", nothing more. Without
`--if-demo` a non-demo box gets a hard, non-zero failure; with it, a notice and exit 0.

Two further guards cannot be flagged past: the command refuses if `DEMO_TENANT_SLUG` is
empty, or if it equals `INSTALL_TENANT_SLUG` — a `.env` typo must not be able to point
the deletion at the install tenant.

Note the demo tenant gets a **new numeric ID on every refresh**; its slug is the stable
identifier clients send as `X-Tenant-ID`. Demo logins and API tokens are invalidated each
time. `DEMO_LEGACY_TENANT_SLUGS` can be emptied once every box has refreshed once.

```
+----------------------+---------------+-------+--------+
| Rebuilt from scratch | Code          | Users | Orders |
+----------------------+---------------+-------+--------+
| #5 Bangla Bistro     | bangla-bistro | 47    | 50653  |
+----------------------+---------------+-------+--------+
+----------------+---------------+-------+--------+
| Left untouched | Code          | Users | Orders |
+----------------+---------------+-------+--------+
| #1 RestoraERP  | default       | 1     | 0      |
| #4 Acme Bistro | acme-bistro   | 1     | 0      |
+----------------+---------------+-------+--------+
```

```bash
# Report only — changes nothing
php artisan demo:refresh --dry-run

# Prompt, then rebuild
php artisan demo:refresh

# Cron and CI
php artisan demo:refresh --force --if-demo --isolated

# Same thing through the wrapper script
./demodata.sh --force
```

`demodata.sh` is a thin `set -euo pipefail` wrapper that `exec`s this command, so the
artisan exit code is the script's exit code. It used to be the `migrate:fresh` that caused
all of this; nothing in the deploy or in cron may run `migrate:fresh` again.

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

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
