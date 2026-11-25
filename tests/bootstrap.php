<?php

/**
 * Pins the test suite to its own database, before anything else loads.
 *
 * This has to happen here rather than in phpunit.xml or .env.testing, because
 * neither can win against the container's environment:
 *
 *   - docker-compose starts core-api with `env_file: ./core-api/.env`, so
 *     DB_DATABASE=restoraerp is a real OS-level environment variable.
 *   - Laravel loads .env / .env.testing *immutably* - dotenv never overwrites a
 *     variable that already exists in the environment.
 *   - PHPUnit's <env force="true"> does not reliably win either, since Laravel
 *     resolves env() through $_ENV/$_SERVER rather than putenv().
 *
 * The result was that `php artisan test` connected to the DEVELOPMENT database
 * and RefreshDatabase dropped every table in it on each run.
 *
 * Overwriting both superglobals here - before vendor/autoload.php and long
 * before the framework boots - is the one place that cannot be overridden.
 */
$token = $_ENV['TEST_TOKEN'] ?? $_SERVER['TEST_TOKEN'] ?? getenv('TEST_TOKEN');
$database = 'restoraerp_test';
if (! empty($token)) {
    $database = "{$database}_test_{$token}";
    try {
        $pdo = new PDO("mysql:host=mysql;port=3369", "root", "secret", [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}`");
    } catch (\Throwable $e) {
        // Silently continue if MySQL connection is deferred
    }
}

$testEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'mysql',
    'DB_PORT' => '3369',
    'DB_DATABASE' => $database,
    'DB_USERNAME' => 'root',
    'DB_PASSWORD' => 'secret',
];

foreach ($testEnvironment as $key => $value) {
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
    putenv("{$key}={$value}");
}

// Guard rail: if anything downstream still points the suite at a database whose
// name does not look like a test database, stop rather than destroy it.
if (! preg_match('/^restoraerp_test(_test_\d+)?$/', (string) $_ENV['DB_DATABASE'])) {
    fwrite(STDERR, "Refusing to run tests against database [{$_ENV['DB_DATABASE']}].\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
