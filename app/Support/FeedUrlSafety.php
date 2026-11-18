<?php

namespace App\Support;

class FeedUrlSafety
{
    public function __construct(private FeedDnsResolver $dns) {}

    /** @return array{url: string, host: string} */
    public function inspect(string $url): array
    {
        if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\]/', $url)
            || preg_match('/[\x00-\x1f\x7f\\\\]/', rawurldecode($url))) {
            throw new FeedFailure('unsafe_url');
        }
        $parts = parse_url($url);
        if (! $parts || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || (isset($parts['port']) && $parts['port'] !== 443)) {
            throw new FeedFailure('unsafe_url');
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        $host = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46) ?: '';
        if (! preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}\z/D', $host)
            || preg_match('/\.(localhost|local|internal|test|invalid|example|onion)$/D', $host)
            || filter_var(trim($host, '[]'), FILTER_VALIDATE_IP)) {
            throw new FeedFailure('unsafe_host');
        }
        foreach (explode('&', $parts['query'] ?? '') as $pair) {
            $key = strtolower(rawurldecode(explode('=', $pair, 2)[0]));
            if (in_array($key, ['key', 'auth', 'session', 'sessionid', 'sid'], true)
                || preg_match('/token|secret|password|signature|credential|authorization|api.?key|access.?key/', $key)) {
                throw new FeedFailure('credentials_not_supported');
            }
        }
        $normalized = 'https://'.$host.($parts['path'] ?? '/');
        if (isset($parts['query']) && $parts['query'] !== '') {
            $normalized .= '?'.$parts['query'];
        }

        return ['url' => $normalized, 'host' => $host];
    }

    /** @return array{url: string, host: string, addresses: list<string>} */
    public function resolve(string $url): array
    {
        $safe = $this->inspect($url);
        $addresses = $this->dns->resolve($safe['host']);
        if (! $addresses || count($addresses) > 32) {
            throw new FeedFailure('dns_unavailable');
        }
        foreach ($addresses as $address) {
            if (! $this->publicAddress($address)) {
                throw new FeedFailure('unsafe_dns_address');
            }
        }

        return $safe + ['addresses' => $addresses];
    }

    public function publicAddress(string $address): bool
    {
        if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return false;
        }
        if (str_contains($address, ':')) {
            $packed = inet_pton($address);

            return $packed !== false && (ord($packed[0]) & 0xE0) === 0x20
                && ! $this->inRange($address, '2001:db8::', 32)
                && ! $this->inRange($address, '2001::', 32)
                && ! $this->inRange($address, '2002::', 16);
        }
        foreach ([['0.0.0.0', 8], ['10.0.0.0', 8], ['100.64.0.0', 10], ['127.0.0.0', 8], ['169.254.0.0', 16],
            ['172.16.0.0', 12], ['192.0.0.0', 24], ['192.0.2.0', 24], ['192.168.0.0', 16],
            ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4], ['240.0.0.0', 4]] as [$network, $bits]) {
            if ($this->inRange($address, $network, $bits)) {
                return false;
            }
        }

        return true;
    }

    private function inRange(string $address, string $network, int $bits): bool
    {
        $ip = inet_pton($address);
        $base = inet_pton($network);
        if ($ip === false || $base === false || strlen($ip) !== strlen($base)) {
            return false;
        }
        $bytes = intdiv($bits, 8);
        $remaining = $bits % 8;

        return substr($ip, 0, $bytes) === substr($base, 0, $bytes)
            && ($remaining === 0 || (ord($ip[$bytes]) & (0xFF << (8 - $remaining))) === (ord($base[$bytes]) & (0xFF << (8 - $remaining))));
    }
}
