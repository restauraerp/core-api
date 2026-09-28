<?php

namespace App\Jobs\Telegram;

use App\Models\TelegramBot;
use App\Models\Tenant;
use App\Support\Telegram\TelegramClient;
use App\Support\Telegram\TelegramError;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One call to a restaurant's Telegram bot, on the queue.
 *
 * Every call goes through the `telegram` rate limiter (AppServiceProvider),
 * keyed by restaurant, so one bot never sends more than config('telegram')
 * allows however many riders are being assigned at once. A job held back by
 * the limiter is released, not failed - which counts as an attempt, so jobs
 * are bounded by time (retryUntil) rather than by a number of tries.
 *
 * Runs inside the restaurant's tenant context: a queue worker has none of
 * its own.
 */
abstract class TelegramJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        return [new RateLimited('telegram')];
    }

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function handle(TenantContext $context): void
    {
        $tenant = Tenant::find($this->tenantId);

        if ($tenant === null) {
            return;
        }

        $context->runFor($tenant, function () {
            $bot = TelegramBot::first();

            // Disconnected since this was queued: nothing to send through.
            if ($bot === null) {
                return;
            }

            try {
                $this->send(new TelegramClient($bot->token), $bot);

                // Working again: stop showing the owner an old failure.
                if ($bot->last_error !== null) {
                    $bot->forceFill(['last_error' => null])->save();
                }
            } catch (TelegramError $e) {
                $this->handleError($e, $bot);
            }
        });
    }

    abstract protected function send(TelegramClient $telegram, TelegramBot $bot): void;

    /** A rider this job was sending to is no longer reachable. */
    protected function chatUnreachable(TelegramError $e): void {}

    private function handleError(TelegramError $e, TelegramBot $bot): void
    {
        if ($e->isRateLimited()) {
            // Telegram's own limit, on top of ours: wait what it asks.
            $this->release(max(1, $e->retryAfter ?? 5));

            return;
        }

        if ($e->errorCode === 0) {
            // No answer at all. Try again shortly; retryUntil bounds it.
            $this->release(30);

            return;
        }

        if ($e->isUnreachableChat()) {
            $this->chatUnreachable($e);

            return;
        }

        if ($e->isStaleMessage()) {
            return;
        }

        // Anything else will not fix itself by retrying. Record it where the
        // restaurant can see it, and stop.
        $bot->forceFill(['last_error' => $e->getMessage()])->save();
        Log::warning('Telegram call failed for tenant '.$this->tenantId.': '.$e->getMessage());
    }
}
