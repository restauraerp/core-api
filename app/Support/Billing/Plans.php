<?php

namespace App\Support\Billing;

use InvalidArgumentException;

/**
 * Reads config/plans.php. The one place that answers "what does this tier get".
 *
 * Kept separate from the Tenant model so entitlement questions can be asked
 * about a tier name alone - middleware, commands and validation all need that
 * before any tenant is loaded.
 */
class Plans
{
    /**
     * @return list<string>
     */
    public static function tiers(): array
    {
        return array_keys(config('plans.tiers', []));
    }

    public static function exists(string $tier): bool
    {
        return in_array($tier, self::tiers(), true);
    }

    public static function default(): string
    {
        return (string) config('plans.default', 'starter');
    }

    /**
     * @return array<string, mixed>
     */
    public static function tier(string $tier): array
    {
        $config = config("plans.tiers.{$tier}");

        if ($config === null) {
            throw new InvalidArgumentException(
                "Unknown plan [{$tier}]. Expected one of: ".implode(', ', self::tiers())
            );
        }

        return $config;
    }

    public static function label(string $tier): string
    {
        return (string) (self::tier($tier)['name'] ?? $tier);
    }

    /**
     * Outlet cap for a tier. NULL means unlimited.
     */
    public static function outletLimit(string $tier): ?int
    {
        $limit = self::tier($tier)['outlets'] ?? null;

        return $limit === null ? null : (int) $limit;
    }

    /**
     * @return list<string>
     */
    public static function modules(string $tier): array
    {
        return array_values(self::tier($tier)['modules'] ?? []);
    }

    public static function includesModule(string $tier, string $module): bool
    {
        return in_array($module, self::modules($tier), true);
    }

    /**
     * Every permission a tier is entitled to: its modules' permissions plus the
     * ones no tier can lose (dashboard, settings, role management).
     *
     * @return list<string>
     */
    public static function permissions(string $tier): array
    {
        $byModule = Modules::permissions();

        $granted = Modules::alwaysGranted();

        foreach (self::modules($tier) as $module) {
            $granted = array_merge($granted, $byModule[$module] ?? []);
        }

        return array_values(array_unique($granted));
    }

    /**
     * Outlet caps keyed by tier, for callers that want the whole table.
     *
     * @return array<string, int|null>
     */
    public static function outletLimits(): array
    {
        $limits = [];

        foreach (self::tiers() as $tier) {
            $limits[$tier] = self::outletLimit($tier);
        }

        return $limits;
    }
}
