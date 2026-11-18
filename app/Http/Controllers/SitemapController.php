<?php

namespace App\Http\Controllers;

use App\Support\PublicUrl;
use App\Support\Sitemap;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SitemapController extends Controller
{
    private const HEADERS = [
        'Content-Type' => 'application/xml; charset=UTF-8',
        'X-Content-Type-Options' => 'nosniff',
    ];

    public function index(Sitemap $sitemap): StreamedResponse
    {
        $shards = $sitemap->shards();

        return response()->stream(fn () => $sitemap->index($shards), 200, self::HEADERS);
    }

    public function pages(Sitemap $sitemap): StreamedResponse
    {
        return response()->stream(fn () => $sitemap->pages(), 200, self::HEADERS);
    }

    public function articles(string $start, Sitemap $sitemap): StreamedResponse
    {
        $start = filter_var($start, FILTER_VALIDATE_INT);
        abort_unless($start !== false && $start > 0 && ($start - 1) % Sitemap::SHARD_SIZE === 0 && $start <= PHP_INT_MAX - Sitemap::SHARD_SIZE, 404);

        return response()->stream(fn () => $sitemap->articles($start), 200, self::HEADERS);
    }

    public function robots(PublicUrl $urls): Response
    {
        return response("User-agent: *\nDisallow: /admin\nSitemap: ".$urls->route('sitemap.index')."\n", 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
