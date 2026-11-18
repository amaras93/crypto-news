<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;

class ProductionReadiness
{
    /** @return list<array{id: string, status: string, severity: string, detail: string}> */
    public function checks(): array
    {
        $checks = [];
        $add = function (string $id, bool $passed, string $detail, string $severity = 'P1') use (&$checks): void {
            $checks[] = ['id' => $id, 'status' => $passed ? 'PASS' : 'FAIL', 'severity' => $severity, 'detail' => $detail];
        };
        $url = parse_url((string) config('app.url'));
        $key = (string) config('app.key');
        $key = str_starts_with($key, 'base64:') ? (base64_decode(substr($key, 7), true) ?: '') : $key;
        $add('environment', app()->isProduction(), 'Production requires APP_ENV=production; local development is intentionally different.');
        $add('debug', config('app.debug') === false, 'Production debug output must be disabled.');
        $add('canonical_url', is_array($url) && ($url['scheme'] ?? '') === 'https' && ! empty($url['host']) && ! isset($url['user']) && ! isset($url['pass']), 'Configure the real canonical HTTPS APP_URL; certificate and DNS checks are external.');
        $add('encryption_key', Encrypter::supported($key, (string) config('app.cipher')), 'A valid application encryption key must be injected from secret storage. Its value is never reported.');
        $add('cookies', config('session.secure') === true && config('session.http_only') === true && in_array(config('session.same_site'), ['lax', 'strict'], true), 'Session cookies require Secure, HttpOnly and an appropriate SameSite policy.');
        $add('session_encryption', config('session.encrypt') === true, 'Encrypt persisted session payloads.', 'P2');
        $add('shared_sessions', $this->sharedStore((string) config('session.driver')), 'Use shared database or Redis sessions across workers.');
        $add('database', config('database.default') === 'mysql' && extension_loaded('pdo_mysql'), 'Configure MySQL with PDO MySQL. This does not execute or prove MySQL integration tests.');
        foreach (['cache.default', 'markets.cache_store', 'discovery.cache_store'] as $setting) {
            $store = config($setting) ?: config('cache.default');
            $add($setting, $this->sharedStore((string) config('cache.stores.'.$store.'.driver')), 'Use a shared database or Redis store; shared connectivity and atomic locks require staging verification.');
        }
        $connection = (string) config('queue.default');
        $add('queue_backend', $this->sharedStore((string) config('queue.connections.'.$connection.'.driver')), 'Use a persistent database or Redis queue.');
        $add('queue_retry_window', (int) config('queue.connections.'.$connection.'.retry_after') >= 115, 'retry_after must exceed the longest 85-second job by at least 30 seconds; recommended value is 180.');
        $add('worker_timeouts', extension_loaded('pcntl'), 'Production workers require Linux PCNTL for job timeout enforcement; verify Supervisor and worker settings separately.');
        $add('trusted_proxies', collect(config('security.trusted_proxies', []))->every(fn (string $entry): bool => self::validProxy($entry)), 'Only explicit proxy IP addresses or CIDRs are accepted; an empty list trusts none.');
        $add('trusted_hosts', collect(config('security.additional_hosts', []))->every(fn (string $host): bool => filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false), 'Additional trusted hosts must be literal hostnames without schemes, ports or wildcard patterns.');
        $add('built_assets', is_file(public_path('build/manifest.json')) && ! is_file(public_path('hot')), 'Build production Vite assets and remove a development hot-file from the deployment artifact.');
        $add('market_safety', config('markets.mode') === 'demo', 'This phase requires DEMO market data.');
        $add('ai_safety', config('ai.emergency_stop') === true && ! config('ai.generation_enabled') && ! config('ai.translation_enabled') && ! config('ai.candidate_processing_enabled') && ! config('ai.scheduled_generation_enabled') && ! config('ai.automatic_publishing'), 'Paid generation, translation and automatic publishing must remain disabled.');
        $add('engagement_safety', ! config('engagement.newsletters_enabled') && ! config('engagement.price_alerts_enabled') && ! config('engagement.real_email_enabled'), 'Scheduled engagement and real engagement email remain disabled pending separate approval.');
        $add('advertising_safety', ! config('advertising.enabled') && ! config('advertising.direct_enabled') && ! config('advertising.adsense_enabled') && config('advertising.mode') === 'disabled', 'Advertising and AdSense remain disabled; CMP and account approval are external requirements.');

        return $checks;
    }

    public static function validProxy(string $entry): bool
    {
        [$address, $bits] = array_pad(explode('/', $entry, 2), 2, null);

        return filter_var($address, FILTER_VALIDATE_IP) !== false
            && ($bits === null || (ctype_digit($bits) && (int) $bits > 0 && (int) $bits <= (str_contains($address, ':') ? 128 : 32)));
    }

    private function sharedStore(string $driver): bool
    {
        return in_array($driver, ['database', 'redis'], true);
    }
}
