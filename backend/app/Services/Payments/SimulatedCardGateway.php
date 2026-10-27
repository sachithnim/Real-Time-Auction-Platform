<?php

namespace App\Services\Payments;

/**
 * Stands in for a real card processor (bonus challenge: payment simulation).
 * Pure and deterministic: the card number alone decides the outcome, using the
 * test numbers card processors publish. Nothing is charged.
 */
class SimulatedCardGateway
{
    /** Always declined, like a real processor's decline test card. */
    public const DECLINED_CARD = '4000000000000002';

    /** Declined for insufficient funds. */
    public const INSUFFICIENT_FUNDS_CARD = '4000000000009995';

    /**
     * @return array{approved: bool, reason: string|null}
     */
    public function charge(string $cardNumber, int $amount): array
    {
        return match (self::digits($cardNumber)) {
            self::DECLINED_CARD => ['approved' => false, 'reason' => 'CARD_DECLINED'],
            self::INSUFFICIENT_FUNDS_CARD => ['approved' => false, 'reason' => 'INSUFFICIENT_FUNDS'],
            default => ['approved' => true, 'reason' => null],
        };
    }

    public static function digits(string $cardNumber): string
    {
        return preg_replace('/[\s-]/', '', $cardNumber) ?? '';
    }

    /** The Luhn checksum every real card number satisfies. */
    public static function passesLuhn(string $digits): bool
    {
        if (! preg_match('/^\d{12,19}$/', $digits)) {
            return false;
        }

        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $digit) {
            $value = (int) $digit;
            if ($i % 2 === 1) {
                $value *= 2;
                if ($value > 9) {
                    $value -= 9;
                }
            }
            $sum += $value;
        }

        return $sum % 10 === 0;
    }

    public static function brand(string $digits): string
    {
        return match (true) {
            str_starts_with($digits, '4') => 'VISA',
            (bool) preg_match('/^(5[1-5]|2[2-7])/', $digits) => 'MASTERCARD',
            (bool) preg_match('/^3[47]/', $digits) => 'AMEX',
            default => 'CARD',
        };
    }
}
