<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class PriceThreshold
{
    public function normalize(mixed $value): string
    {
        if ((! is_string($value) && ! is_int($value)) || ! preg_match('/\A[0-9]{1,16}(?:\.[0-9]{1,10})?\z/D', (string) $value)) {
            throw ValidationException::withMessages(['target_price' => __('engagement.invalid_threshold')]);
        }
        [$integer, $fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $integer = ltrim($integer, '0') ?: '0';
        $fraction = str_pad($fraction, 10, '0');
        $normalized = $integer.'.'.$fraction;
        if ($this->compare($normalized, '0.0000000000') <= 0 || $this->compare($normalized, '1000000000000000.0000000000') > 0) {
            throw ValidationException::withMessages(['target_price' => __('engagement.invalid_threshold')]);
        }

        return $normalized;
    }

    public function compare(string $left, string $right): int
    {
        [$leftInt, $leftFrac] = array_pad(explode('.', $left, 2), 2, '');
        [$rightInt, $rightFrac] = array_pad(explode('.', $right, 2), 2, '');
        $leftInt = ltrim($leftInt, '0') ?: '0';
        $rightInt = ltrim($rightInt, '0') ?: '0';

        return (strlen($leftInt) <=> strlen($rightInt)) ?: (strcmp($leftInt, $rightInt) <=> 0)
            ?: (strcmp(str_pad($leftFrac, 10, '0'), str_pad($rightFrac, 10, '0')) <=> 0);
    }

    public function quote(float $price): ?string
    {
        if (! MarketQuote::positivePrice($price)) {
            return null;
        }
        $formatted = number_format($price, 10, '.', '');

        return $this->compare($formatted, '0.0000000000') > 0 ? $formatted : null;
    }
}
