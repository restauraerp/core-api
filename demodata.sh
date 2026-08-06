#!/usr/bin/env bash
#
# The one way demo data is (re)imported.
#
# Everything is delegated to `php artisan demo:refresh`, which rebuilds ONLY the
# demo tenant and refuses to run unless DEMO_MODE=true. It never drops a table.
#
# This script used to be `migrate:fresh --force --seed`, which dropped every
# table in the database - fine when the box was nothing but a demo, fatal once
# real restaurants were on it.
#
# Arguments are passed through. Use --force from cron or CI: with no TTY the
# confirmation prompt defaults to "no" and the refresh silently does nothing.
set -euo pipefail

cd "$(dirname "$0")"

exec php artisan demo:refresh "$@"
