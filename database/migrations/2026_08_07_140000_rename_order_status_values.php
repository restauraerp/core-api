<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Say what the floor says.
 *
 * Staff call the stage after cooking "ready to serve" and the stage after
 * packing "picked up by delivery"; the database said `cooked` and `picked`. The
 * words on the screen were already being translated, which is how a report
 * comes to disagree with a kitchen display.
 *
 * Existing orders are rewritten so there is one spelling rather than two.
 * OrderFlow::normalise() still resolves the old words, so a client that has not
 * been updated - or a request replayed from a log - keeps working.
 */
return new class extends Migration
{
    private const RENAMES = [
        'cooked' => 'ready_to_serve',
        'picked' => 'picked_up',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('orders')->where('status', $old)->update(['status' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::RENAMES as $old => $new) {
            DB::table('orders')->where('status', $new)->update(['status' => $old]);
        }
    }
};
