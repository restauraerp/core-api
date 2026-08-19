<?php

namespace Tests\Unit;

use App\Rules\PhoneNumber as PhoneRule;
use App\Support\PhoneNumber;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PhoneNumberTest extends TestCase
{
    /**
     * Every way one number turns up at the till, and the one form it is stored
     * in. This is what makes the uniqueness rule mean anything.
     *
     * @return array<string, array{?string, ?string}>
     */
    public static function normalisations(): array
    {
        return [
            'national with trunk zero' => ['01712345678', '+8801712345678'],
            'subscriber only' => ['1712345678', '+8801712345678'],
            'already international' => ['+8801712345678', '+8801712345678'],
            'country code, no plus' => ['8801712345678', '+8801712345678'],
            'dashes and spaces' => ['01712-345 678', '+8801712345678'],
            'blank' => ['', null],
            'null' => [null, null],
            'punctuation only' => ['---', null],
        ];
    }

    #[DataProvider('normalisations')]
    public function test_it_normalises_to_one_stored_form(?string $input, ?string $expected): void
    {
        $this->assertSame($expected, PhoneNumber::normalise($input));
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function mobileNumbers(): array
    {
        return [
            'a real mobile' => ['01712345678', true],
            'lowest allocated operator' => ['01312345678', true],
            'highest allocated operator' => ['01912345678', true],
            // The exact production complaint: a dial code joined to a national
            // number that kept its trunk zero.
            'trunk zero left in behind the country code' => ['+88001677181007', false],
            'one digit too many' => ['017123456789', false],
            'one digit too few' => ['0171234567', false],
            'unallocated operator digit' => ['01012345678', false],
            'a landline cannot receive a receipt' => ['0299887766', false],
            'not a number' => ['call me', false],
        ];
    }

    #[DataProvider('mobileNumbers')]
    public function test_mobile_accepts_only_reachable_numbers(string $phone, bool $valid): void
    {
        $passes = Validator::make(['phone' => $phone], ['phone' => [PhoneRule::mobile()]])->passes();

        $this->assertSame($valid, $passes, "Unexpected verdict for {$phone}");
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function dialableNumbers(): array
    {
        return [
            'a mobile is still fine' => ['01712345678', true],
            'a Dhaka landline' => ['0299887766', true],
            'a short area-code landline' => ['0312345678', true],
            'far too long to dial' => ['+8808797979879879', false],
            'far too short' => ['0123', false],
        ];
    }

    #[DataProvider('dialableNumbers')]
    public function test_any_accepts_landlines_but_not_nonsense(string $phone, bool $valid): void
    {
        $passes = Validator::make(['phone' => $phone], ['phone' => [PhoneRule::any()]])->passes();

        $this->assertSame($valid, $passes, "Unexpected verdict for {$phone}");
    }
}
