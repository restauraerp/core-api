<?php

namespace App\Support\Billing;

use App\Support\Tenancy\RoleDefinitions;

/**
 * The twelve product modules, as sold.
 *
 * A module is the unit a customer buys: the pricing page splits Starter's "6
 * Core Modules" from everyone else's "All 12", and these constants are those
 * two lists. Each one maps to the permissions already defined in
 * RoleDefinitions, which the front reads to decide which admin routes exist -
 * so entitling a module lights up its UI without any front-end change.
 */
class Modules
{
    public const POS = 'pos';

    public const ORDERS = 'orders';

    public const CATALOG = 'catalog';

    public const INVENTORY = 'inventory';

    public const ACCOUNTING = 'accounting';

    public const REPORTING = 'reporting';

    public const CRM = 'crm';

    public const HR = 'hr';

    public const DELIVERY = 'delivery';

    public const KITCHEN_KIOSK = 'kitchen_kiosk';

    public const LOCATIONS = 'locations';

    public const WEBSITE = 'website';

    /**
     * The six every tier includes.
     *
     * @var list<string>
     */
    public const CORE = [
        self::POS,
        self::ORDERS,
        self::CATALOG,
        self::INVENTORY,
        self::ACCOUNTING,
        self::REPORTING,
    ];

    /**
     * The other six, sold from Growth up.
     *
     * @var list<string>
     */
    public const PAID = [
        self::CRM,
        self::HR,
        self::DELIVERY,
        self::KITCHEN_KIOSK,
        self::LOCATIONS,
        self::WEBSITE,
    ];

    /**
     * @var list<string>
     */
    public const ALL = [
        self::POS,
        self::ORDERS,
        self::CATALOG,
        self::INVENTORY,
        self::ACCOUNTING,
        self::REPORTING,
        self::CRM,
        self::HR,
        self::DELIVERY,
        self::KITCHEN_KIOSK,
        self::LOCATIONS,
        self::WEBSITE,
    ];

    /**
     * Permissions belonging to each module.
     *
     * Deliberately not exhaustive of RoleDefinitions::permissions(): dashboard
     * and settings are not modules anyone buys or loses, so they stay outside
     * this map and are granted to every tier.
     *
     * @return array<string, list<string>>
     */
    public static function permissions(): array
    {
        return [
            self::POS => ['view_pos', 'create_pos_order'],
            self::ORDERS => ['view_orders', 'update_order_status', 'delete_order'],
            self::CATALOG => ['view_catalog', 'create_catalog_item', 'update_catalog_item', 'delete_catalog_item'],
            self::INVENTORY => ['view_inventory', 'create_inventory_item', 'update_inventory_item', 'delete_inventory_item'],
            self::ACCOUNTING => ['view_accounting', 'manage_ledgers', 'manage_expenses'],
            self::REPORTING => ['view_reporting'],
            self::CRM => ['view_crm', 'manage_customers', 'manage_loyalty_settings'],
            self::HR => ['view_hr', 'manage_employees', 'manage_attendance', 'manage_leaves', 'manage_payroll'],
            self::DELIVERY => ['view_delivery', 'update_delivery_status'],
            self::KITCHEN_KIOSK => ['view_kitchen_kiosk', 'update_kiosk_status'],
            self::LOCATIONS => ['view_locations', 'create_location', 'update_location', 'delete_location'],
            self::WEBSITE => ['view_website', 'manage_website_content'],
        ];
    }

    /**
     * Permissions no tier can lose - the shell every restaurant needs to be a
     * usable account at all.
     *
     * @return list<string>
     */
    public static function alwaysGranted(): array
    {
        return array_values(array_unique(array_merge(
            array_diff(
                RoleDefinitions::permissions(),
                array_merge(...array_values(self::permissions())),
            ),
            self::ESSENTIAL,
        )));
    }

    /**
     * Module permissions no tier may lose.
     *
     * Every restaurant has at least one outlet and has to be able to correct
     * its own address and phone number. What the Locations module sells is
     * *multi-branch* management, and that is enforced by the outlet cap
     * (Tenant::hasReachedOutletLimit) rather than by hiding the screen - a
     * Starter tenant that cannot edit its single outlet is a broken account,
     * not an upsell.
     *
     * @var list<string>
     */
    public const ESSENTIAL = [
        'view_locations',
        'update_location',
    ];
}
