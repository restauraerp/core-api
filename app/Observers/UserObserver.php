<?php

namespace App\Observers;

use App\Models\User;
use App\Services\DeliveryPersonService;

class UserObserver
{
    public function saved(User $user): void
    {
        if (! empty($user->tenant_id)) {
            DeliveryPersonService::flushForTenant($user->tenant_id);
        }
    }

    public function deleted(User $user): void
    {
        if (! empty($user->tenant_id)) {
            DeliveryPersonService::flushForTenant($user->tenant_id);
        }
    }
}
