<?php

namespace App\Models\Concerns;

use App\Support\PhoneNumber;

/**
 * Stores a phone number in one shape, whoever wrote it.
 *
 * Validation rejects a number that could never be dialled; this makes sure the
 * one that survives is written the same way every time. Both are needed, and
 * for different reasons: the rule guards the four HTTP forms, while this guards
 * everything else - seeders, the demo refresh, imports, a tinker session, the
 * POS - so a canonical `phone` column is a property of the model rather than of
 * whichever caller remembered to call the normaliser.
 *
 * It is also what makes the uniqueness rule mean anything. Without it
 * `01712345678` and `+8801712345678` are two customers, and the second one
 * quietly shadows the first's order history.
 */
trait NormalisesPhone
{
    public function setPhoneAttribute(?string $value): void
    {
        $this->attributes['phone'] = PhoneNumber::normalise($value);
    }
}
