<?php

namespace Tests\Feature;

use App\Support\ProductionReadiness;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionConfigurationTest extends TestCase
{
    public function test_readiness_output_is_read_only_and_never_contains_credentials(): void
    {
        Http::preventStrayRequests();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('R', 32)), 'ai.openai_key' => 'fixture-sensitive-ai-key', 'database.connections.mysql.password' => 'fixture-db-secret']);
        $this->assertSame(1, Artisan::call('app:check-production-readiness', ['--json' => true, '--no-interaction' => true]));
        $output = Artisan::output();
        $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        $checks = array_column($report['checks'], null, 'id');
        $this->assertStringContainsString('Configuration only', $report['scope']);
        $this->assertSame('FAIL', $checks['environment']['status']);
        $this->assertSame('PASS', $checks['market_safety']['status']);
        foreach (['fixture-sensitive-ai-key', 'fixture-db-secret', config('app.key')] as $secret) {
            $this->assertStringNotContainsString($secret, $output);
        }
        Http::assertNothingSent();
    }

    #[DataProvider('proxyAddresses')]
    public function test_proxy_configuration_rejects_wildcards_and_invalid_networks(string $entry, bool $valid): void
    {
        $this->assertSame($valid, ProductionReadiness::validProxy($entry));
    }

    public static function proxyAddresses(): array
    {
        return [['10.0.0.10', true], ['10.0.0.0/16', true], ['2001:db8::/64', true], ['::1', true], ['*', false], ['REMOTE_ADDR', false], ['0.0.0.0/0', false], ['::/0', false], ['10.0.0.0/33', false], ['10.0.0.0/-1', false], ['example.com', false]];
    }

    public function test_unsafe_retry_windows_and_process_local_locks_are_flagged(): void
    {
        config(['queue.default' => 'database', 'queue.connections.database.retry_after' => 85, 'cache.default' => 'array', 'markets.cache_store' => null, 'discovery.cache_store' => null]);
        $checks = array_column(app(ProductionReadiness::class)->checks(), 'status', 'id');
        foreach (['queue_retry_window', 'cache.default', 'markets.cache_store', 'discovery.cache_store'] as $id) {
            $this->assertSame('FAIL', $checks[$id]);
        }
        config(['queue.connections.database.retry_after' => 180, 'cache.default' => 'database']);
        $checks = array_column(app(ProductionReadiness::class)->checks(), 'status', 'id');
        $this->assertSame('PASS', $checks['queue_retry_window']);
        $this->assertSame('PASS', $checks['markets.cache_store']);
    }

    public function test_enabled_paid_or_advertising_integrations_fail_this_phase_safety_check(): void
    {
        config(['ai.generation_enabled' => true, 'advertising.adsense_enabled' => true, 'engagement.newsletters_enabled' => true, 'markets.mode' => 'live']);
        $checks = array_column(app(ProductionReadiness::class)->checks(), 'status', 'id');
        foreach (['ai_safety', 'market_safety', 'advertising_safety', 'engagement_safety'] as $id) {
            $this->assertSame('FAIL', $checks[$id]);
        }
    }

    public function test_production_configuration_and_routes_cache_in_isolation_without_touching_local_environment(): void
    {
        $relativeDirectory = 'storage/framework/testing/phase11-config-'.Str::uuid();
        $directory = base_path($relativeDirectory);
        mkdir($directory, 0700, true);
        touch($directory.'/disposable.sqlite');
        $originalEnvironment = hash_file('sha256', base_path('.env'));
        $environment = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://news.example.test',
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('R', 32)), 'APP_CONFIG_CACHE' => $relativeDirectory.'/config.php',
            'APP_ROUTES_CACHE' => $relativeDirectory.'/routes.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $directory.'/disposable.sqlite', 'DB_URL' => '',
            'SESSION_SECURE_COOKIE' => 'true', 'SESSION_ENCRYPT' => 'true', 'SESSION_DRIVER' => 'database', 'QUEUE_CONNECTION' => 'database',
            'DB_QUEUE_RETRY_AFTER' => '180', 'CACHE_STORE' => 'database', 'MARKET_DATA_MODE' => 'demo', 'AI_EMERGENCY_STOP' => 'true',
            'AI_GENERATION_ENABLED' => 'false', 'AI_TRANSLATION_ENABLED' => 'false', 'AI_CANDIDATE_PROCESSING_ENABLED' => 'false', 'AI_SCHEDULED_GENERATION_ENABLED' => 'false',
            'NEWSLETTERS_ENABLED' => 'false', 'PRICE_ALERTS_ENABLED' => 'false', 'ENGAGEMENT_REAL_EMAIL_ENABLED' => 'false',
            'ADVERTISING_ENABLED' => 'false', 'DIRECT_ADVERTISING_ENABLED' => 'false', 'ADSENSE_ENABLED' => 'false', 'ADVERTISING_MODE' => 'disabled',
            'OPENAI_API_KEY' => '', 'COINGECKO_API_KEY' => '', 'GOLDAPI_API_KEY' => '', 'MAIL_MAILER' => 'log'];
        foreach (['config:cache', 'route:cache'] as $command) {
            $process = new Process([PHP_BINARY, 'artisan', $command, '--no-interaction'], base_path(), $environment);
            $process->setTimeout(60)->run();
            $this->assertSame(0, $process->getExitCode(), $command.' failed: '.$process->getOutput().$process->getErrorOutput());
        }
        $this->assertFileExists($directory.'/config.php');
        $this->assertFileExists($directory.'/routes.php');
        $cached = require $directory.'/config.php';
        $this->assertSame('production', $cached['app']['env']);
        $this->assertFalse($cached['app']['debug']);
        $this->assertSame($environment['APP_KEY'], $cached['app']['key']);
        $this->assertTrue($cached['session']['secure']);
        $this->assertTrue($cached['session']['encrypt']);
        $this->assertSame('demo', $cached['markets']['mode']);
        $routes = new Process([PHP_BINARY, 'artisan', 'route:list', '--json', '--except-vendor', '--no-interaction'], base_path(), $environment);
        $routes->setTimeout(60)->run();
        $this->assertSame(0, $routes->getExitCode(), $routes->getErrorOutput());
        $names = array_column(json_decode($routes->getOutput(), true, flags: JSON_THROW_ON_ERROR), 'name');
        foreach (['home', 'news.show', 'account', 'sitemap.index', 'filament.admin.auth.login'] as $name) {
            $this->assertContains($name, $names);
        }
        $this->assertSame($originalEnvironment, hash_file('sha256', base_path('.env')));
    }
}
