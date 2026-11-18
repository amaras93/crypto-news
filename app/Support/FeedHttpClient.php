<?php

namespace App\Support;

use App\Models\NewsSource;
use GuzzleHttp\Psr7\FnStream;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class FeedHttpClient
{
    public function __construct(private FeedUrlSafety $urls) {}

    /** @return array{body: ?string, etag: ?string, last_modified: ?string, not_modified: bool} */
    public function fetch(NewsSource $source): array
    {
        $safe = $this->urls->resolve($source->feed_url);
        $limit = (int) config('discovery.max_feed_bytes');
        $stream = Utils::streamFor(fopen('php://temp', 'w+'));
        $received = 0;
        $sink = FnStream::decorate($stream, ['write' => function (string $chunk) use ($stream, &$received, $limit): int {
            if ($received + strlen($chunk) > $limit) {
                throw new FeedFailure('feed_too_large');
            }
            $written = $stream->write($chunk);
            $received += $written;

            return $written;
        }]);
        $headers = ['Accept-Encoding' => 'identity', 'User-Agent' => 'CryptoNewsFeedDiscovery/1.0'];
        foreach (['If-None-Match' => $source->etag, 'If-Modified-Since' => $source->last_modified] as $name => $value) {
            if ($this->header($value, 512)) {
                $headers[$name] = $value;
            }
        }
        $address = $safe['addresses'][0];
        $pin = $safe['host'].':443:'.(str_contains($address, ':') ? '['.$address.']' : $address);
        try {
            $response = Http::accept('application/rss+xml, application/atom+xml, application/xml, text/xml')
                ->withHeaders($headers)->connectTimeout(3)->timeout(12)->withoutRedirecting()
                ->withOptions([
                    'verify' => true, 'proxy' => '', 'protocols' => ['https'], 'decode_content' => false, 'sink' => $sink,
                    'on_headers' => function (ResponseInterface $response) use ($limit): void {
                        if (in_array($response->getStatusCode(), [429, 503], true)) {
                            throw $this->rateLimit($response->getHeaderLine('Retry-After'));
                        }
                        if ((int) $response->getHeaderLine('Content-Length') > $limit) {
                            throw new FeedFailure('feed_too_large');
                        }
                    },
                    'curl' => [CURLOPT_RESOLVE => [$pin]],
                ])->get($safe['url']);
        } catch (Throwable $exception) {
            for ($error = $exception; $error; $error = $error->getPrevious()) {
                if ($error instanceof FeedFailure) {
                    throw $error;
                }
            }
            throw new FeedFailure($exception instanceof ConnectionException ? 'network_error' : 'transport_error');
        }
        if ($response->status() === 304) {
            return ['body' => null, 'etag' => $source->etag, 'last_modified' => $source->last_modified, 'not_modified' => true];
        }
        if ($response->status() === 429 || $response->status() === 503) {
            throw $this->rateLimit($response->header('Retry-After'));
        }
        if ($response->redirect()) {
            throw new FeedFailure('redirect_not_allowed');
        }
        if (! $response->ok()) {
            throw new FeedFailure('http_error');
        }
        if ($response->header('Content-Encoding') && strtolower($response->header('Content-Encoding')) !== 'identity') {
            throw new FeedFailure('compressed_feed_not_supported');
        }
        $type = strtolower(trim(explode(';', $response->header('Content-Type'))[0]));
        if (! in_array($type, ['application/rss+xml', 'application/atom+xml', 'application/xml', 'text/xml', 'application/rdf+xml'], true)) {
            throw new FeedFailure('unsupported_content_type');
        }
        $body = $response->body();
        if (strlen($body) > $limit) {
            throw new FeedFailure('feed_too_large');
        }

        return ['body' => $body, 'etag' => $this->header($response->header('ETag'), 512),
            'last_modified' => $this->header($response->header('Last-Modified'), 128), 'not_modified' => false];
    }

    private function header(?string $value, int $max): ?string
    {
        return is_string($value) && strlen($value) <= $max && preg_match('/\A[\x20-\x7e]+\z/D', $value) ? $value : null;
    }

    private function rateLimit(string $retry): FeedFailure
    {
        $seconds = ctype_digit($retry) ? (strlen($retry) > 9 ? 604801 : (int) $retry)
            : ((strtotime($retry) ?: now()->timestamp + 3600) - now()->timestamp);
        if ($seconds > 604800) {
            return new FeedFailure('provider_polling_blocked');
        }

        return new FeedFailure('provider_rate_limited', max(60, $seconds));
    }
}
