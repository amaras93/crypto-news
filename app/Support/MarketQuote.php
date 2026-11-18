<?php

namespace App\Support;

use DateTimeImmutable;
use Throwable;

final readonly class MarketQuote
{
    public function __construct(
        public string $asset,
        public string $symbol,
        public string $name,
        public string $currency,
        public ?float $price,
        public ?float $change24h,
        public string $provider,
        public ?int $providerTimestamp,
        public ?int $fetchedAt,
        public string $unit,
        public string $status = 'current',
    ) {}

    public static function valid(string $asset, mixed $price, mixed $change, mixed $timestamp, int $fetchedAt, string $provider): ?self
    {
        $definition = config('markets.assets.'.$asset);
        if (! is_array($definition) || ! self::positivePrice($price) || ! is_int($timestamp)
            || $timestamp < 1230768000 || $timestamp > $fetchedAt + 120
            || $fetchedAt < 1230768000 || $fetchedAt > now()->timestamp + 120
            || $provider !== ($definition['group'] === 'crypto' ? 'coingecko' : 'goldapi')) {
            return null;
        }

        $change = (is_int($change) || is_float($change)) && is_finite((float) $change) && $change >= -100 ? (float) $change : null;

        return new self($asset, $definition['symbol'], $asset, 'USD', (float) $price, $change, $provider, $timestamp, $fetchedAt, $definition['unit']);
    }

    public static function positivePrice(mixed $price): bool
    {
        return (is_int($price) || is_float($price)) && is_finite((float) $price) && $price > 0 && $price <= 1.0e15;
    }

    public static function isoTimestamp(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : $date->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    public static function fromCache(string $asset, mixed $data): ?self
    {
        if (! is_array($data) || ! is_int($data['fetched_at'] ?? null) || ! is_string($data['provider'] ?? null)) {
            return null;
        }

        return self::valid($asset, $data['price'] ?? null, $data['change_24h'] ?? null, $data['provider_timestamp'] ?? null, $data['fetched_at'], $data['provider']);
    }

    public static function unavailable(string $asset): self
    {
        $definition = config('markets.assets.'.$asset);

        return new self($asset, $definition['symbol'], $asset, 'USD', null, null, $definition['group'] === 'crypto' ? 'coingecko' : 'goldapi', null, null, $definition['unit'], 'unavailable');
    }

    public static function demo(string $asset, array $data): self
    {
        $definition = config('markets.assets.'.$asset);

        return new self($asset, $definition['symbol'], $asset, 'USD', (float) $data['price'], (float) $data['change'], 'demo', null, null, $definition['unit'], 'demo');
    }

    public function withStatus(string $status): self
    {
        return new self($this->asset, $this->symbol, $this->name, $this->currency, $this->price, $this->change24h, $this->provider, $this->providerTimestamp, $this->fetchedAt, $this->unit, $status);
    }

    /** @return array<string, int|float|string|null> */
    public function toArray(): array
    {
        return [
            'asset' => $this->asset, 'symbol' => $this->symbol, 'name' => $this->name,
            'currency' => $this->currency, 'price' => $this->price, 'change_24h' => $this->change24h,
            'provider' => $this->provider, 'provider_timestamp' => $this->providerTimestamp,
            'fetched_at' => $this->fetchedAt, 'unit' => $this->unit, 'status' => $this->status,
        ];
    }
}
