<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Asks the marketing site to send a branded email.
 *
 * Every customer-facing email lives on the website, which owns the layouts and
 * the copy. This API has none of that, so instead of growing a second set of
 * templates it hands the trigger across the same shared-secret channel the
 * website uses to call here.
 *
 * Never throws. A missing notification must not fail the action that caused it:
 * a password that was successfully changed is still changed even if the email
 * about it never left.
 */
class WebsiteNotifier
{
    public function passwordChanged(string $email, string $name, ?string $restaurantName = null): void
    {
        $this->post('notifications/password-changed', [
            'email' => $email,
            'name' => $name,
            'restaurant_name' => $restaurantName,
        ]);
    }

    private function post(string $path, array $payload): void
    {
        $website = rtrim((string) config('platform.website_url'), '/');
        $secret = (string) config('platform.token');

        if ($website === '' || $secret === '') {
            Log::warning("Cannot notify the website ({$path}): website URL or platform token missing.");

            return;
        }

        try {
            $response = Http::withToken($secret)
                ->acceptJson()
                ->asJson()
                ->timeout(10)
                ->post($website.'/api/'.ltrim($path, '/'), $payload);

            if ($response->failed()) {
                Log::error("Website refused {$path}: ".$response->status().' '.$response->body());
            }
        } catch (\Throwable $e) {
            Log::error("Failed to reach the website for {$path}: ".$e->getMessage());
        }
    }
}
