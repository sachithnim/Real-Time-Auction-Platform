<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\SimulatedCardGateway;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SimulatedCardGatewayTest extends TestCase
{
    /**
     * @return array<string, array{string, bool}>
     */
    public static function cardNumbers(): array
    {
        return [
            'visa test card' => ['4242424242424242', true],
            'decline test card' => ['4000000000000002', true],
            'mastercard test card' => ['5555555555554444', true],
            'last digit changed' => ['4242424242424241', false],
            'letters' => ['4242abcd42424242', false],
            'too short' => ['42424242424', false],
        ];
    }

    #[DataProvider('cardNumbers')]
    public function test_card_numbers_are_checked_with_luhn(string $number, bool $valid): void
    {
        $this->assertSame($valid, SimulatedCardGateway::passesLuhn($number));
    }

    public function test_spaces_and_dashes_are_ignored(): void
    {
        $this->assertSame('4242424242424242', SimulatedCardGateway::digits('4242 4242-4242 4242'));
    }

    public function test_the_brand_comes_from_the_first_digits(): void
    {
        $this->assertSame('VISA', SimulatedCardGateway::brand('4242424242424242'));
        $this->assertSame('MASTERCARD', SimulatedCardGateway::brand('5555555555554444'));
        $this->assertSame('MASTERCARD', SimulatedCardGateway::brand('2223003122003222'));
        $this->assertSame('AMEX', SimulatedCardGateway::brand('378282246310005'));
        $this->assertSame('CARD', SimulatedCardGateway::brand('6011111111111117'));
    }

    public function test_the_test_card_numbers_decide_the_outcome(): void
    {
        $gateway = new SimulatedCardGateway;

        $this->assertSame(['approved' => true, 'reason' => null], $gateway->charge('4242424242424242', 15_500));
        $this->assertSame(['approved' => false, 'reason' => 'CARD_DECLINED'], $gateway->charge('4000 0000 0000 0002', 15_500));
        $this->assertSame(['approved' => false, 'reason' => 'INSUFFICIENT_FUNDS'], $gateway->charge('4000000000009995', 15_500));
    }
}
