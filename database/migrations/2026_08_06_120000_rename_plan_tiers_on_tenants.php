<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Renames the plan enum from the deployment model to the tier a customer
     * actually buys.
     *
     * `shared|dedicated|cloud` described where a tenant was hosted. The pricing
     * page sells Starter, Growth, Business and Enterprise, and support had to
     * translate between the two every time - while `starter` had nowhere to be
     * stored at all, so the cheapest plan could not be recorded.
     *
     * Caps move with the names: config/plans.php now holds 1/1/3/unlimited,
     * matching what is advertised, where the code previously said 2/5/unlimited
     * and enforced none of it.
     *
     * MySQL cannot rename enum members in place, so this widens the column to
     * both vocabularies, rewrites the rows, then narrows it to the new one.
     */
    private const MAP = [
        'shared' => 'growth',
        'dedicated' => 'business',
        'cloud' => 'enterprise',
    ];

    private const OLD = ['shared', 'dedicated', 'cloud'];

    private const NEW = ['starter', 'growth', 'business', 'enterprise'];

    public function up(): void
    {
        $this->widen(array_merge(self::OLD, self::NEW), 'shared');

        foreach (self::MAP as $from => $to) {
            DB::table('tenants')->where('plan', $from)->update(['plan' => $to]);
        }

        $this->narrow(self::NEW, 'starter');

        // Bring every tenant's cap onto its tier. Tenants already over the new
        // cap keep the outlets they have - nothing is deleted here - but
        // hasReachedOutletLimit() will refuse the next one until they upgrade.
        // Grandfathering by leaving the old, larger cap in place would mean the
        // limit never bites for exactly the tenants it is meant to.
        foreach (config('plans.tiers') as $tier => $config) {
            DB::table('tenants')->where('plan', $tier)->update(['max_outlets' => $config['outlets']]);
        }

        DB::statement('ALTER TABLE tenants MODIFY max_outlets SMALLINT UNSIGNED NULL DEFAULT 1');
    }

    public function down(): void
    {
        $this->widen(array_merge(self::OLD, self::NEW), 'starter');

        foreach (array_flip(self::MAP) as $from => $to) {
            DB::table('tenants')->where('plan', $from)->update(['plan' => $to]);
        }

        // Starter has no pre-rename equivalent; the closest is the cheapest
        // shared tier it was sold alongside.
        DB::table('tenants')->where('plan', 'starter')->update(['plan' => 'shared']);

        $this->narrow(self::OLD, 'shared');

        foreach (['shared' => 2, 'dedicated' => 5, 'cloud' => null] as $plan => $outlets) {
            DB::table('tenants')->where('plan', $plan)->update(['max_outlets' => $outlets]);
        }

        DB::statement('ALTER TABLE tenants MODIFY max_outlets SMALLINT UNSIGNED NULL DEFAULT 2');
    }

    /**
     * @param  list<string>  $values
     */
    private function widen(array $values, string $default): void
    {
        DB::statement($this->enumStatement($values, $default));
    }

    /**
     * @param  list<string>  $values
     */
    private function narrow(array $values, string $default): void
    {
        DB::statement($this->enumStatement($values, $default));
    }

    /**
     * @param  list<string>  $values
     */
    private function enumStatement(array $values, string $default): string
    {
        $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));

        return "ALTER TABLE tenants MODIFY plan ENUM({$list}) NOT NULL DEFAULT '{$default}'";
    }
};
