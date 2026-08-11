<?php

namespace App\Http\Controllers\Platform;

use App\Http\Controllers\Controller;
use App\Support\Billing\Plans;
use Illuminate\Http\JsonResponse;

/**
 * The plan catalogue, so the website quotes what the API will actually enforce.
 *
 * Prices live in config/plans.php on this side and are duplicated into the
 * marketing copy on the other. Serving them here is what lets the website stop
 * guessing - and makes a mismatch visible rather than silent.
 */
class PlanController extends Controller
{
    public function index(): JsonResponse
    {
        $plans = collect(Plans::tiers())->map(function (string $tier) {
            $config = Plans::tier($tier);

            return [
                'tier' => $tier,
                'name' => $config['name'],
                'description' => $config['description'],
                'outlets' => $config['outlets'],
                'price_monthly' => $config['price_monthly'],
                'price_yearly' => $config['price_yearly'],
                'setup_fee' => $config['setup_fee'],
                'modules' => Plans::modules($tier),
            ];
        })->values();

        return response()->json([
            'default' => Plans::default(),
            'currency' => 'BDT',
            'plans' => $plans,
        ]);
    }
}
