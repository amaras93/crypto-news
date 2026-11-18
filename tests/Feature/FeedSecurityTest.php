<?php

namespace Tests\Feature;

use App\Models\NewsSource;
use App\Support\FeedDnsResolver;
use App\Support\FeedFailure;
use App\Support\FeedHttpClient;
use App\Support\FeedParser;
use App\Support\FeedUrlSafety;
use GuzzleHttp\Handler\CurlFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\StreamInterface;
use Tests\TestCase;

class FeedSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->mock(FeedDnsResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34']);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn (string $url): array => [$url], [
            'http://publisher.com/rss', 'file:///etc/passwd', 'ftp://publisher.com/rss', 'https://localhost/feed',
            'https://127.0.0.1/feed', 'https://169.254.169.254/latest/meta-data', 'https://[::1]/rss',
            'https://internal.local/rss', 'https://2130706433/feed', 'https://0x7f000001/feed',
            'https://user:secret@publisher.com/feed', 'https://publisher.com:8443/feed', 'https://publisher.com/rss#fragment',
            'https://publisher.com/rss?api_key=secret', 'https://publisher.com/rss?access_token=secret',
            'https://publisher.com/rss?key=secret', 'https://publisher.com/rss?auth=secret',
            "https://publisher.com/\r\nX-Injected:yes", 'https://publisher.com/%0d%0aInjected', 'https://publisher.com/\\evil',
        ]);
    }

    #[DataProvider('unsafeUrls')]
    public function test_unsafe_urls_never_reach_http(string $url): void
    {
        try {
            app(FeedUrlSafety::class)->resolve($url);
            $this->fail('Unsafe URL accepted');
        } catch (FeedFailure $error) {
            $this->assertContains($error->reason, ['unsafe_url', 'unsafe_host', 'credentials_not_supported']);
        }
        Http::assertNothingSent();
    }

    public static function unsafeAddresses(): array
    {
        return array_map(fn (string $ip): array => [$ip], [
            '127.0.0.1', '10.1.2.3', '172.16.0.1', '192.168.1.1', '169.254.169.254',
            '100.64.0.1', '198.18.0.1', '192.0.2.1', '198.51.100.1', '203.0.113.1', '224.0.0.1',
            '0.0.0.0', '::1', 'fc00::1', 'fe80::1', '::ffff:127.0.0.1', '::ffff:8.8.8.8',
            '2001:db8::1', '2002:7f00:1::1', '64:ff9b::a00:1',
        ]);
    }

    #[DataProvider('unsafeAddresses')]
    public function test_internal_reserved_and_tunneling_addresses_are_rejected(string $address): void
    {
        $this->assertFalse(app(FeedUrlSafety::class)->publicAddress($address));
    }

    public function test_all_dns_answers_must_be_public_and_dns_rebinding_is_pinned(): void
    {
        $this->mock(FeedDnsResolver::class)->shouldReceive('resolve')->andReturn(['93.184.216.34', '127.0.0.1']);
        try {
            app(FeedHttpClient::class)->fetch(NewsSource::factory()->make());
            $this->fail('Mixed DNS records accepted');
        } catch (FeedFailure $error) {
            $this->assertSame('unsafe_dns_address', $error->reason);
        }
        Http::assertNothingSent();
    }

    public function test_http_requests_pin_dns_disable_redirects_and_proxies_and_bound_streams(): void
    {
        $source = NewsSource::factory()->create();
        $xml = file_get_contents(base_path('tests/Fixtures/discovery-rss.xml'));
        Http::fake(function (Request $request, array $options) use ($xml) {
            $this->assertSame(['publisher.com:443:93.184.216.34'], $options['curl'][CURLOPT_RESOLVE]);
            $this->assertFalse($options['allow_redirects']);
            $this->assertSame('', $options['proxy']);
            $this->assertTrue($options['verify']);
            $this->assertSame('identity', $request->header('Accept-Encoding')[0]);
            $this->assertSame(12, $options['timeout']);
            $this->assertInstanceOf(StreamInterface::class, $options['sink']);
            $this->assertSame(['https'], $options['protocols']);
            $factory = new CurlFactory(1);
            $easy = $factory->create(new \GuzzleHttp\Psr7\Request('GET', $request->url()), $options);
            $factory->release($easy);

            return Http::response($xml, 200, ['Content-Type' => 'application/rss+xml']);
        });
        $result = app(FeedHttpClient::class)->fetch($source);
        $this->assertSame($xml, $result['body']);
        Http::assertSentCount(1);
    }

    public function test_oversized_chunked_responses_abort_the_bounded_sink(): void
    {
        Http::fake(function (Request $request, array $options) {
            $options['sink']->write(str_repeat('x', 1048577));

            return Http::response('');
        });
        $this->expectExceptionMessage('feed_too_large');
        app(FeedHttpClient::class)->fetch(NewsSource::factory()->create());
    }

    public function test_oversized_declared_or_faked_responses_and_wrong_types_are_rejected(): void
    {
        $source = NewsSource::factory()->create();
        foreach ([
            Http::response(str_repeat('x', 1048577), 200, ['Content-Type' => 'application/rss+xml']),
            Http::response('<html>Paywall</html>', 200, ['Content-Type' => 'text/html']),
            Http::response('compressed', 200, ['Content-Type' => 'application/rss+xml', 'Content-Encoding' => 'gzip']),
        ] as $response) {
            Http::swap(new Factory);
            Http::preventStrayRequests();
            Http::fake(['*' => $response]);
            try {
                app(FeedHttpClient::class)->fetch($source);
                $this->fail('Unsafe response accepted');
            } catch (FeedFailure $error) {
                $this->assertContains($error->reason, ['feed_too_large', 'unsupported_content_type', 'compressed_feed_not_supported']);
            }
        }
    }

    public function test_redirects_are_rejected_without_contacting_the_target(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://127.0.0.1/private'])]);
        try {
            app(FeedHttpClient::class)->fetch(NewsSource::factory()->create());
            $this->fail('Redirect accepted');
        } catch (FeedFailure $error) {
            $this->assertSame('redirect_not_allowed', $error->reason);
        }
        Http::assertSentCount(1);
    }

    public function test_timeouts_and_provider_backoff_are_sanitized(): void
    {
        $source = NewsSource::factory()->create();
        Http::fake(['*' => Http::failedConnection('secret-host detail')]);
        try {
            app(FeedHttpClient::class)->fetch($source);
            $this->fail('Network failure ignored');
        } catch (FeedFailure $error) {
            $this->assertSame('network_error', $error->getMessage());
        }
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('', 429, ['Retry-After' => '7200'])]);
        try {
            app(FeedHttpClient::class)->fetch($source);
            $this->fail('Rate limit ignored');
        } catch (FeedFailure $error) {
            $this->assertSame(7200, $error->retrySeconds);
        }
    }

    public function test_xml_external_entities_and_entity_expansion_are_rejected(): void
    {
        foreach ([
            '<?xml version="1.0"?><!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><rss><channel><item><title>&xxe;</title></item></channel></rss>',
            '<!DOCTYPE rss SYSTEM "https://internal.local/dtd"><rss/>',
            "<?xml version=\"1.0\"?><rss>\0</rss>",
        ] as $xml) {
            try {
                app(FeedParser::class)->parse($xml, 'rss', 10);
                $this->fail('Unsafe XML accepted');
            } catch (FeedFailure $error) {
                $this->assertSame('unsafe_xml', $error->reason);
            }
        }
        Http::assertNothingSent();
    }

    public function test_malformed_deep_and_oversized_xml_is_bounded(): void
    {
        foreach ([
            '<rss><unclosed>', str_repeat('<nested>', 40).str_repeat('</nested>', 40),
            '<rss>'.str_repeat('<i/>', 20001).'</rss>', str_repeat('x', 1048577),
        ] as $xml) {
            try {
                app(FeedParser::class)->parse($xml, 'rss', 10);
                $this->fail('Invalid XML accepted');
            } catch (FeedFailure $error) {
                $this->assertContains($error->reason, ['invalid_xml', 'xml_structure_limit', 'feed_too_large']);
            }
        }
    }
}
