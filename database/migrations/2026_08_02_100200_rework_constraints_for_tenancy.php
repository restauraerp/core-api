<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Globally-unique columns become unique *per tenant*, and hot indexes get
     * tenant_id as their leading column.
     *
     * Without the first half, the second restaurant to sign up cannot create a
     * product category called "Desserts". Without the second, every reporting
     * query scans across all tenants before filtering.
     *
     * Every step is guarded by an existence check. MySQL does not roll DDL back,
     * so a failure part-way through this migration leaves the schema half
     * converted - and the migration is not recorded as run, meaning the next
     * `artisan migrate` replays it from the top.
     */

    /** table => [column, old unique index name] */
    private array $uniques = [
        'users' => ['email', 'users_email_unique'],
        'customers' => ['phone', 'customers_phone_unique'],
        'product_categories' => ['slug', 'product_categories_slug_unique'],
        'tags' => ['slug', 'tags_slug_unique'],
        'pages' => ['slug', 'pages_slug_unique'],
        'website_settings' => ['key', 'website_settings_key_unique'],
        'locations' => ['slug', 'locations_slug_unique'],
        'organizations' => ['name', 'organizations_name_unique'],
    ];

    /** index name => [table, new column list] - the name is reused in place */
    private array $indexes = [
        'idx_orders_status_date' => ['orders', ['tenant_id', 'status', 'created_at']],
        'idx_orders_created_at' => ['orders', ['tenant_id', 'created_at']],
        'idx_orders_location_created_at' => ['orders', ['tenant_id', 'location_id', 'created_at']],
        'idx_reservations_date_status' => ['reservations', ['tenant_id', 'reservation_date', 'status']],
        'idx_deliveries_status' => ['deliveries', ['tenant_id', 'status']],
        'idx_notifications_user_read' => ['notifications', ['tenant_id', 'user_id', 'is_read']],
        'idx_usage_logs_created_at' => ['usage_logs', ['tenant_id', 'created_at']],
        'idx_attendances_date' => ['attendances', ['tenant_id', 'date']],
        'idx_products_is_active' => ['products', ['tenant_id', 'is_active']],
    ];

    public function up(): void
    {
        foreach ($this->uniques as $table => [$column, $oldIndex]) {
            if ($this->hasIndex($table, $oldIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($oldIndex));
            }

            $newIndex = "{$table}_tenant_id_{$column}_unique";

            if (! $this->hasIndex($table, $newIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique(['tenant_id', $column], $newIndex));
            }
        }

        foreach ($this->indexes as $indexName => [$table, $columns]) {
            // Already converted (leading column is tenant_id) - nothing to do.
            if ($this->leadingColumn($table, $indexName) === 'tenant_id') {
                continue;
            }

            $this->ensureForeignKeyCover($table, $indexName);

            if ($this->hasIndex($table, $indexName)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
            }

            Schema::table($table, fn (Blueprint $t) => $t->index($columns, $indexName));
        }

        // password_reset_tokens keys on email alone, so two tenants sharing an
        // email address would overwrite each other's reset token. Existing
        // tokens are dropped rather than backfilled - they expire in 60 minutes
        // and a stale one is worse than asking for a new link.
        if (! Schema::hasColumn('password_reset_tokens', 'tenant_id')) {
            DB::table('password_reset_tokens')->delete();

            Schema::table('password_reset_tokens', function (Blueprint $t) {
                $t->unsignedBigInteger('tenant_id')->after('email');
                $t->dropPrimary();
                $t->primary(['tenant_id', 'email']);
                $t->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('password_reset_tokens', 'tenant_id')) {
            Schema::table('password_reset_tokens', function (Blueprint $t) {
                $t->dropForeign(['tenant_id']);
                $t->dropPrimary();
                $t->dropColumn('tenant_id');
                $t->primary('email');
            });
        }

        foreach ($this->indexes as $indexName => [$table, $columns]) {
            if ($this->hasIndex($table, $indexName)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($indexName));
            }

            $original = array_values(array_diff($columns, ['tenant_id']));
            Schema::table($table, fn (Blueprint $t) => $t->index($original, $indexName));
        }

        // The standalone FK-cover indexes added by ensureForeignKeyCover() are
        // deliberately left in place: an index on a foreign key column is
        // correct either way, and dropping them here would re-trigger the same
        // error 1553 in reverse.

        foreach ($this->uniques as $table => [$column, $oldIndex]) {
            $newIndex = "{$table}_tenant_id_{$column}_unique";

            if ($this->hasIndex($table, $newIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->dropUnique($newIndex));
            }

            if (! $this->hasIndex($table, $oldIndex)) {
                Schema::table($table, fn (Blueprint $t) => $t->unique($column, $oldIndex));
            }
        }
    }

    /**
     * InnoDB requires every foreign key column to be the leading column of some
     * index. Several of the indexes being rewritten here are silently doing
     * that job (orders.location_id, notifications.user_id, ...), and prefixing
     * them with tenant_id would leave the FK uncovered - InnoDB rejects the
     * drop with error 1553.
     *
     * So: if the index about to be dropped leads with a foreign key column and
     * nothing else covers it, add a standalone index on that column first.
     */
    private function ensureForeignKeyCover(string $table, string $index): void
    {
        $leading = $this->leadingColumn($table, $index);

        if ($leading === null) {
            return;
        }

        $isForeignKey = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $leading)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();

        if (! $isForeignKey) {
            return;
        }

        $alreadyCovered = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $leading)
            ->where('SEQ_IN_INDEX', 1)
            ->where('INDEX_NAME', '!=', $index)
            ->exists();

        if ($alreadyCovered) {
            return;
        }

        Schema::table($table, fn (Blueprint $t) => $t->index($leading, "{$table}_{$leading}_index"));
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }

    private function leadingColumn(string $table, string $index): ?string
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->where('SEQ_IN_INDEX', 1)
            ->value('COLUMN_NAME');
    }
};
