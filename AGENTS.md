## Guideline
* When trying to run a Laravel `artisan` command do it inside the docker container like this: `docker exec restoraerp_core_api php artisan <rest of the command>`
* On the **production** server (`ssh resp`) artisan must run as the deploy account, from the `current` symlink: `sudo -u publicdeploy php /var/www/core-api/current/artisan <rest of the command>`. Running it as your own login (`resadmin`) leaves `bootstrap/cache/*` and `storage/logs/*` owned by `resadmin`, which the app — running as `publicdeploy` — can read but never rewrite. See "Running artisan on production" in `README.md`, including how to clean it up.

## Automated Test Coverage Guardrail (>= 85%)
* **Mandatory Requirement:** All new tasks, features, and bug fixes MUST achieve at least **85% automated test coverage** on new/edited code using PHPUnit (`php artisan test` or `./vendor/bin/phpunit`).
* A task is NOT considered done until automated tests covering all logic paths are written, passing, and meet or exceed the 85% threshold.
