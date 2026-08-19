<?php

namespace App\Support;

/**
 * Reduces a phone number to E.164 - a leading plus and digits, nothing else.
 *
 * Numbers arrive from the till, from imports and from four different forms,
 * and `01712345678`, `+8801712345678`, `8801712345678` and `01712-345678` are
 * all one number. Storing them as typed means a customer who gave their number
 * twice is two rows, the phone uniqueness rule never fires, and looking
 * somebody up depends on guessing how the cashier typed it that day.
 *
 * Normalising is not validating - see App\Rules\PhoneNumber for that. Anything
 * that cannot be made sense of comes back null.
 *
 * The website has its own copy of this logic in App\Support\ContactValue. The
 * two repositories deploy separately and share no package, so the duplication
 * is deliberate; keep the E.164 output identical if either one changes.
 */
class PhoneNumber
{
    /** Bangladesh, and the only dial code assumed when a number gives none. */
    public const DEFAULT_DIAL_CODE = '+880';

    /**
     * The four shapes that actually turn up, in the order they are tested:
     * an already-international number, a bare country code without the plus,
     * a national number with its trunk zero, and a subscriber number alone.
     */
    public static function normalise(?string $value, ?string $dialCode = null): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $hadPlus = str_starts_with($value, '+');
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        if ($digits === '') {
            return null;
        }

        if ($hadPlus) {
            return '+'.$digits;
        }

        $dialDigits = preg_replace('/\D+/', '', $dialCode ?: self::DEFAULT_DIAL_CODE) ?? '';

        // Carries the country code without the plus, e.g. 8801712345678. Only
        // read that way when enough follows to be a subscriber number, so a
        // local number that happens to start with those digits is not mangled.
        if ($dialDigits !== '' && str_starts_with($digits, $dialDigits) && strlen($digits) > strlen($dialDigits) + 6) {
            return '+'.$digits;
        }

        // National form: one trunk zero, dropped in favour of the dial code.
        if (str_starts_with($digits, '0')) {
            return '+'.$dialDigits.ltrim($digits, '0');
        }

        return '+'.$dialDigits.$digits;
    }

    /**
     * The subscriber part - what is left once the country code is removed.
     */
    public static function subscriber(string $e164, string $dialDigits): string
    {
        $digits = ltrim($e164, '+');

        return str_starts_with($digits, $dialDigits)
            ? substr($digits, strlen($dialDigits))
            : $digits;
    }
}
