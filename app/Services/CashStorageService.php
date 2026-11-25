<?php

namespace App\Services;

use App\Models\CashStorage;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;

class CashStorageService
{
    /**
     * Resolves a cash storage for a payment.
     *
     * If an explicit storage ID is passed, loads and verifies it.
     * If not passed, auto-resolves to the tenant's active storage matching the method and location.
     */
    public function resolveStorage(string $method, ?int $storageId = null, ?int $locationId = null): ?CashStorage
    {
        if ($storageId) {
            $storage = CashStorage::query()->find($storageId);
            if ($storage && $storage->is_active) {
                return $storage;
            }
        }

        $type = CashStorage::normalizeType($method);

        // Try location-scoped first, then tenant-wide
        if ($locationId) {
            $storage = CashStorage::query()
                ->active()
                ->type($type)
                ->where('location_id', $locationId)
                ->first();

            if ($storage) {
                return $storage;
            }
        }

        return CashStorage::query()
            ->active()
            ->type($type)
            ->whereNull('location_id')
            ->first()
            ?? CashStorage::query()->active()->type($type)->first();
    }

    /**
     * Links a payment to the storage and updates its balance.
     */
    public function recordPayment(Payment $payment, ?CashStorage $storage): void
    {
        if (! $storage) {
            return;
        }

        $payment->cash_storage_id = $storage->id;
        $payment->save();

        // Increment current balance atomically
        $storage->increment('current_balance', $payment->amount);
    }

    /**
     * Ensures standard default storages exist for a tenant.
     */
    public function seedDefaultsForTenant(int $tenantId): void
    {
        $existing = CashStorage::query()->where('tenant_id', $tenantId)->exists();
        if ($existing) {
            return;
        }

        $now = now();
        DB::table('cash_storages')->insert([
            [
                'tenant_id' => $tenantId,
                'location_id' => null,
                'name' => 'Main Cash Drawer',
                'type' => CashStorage::TYPE_CASH,
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
                'is_active' => true,
                'notes' => 'Primary cash drawer/till for physical cash collections',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenantId,
                'location_id' => null,
                'name' => 'Card POS Terminal',
                'type' => CashStorage::TYPE_CARD,
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
                'is_active' => true,
                'notes' => 'Primary card POS machine for credit/debit card collections',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'tenant_id' => $tenantId,
                'location_id' => null,
                'name' => 'bKash Merchant Account',
                'type' => CashStorage::TYPE_BKASH,
                'opening_balance' => 0.00,
                'current_balance' => 0.00,
                'is_active' => true,
                'notes' => 'Primary bKash / MFS mobile wallet for customer collections',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }
}
