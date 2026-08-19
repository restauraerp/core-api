<?php

namespace App\Rules;

use App\Support\PhoneNumber as Normaliser;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A phone number that could actually be dialled.
 *
 * `max:20` was the only thing standing between the till and a number of any
 * shape, and production has the rows to prove it. A number nobody can reach is
 * worse than a blank field: it looks like a contactable customer right up
 * until somebody tries to send them a receipt.
 *
 * Two strictnesses, because two different things are being asked:
 *
 * - `mobile()` is for anyone we will send an SMS or a WhatsApp message to - a
 *   customer, an employee. Bangladeshi mobiles are eleven digits nationally
 *   (a trunk zero, `1`, an operator digit, eight more), which is the ten E.164
 *   keeps after `+880`. The operator digit is checked as well: 013-019 are
 *   allocated, 010-012 are not.
 * - `any()` is for a number that is only ever dialled - a supplier, an outlet's
 *   own front desk - where a landline is a perfectly good answer and demanding
 *   a mobile would be wrong.
 *
 * Outside Bangladesh both fall back to the E.164 bounds, which is honest about
 * not knowing rather than inventing a rule per country.
 */
class PhoneNumber implements ValidationRule
{
    private const BD_DIAL_DIGITS = '880';

    private const BD_MOBILE = '/^1[3-9][0-9]{8}$/';

    /** Bangladeshi landlines run to eight digits of subscriber behind a one- or
     *  two-digit area code, so the significant part lands in this range. */
    private const BD_ANY_MIN = 8;

    private const BD_ANY_MAX = 10;

    private function __construct(
        private readonly bool $mobileOnly,
        private readonly ?string $dialCode = null,
    ) {}

    /** Must be reachable by SMS or WhatsApp. */
    public static function mobile(?string $dialCode = null): self
    {
        return new self(true, $dialCode);
    }

    /** Must be dialable. A landline passes. */
    public static function any(?string $dialCode = null): self
    {
        return new self(false, $dialCode);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $normalised = Normaliser::normalise(
            is_scalar($value) ? (string) $value : null,
            $this->dialCode,
        );

        if ($normalised === null) {
            $fail('The :attribute must be a valid phone number.');

            return;
        }

        $digits = ltrim($normalised, '+');
        $dialDigits = preg_replace('/\D+/', '', $this->dialCode ?: Normaliser::DEFAULT_DIAL_CODE) ?? '';

        // A number carrying +880 itself is judged as Bangladeshi whatever the
        // form had selected - otherwise the wrong dropdown choice waves a
        // malformed number through.
        if (! str_starts_with($digits, $dialDigits) && str_starts_with($digits, self::BD_DIAL_DIGITS)) {
            $dialDigits = self::BD_DIAL_DIGITS;
        }

        $subscriber = Normaliser::subscriber($normalised, $dialDigits);

        if ($dialDigits === self::BD_DIAL_DIGITS) {
            $this->validateBangladeshi($subscriber, $fail);

            return;
        }

        // E.164 caps the whole number, country code included, at 15 digits.
        if (strlen($subscriber) < 6 || strlen($digits) > 15) {
            $fail('The :attribute must be a valid phone number.');
        }
    }

    private function validateBangladeshi(string $subscriber, Closure $fail): void
    {
        if ($this->mobileOnly) {
            if (preg_match(self::BD_MOBILE, $subscriber) !== 1) {
                $fail('The :attribute must be an 11-digit Bangladeshi mobile number, for example 01712345678.');
            }

            return;
        }

        $length = strlen($subscriber);

        if ($length < self::BD_ANY_MIN || $length > self::BD_ANY_MAX) {
            $fail('The :attribute must be a valid Bangladeshi phone number.');
        }
    }
}
