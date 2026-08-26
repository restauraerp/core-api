<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Tenant;
use App\Support\Branding\RestaurantBranding;
use App\Support\Orders\InvoiceLink;
use App\Support\PhoneNumber;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class InvoiceController extends Controller
{
    public function __construct(private TenantContext $context) {}

    /**
     * Mints a shareable link for an order. Staff only.
     *
     * Separate from show() below on purpose: creating the authority to read an
     * invoice is a thing the restaurant does, and reading it is a thing their
     * customer does. Only the first needs a login.
     */
    public function link(Order $order): JsonResponse
    {
        $order->loadMissing('customer');

        // The number is normalised here rather than in the client, because
        // wa.me will not resolve a national-form number: `01711000020` opens
        // nothing, `8801711000020` opens the chat. Rows written before phone
        // canonicalisation still hold the national form, and the normaliser
        // that fixes them already lives on this side.
        $phone = PhoneNumber::normalise($order->customer?->phone);

        return response()->json(InvoiceLink::for($order) + [
            // Digits only, no leading +, which is the shape wa.me takes.
            'customer_phone' => $phone === null ? null : ltrim($phone, '+'),
        ]);
    }

    /**
     * The invoice itself, to whoever holds the signed link.
     *
     * Unauthenticated by design - the customer has no account and the signature
     * is the credential. Two consequences the route declaration handles:
     * ResolveTenant is skipped, because a customer has no restaurant to name,
     * and the tenant is instead taken from the order once it is found.
     *
     * That means loading the order past its tenant scope, which is only safe
     * because the signature already proved this exact order id was the one
     * granted. Nothing here reads a tenant from the request.
     */
    public function show(int $order): JsonResponse
    {
        $found = Order::withoutGlobalScopes()->find($order);

        // 404 rather than 403 for a cancelled or purged order: a link that no
        // longer resolves should not confirm the order ever existed.
        if ($found === null) {
            throw new NotFoundHttpException;
        }

        $tenant = Tenant::withoutGlobalScopes()->find($found->tenant_id);

        if ($tenant === null) {
            throw new NotFoundHttpException;
        }

        return $this->context->runFor($tenant, function () use ($found) {
            $found->load(['items.product', 'payments', 'customer', 'location']);

            return response()->json([
                'restaurant' => RestaurantBranding::current(),
                'order' => [
                    'id' => $found->id,
                    'token_number' => $found->token_number,
                    'created_at' => $found->created_at?->toIso8601String(),
                    'order_type' => $found->order_type,
                    'status' => $found->status,
                    'status_label' => $found->status_label,
                    'payment_status' => $found->payment_status,
                    'subtotal' => $found->subtotal,
                    'tax_amount' => $found->tax_amount,
                    'discount_amount' => $found->discount_amount,
                    'delivery_charge' => $found->delivery_charge,
                    'total' => $found->total,
                    'outlet' => $found->location?->name,
                    // First name only. The invoice is shared over WhatsApp and
                    // may be forwarded; it needs to say who it is for, not
                    // publish the customer's phone number and address.
                    'customer_name' => $found->customer?->name,
                    'items' => $found->items->map(fn ($item) => [
                        'name' => $item->product?->name ?? 'Item',
                        'quantity' => $item->quantity,
                        'price' => $item->price,
                        'notes' => $item->notes,
                    ])->all(),
                    'payments' => $found->payments->map(fn ($payment) => [
                        'method' => $payment->method,
                        'amount' => $payment->amount,
                        'status' => $payment->status,
                    ])->all(),
                ],
            ]);
        });
    }
}
