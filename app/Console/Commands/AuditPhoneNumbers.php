<?php

namespace App\Console\Commands;

use App\Rules\PhoneNumber as PhoneRule;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Lists stored numbers the current rules would reject, and changes nothing.
 *
 * Validation only guards new writes. Production already holds numbers saved
 * under `max:20`, some of them too long to be a Bangladeshi mobile, and those
 * rows are the reason the rule exists at all. Rewriting them is a judgement
 * call about somebody's customer data - a number with an extra digit could be
 * a typo to drop or a digit to insert, and only the restaurant knows which -
 * so this reports and stops. Fixing is a separate, deliberate step.
 *
 *     php artisan phones:audit
 *     php artisan phones:audit --tenant=7
 */
class AuditPhoneNumbers extends Command
{
    protected $signature = 'phones:audit
                            {--tenant= : Only this tenant id}
                            {--csv= : Write the findings to this path instead of the table}';

    protected $description = 'Report stored phone numbers that fail validation. Read-only.';

    /**
     * Table => the strictness that table is held to, matching the controllers.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'customers' => 'mobile',
        'users' => 'mobile',
        'suppliers' => 'any',
        'locations' => 'any',
    ];

    public function handle(): int
    {
        $tenant = $this->option('tenant');
        $findings = [];

        foreach (self::TABLES as $table => $strictness) {
            $query = DB::table($table)
                ->select('id', 'name', 'phone')
                ->whereNotNull('phone')
                ->where('phone', '!=', '');

            if ($tenant !== null) {
                $query->where('tenant_id', $tenant);
            }

            foreach ($query->orderBy('id')->cursor() as $row) {
                $rule = $strictness === 'mobile' ? PhoneRule::mobile() : PhoneRule::any();

                $validator = Validator::make(['phone' => $row->phone], ['phone' => [$rule]]);

                if ($validator->passes()) {
                    continue;
                }

                $findings[] = [
                    'table' => $table,
                    'id' => $row->id,
                    'name' => $row->name ?? '',
                    'stored' => $row->phone,
                    'normalised' => PhoneNumber::normalise($row->phone) ?? '-',
                    'problem' => $validator->errors()->first('phone'),
                ];
            }
        }

        if ($findings === []) {
            $this->info('Every stored number passes. Nothing to fix.');

            return self::SUCCESS;
        }

        if ($path = $this->option('csv')) {
            $this->writeCsv($path, $findings);
            $this->info(count($findings).' bad numbers written to '.$path);

            return self::SUCCESS;
        }

        $this->table(['Table', 'ID', 'Name', 'Stored', 'Normalised', 'Problem'], $findings);
        $this->newLine();
        $this->warn(count($findings).' stored numbers would be rejected today. Nothing was changed.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, string|int>>  $findings
     */
    private function writeCsv(string $path, array $findings): void
    {
        $handle = fopen($path, 'w');
        fputcsv($handle, ['table', 'id', 'name', 'stored', 'normalised', 'problem']);

        foreach ($findings as $finding) {
            fputcsv($handle, $finding);
        }

        fclose($handle);
    }
}
