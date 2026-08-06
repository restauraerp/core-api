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
| `--plan=` | no | `shared` \| `dedicated` \| `cloud` | Outlet cap: `shared` = 2, `dedicated` = 5, `cloud` = unlimited. Defaults to `shared`. An unknown value aborts before anything is created. |
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
php artisan tenants:create "Spice Garden" --owner-email=owner@spicegarden.com

# Everything set explicitly
php artisan tenants:create "Spice Garden" --plan=cloud \
    --owner-email=owner@spicegarden.com --owner-name="Rahim Uddin" \
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
| `--plan=` | no | `shared` \| `dedicated` \| `cloud` | Show only tenants on this plan. An unknown value aborts with the accepted list. |
| `--with-trashed` | no | flag, no value | Include soft-deleted tenants; their name is suffixed `(deleted)`. Excluded by default. |
| `--json` | no | flag, no value | Print the tenant records as pretty JSON instead of a table. Filters still apply. |

Columns: ID, name, restaurant code, plan, status, outlets used against the plan cap
(`∞` when `max_outlets` is `NULL`), user count, contact email and trial end date. Counts
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
