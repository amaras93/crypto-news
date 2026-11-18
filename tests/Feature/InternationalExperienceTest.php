<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Support\MarketDataService;
use App\Support\PublicUrl;
use Database\Seeders\DemoNewsSeeder;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Number;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InternationalExperienceTest extends TestCase
{
    use RefreshDatabase;

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    public static function publicLanguages(): array
    {
        return [
            'English' => ['en', 'Market report'], 'Russian' => ['ru', 'Обзор рынка'],
            'French' => ['fr', 'Analyse du marché'], 'German' => ['de', 'Marktbericht'],
            'Spanish' => ['es', 'Informe del mercado'], 'Italian' => ['it', 'Rapporto sui mercati'],
            'Japanese' => ['ja', '市場レポート'], 'Chinese' => ['zh', '市场报告'],
        ];
    }

    #[DataProvider('publicLanguages')]
    public function test_public_home_and_listing_are_localized_without_creating_article_translations(string $locale, string $headline): void
    {
        Http::preventStrayRequests();
        $expected = ['en', 'ru', 'fr', 'de', 'es', 'it', 'ja', 'zh'];
        $this->assertSame($expected, array_keys(config('news.locales')));
        foreach (['/'.$locale, '/'.$locale.'/news'] as $path) {
            $response = $this->get($path)->assertOk()
                ->assertSee('<html lang="'.config('news.language_tags.'.$locale).'">', false)
                ->assertSee(trans('market.crypto_ticker', [], $locale))
                ->assertSee(trans('market.metals_ticker', [], $locale))
                ->assertSee(trans('ui.precious_metals', [], $locale))
                ->assertDontSee('hreflang="hy"', false)
                ->assertDontSee('market.assets.', false);
            $xpath = $this->dom($response->getContent());
            $this->assertSame(8, $xpath->query('//details[contains(@class,"language-menu")]//a')->length);
            $this->assertSame(config('news.graph_locales.'.$locale), $xpath->evaluate('string(//meta[@property="og:locale"]/@content)'));
            $this->assertSame(2, $xpath->query('//section[@data-market-ticker]')->length);
        }
        $this->assertDatabaseCount('article_translations', 0);
        $this->get('/'.$locale.'/news?q[]=invalid')->assertUnprocessable()
            ->assertSee(trans('ui.invalid_filters', [], $locale));
        Http::assertNothingSent();
    }

    #[DataProvider('publicLanguages')]
    public function test_real_article_translation_urls_schema_and_language_fallbacks_are_correct(string $locale, string $headline): void
    {
        $article = Article::factory()->published()->create();
        $translation = ArticleTranslation::factory()->for($article)->create(['locale' => $locale, 'title' => $headline, 'slug' => 'actual-'.$locale, 'body' => $headline]);
        if ($locale !== 'en') {
            ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'slug' => 'actual-english']);
        }
        ArticleTranslation::factory()->for($article)->create(['locale' => 'hy', 'slug' => 'archived-armenian']);
        $response = $this->get('/'.$locale.'/news/actual-'.$locale)->assertOk()->assertSee($headline);
        $xpath = $this->dom($response->getContent());
        $tag = config('news.language_tags.'.$locale);
        $this->assertSame(app(PublicUrl::class)->article($translation), $xpath->evaluate('string(//link[@rel="canonical"]/@href)'));
        $this->assertSame(1, $xpath->query('//head/link[@hreflang="'.$tag.'"]')->length);
        $this->assertSame(0, $xpath->query('//head/link[@hreflang="hy"]')->length);
        $this->assertSame(8, $xpath->query('//details[contains(@class,"language-menu")]//a')->length);
        $schemas = $xpath->query('//script[@type="application/ld+json"]');
        $news = json_decode($schemas->item(0)->textContent, true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame($tag, $news['inLanguage']);
        $this->assertSame('NewsArticle', $news['@type']);
        foreach (array_keys(config('news.locales')) as $target) {
            if (in_array($target, [$locale, 'en'], true)) {
                continue;
            }
            $response->assertSee('href="'.route('news.index', ['locale' => $target]).'"', false);
            $this->assertSame(0, $xpath->query('//head/link[@hreflang="'.config('news.language_tags.'.$target).'"]')->length);
        }
        $this->assertDatabaseHas('article_translations', ['article_id' => $article->id, 'locale' => 'hy']);
    }

    public function test_direct_missing_translation_is_a_localized_404_and_never_an_english_copy(): void
    {
        $article = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'slug' => 'english-only', 'body' => 'Exclusive untranslated source paragraph.']);
        $this->get('/fr/news/english-only')->assertNotFound()
            ->assertSee('Page introuvable')
            ->assertSee('noindex,follow', false)
            ->assertDontSee('Exclusive untranslated source paragraph.')
            ->assertSee('href="'.route('news.index', ['locale' => 'fr']).'"', false);
    }

    public function test_future_public_translation_rules_accept_only_public_locales_without_restricting_archival_storage(): void
    {
        foreach ([ArticleTranslation::class, CategoryTranslation::class] as $model) {
            foreach (array_keys(config('news.locales')) as $locale) {
                $this->assertTrue(Validator::make(['locale' => $locale], ['locale' => $model::publicLocaleRules()])->passes());
            }
            foreach (['hy', 'pt', 'zh-Hans', '../en'] as $unsupported) {
                $this->assertTrue(Validator::make(['locale' => $unsupported], ['locale' => $model::publicLocaleRules()])->fails());
            }
        }
        $legacy = ArticleTranslation::factory()->create(['locale' => 'hy']);
        $this->assertDatabaseHas('article_translations', ['id' => $legacy->id, 'locale' => 'hy']);
    }

    public function test_legacy_armenian_redirects_preserve_all_stored_content_and_never_invent_slugs(): void
    {
        $article = Article::factory()->published()->create();
        $archived = ArticleTranslation::factory()->for($article)->create([
            'locale' => 'hy', 'slug' => 'հին-հոդված', 'title' => 'Հայերեն պահպանված վերնագիր',
            'body' => 'Պահպանված բովանդակություն։', 'summary' => ['Առաջին', 'Երկրորդ', 'Երրորդ'],
        ]);
        $english = ArticleTranslation::factory()->for($article)->create(['locale' => 'en', 'slug' => 'existing-english']);
        $original = $archived->fresh()->getRawOriginal();
        $this->get('/hy')->assertStatus(301)->assertRedirect('/en');
        $this->get('/hy/news')->assertStatus(301)->assertRedirect('/en/news');
        $this->get('/hy/news/հին-հոդված')->assertStatus(301)->assertRedirect(app(PublicUrl::class)->article($english));
        $this->get('/en/news/existing-english')->assertOk()->assertDontSee('hreflang="hy"', false);
        $this->assertSame($original, $archived->fresh()->getRawOriginal());
        $this->assertDatabaseCount('article_translations', 2);

        $onlyArchived = Article::factory()->published()->create();
        ArticleTranslation::factory()->for($onlyArchived)->create(['locale' => 'hy', 'slug' => 'only-archived']);
        $this->get('/hy/news/only-archived')->assertStatus(301)
            ->assertRedirect(app(PublicUrl::class)->route('news.index', ['locale' => 'en']));
        $this->get('/hy/news/nonexistent')->assertNotFound();
        $draft = Article::factory()->create();
        ArticleTranslation::factory()->for($draft)->create(['locale' => 'hy', 'slug' => 'draft-archive']);
        $this->get('/hy/news/draft-archive')->assertNotFound();
    }

    public function test_new_demo_seeding_does_not_create_or_overwrite_armenian_or_copy_other_languages(): void
    {
        $article = Article::factory()->published()->create();
        $archived = ArticleTranslation::factory()->for($article)->create(['locale' => 'hy', 'slug' => 'demo-bitcoin-long-view-hy', 'title' => 'User-owned Armenian headline']);
        $original = $archived->fresh()->getRawOriginal();
        $this->seed(DemoNewsSeeder::class);
        $this->assertSame($original, $archived->fresh()->getRawOriginal());
        $this->assertSame(1, ArticleTranslation::where('locale', 'hy')->count());
        $this->assertSame(0, ArticleTranslation::whereIn('locale', ['fr', 'de', 'es', 'it', 'ja', 'zh'])->count());
        $this->assertSame(0, ArticleTranslation::where('slug', 'demo-bitcoin-long-view-en')->count());
    }

    public function test_all_language_catalogs_have_complete_keys_placeholders_and_valid_unicode(): void
    {
        foreach (['messages', 'ui', 'paper', 'article', 'market', 'pagination'] as $catalog) {
            $english = Arr::dot(require lang_path('en/'.$catalog.'.php'));
            foreach (array_keys(config('news.locales')) as $locale) {
                $values = Arr::dot(require lang_path($locale.'/'.$catalog.'.php'));
                $this->assertSame(array_keys($english), array_keys($values), $locale.'/'.$catalog.' keys');
                foreach ($english as $key => $source) {
                    $this->assertIsString($values[$key], $locale.'/'.$catalog.'.'.$key);
                    $this->assertNotSame('', trim($values[$key]));
                    $this->assertSame(1, preg_match('//u', $values[$key]));
                    preg_match_all('/:[a-zA-Z_][a-zA-Z_0-9]*/', $source, $expected);
                    preg_match_all('/:[a-zA-Z_][a-zA-Z_0-9]*/', $values[$key], $actual);
                    $expected[0] = array_unique($expected[0]);
                    $actual[0] = array_unique($actual[0]);
                    sort($expected[0]);
                    sort($actual[0]);
                    $this->assertSame($expected[0], $actual[0], $locale.'/'.$catalog.'.'.$key.' placeholders');
                }
            }
        }
    }

    public function test_sitemaps_include_exactly_public_translations_and_exclude_preserved_armenian(): void
    {
        $article = Article::factory()->published()->create();
        $urls = [];
        foreach (array_keys(config('news.locales')) as $locale) {
            $translation = ArticleTranslation::factory()->for($article)->create(['locale' => $locale, 'slug' => 'article-'.$locale]);
            $urls[] = app(PublicUrl::class)->article($translation);
        }
        $archive = ArticleTranslation::factory()->for($article)->create(['locale' => 'hy', 'slug' => 'kept-in-database']);
        $response = $this->get('/sitemap-articles-1.xml')->assertOk();
        $xml = simplexml_load_string($response->streamedContent());
        $this->assertNotFalse($xml);
        $xml->registerXPathNamespace('s', 'http://www.sitemaps.org/schemas/sitemap/0.9');
        $locations = array_map(fn ($node): string => (string) $node, $xml->xpath('//s:loc'));
        $this->assertSame($urls, $locations);
        foreach ($urls as $url) {
            $this->assertStringContainsString(htmlspecialchars($url, ENT_QUOTES | ENT_XML1, 'UTF-8'), $response->streamedContent());
        }
        $this->assertStringNotContainsString('/hy/', $response->streamedContent());
        $this->assertDatabaseHas('article_translations', ['id' => $archive->id]);
        $pages = $this->get('/sitemap-pages.xml')->assertOk()->streamedContent();
        $this->assertSame(16, substr_count($pages, '<url>'));
        $this->assertStringNotContainsString('/hy', $pages);
    }

    public function test_crypto_and_metals_dropdowns_filter_real_categories_and_handle_missing_ones(): void
    {
        foreach (['bitcoin', 'gold', 'silver', 'markets'] as $topic) {
            $category = Category::factory()->has(CategoryTranslation::factory()->state(['locale' => 'en', 'slug' => $topic, 'name' => ucfirst($topic)]), 'translations')->create();
            Article::factory()->published()->for($category)->has(ArticleTranslation::factory()->state(['locale' => 'fr', 'title' => 'Actual '.$topic]), 'translations')->create();
        }
        $response = $this->get('/fr')->assertOk();
        $xpath = $this->dom($response->getContent());
        $this->assertSame(2, $xpath->query('//nav[contains(@class,"paper-desktop-nav")]/details[@data-dismissable]')->length);
        $this->assertSame(2, $xpath->query('//nav[contains(@class,"paper-desktop-nav")]/details/summary')->length);
        $this->assertGreaterThan(0, $xpath->query('//span[@aria-disabled="true" and contains(.,"Platine")]')->length);
        $response->assertSee('Toutes les actualités crypto')->assertSee('Toutes les actualités des métaux');
        $this->get('/fr/news?topic=crypto')->assertOk()->assertSee('Actual bitcoin')->assertDontSee('Actual gold')->assertDontSee('Actual markets');
        $this->get('/fr/news?topic=metals')->assertOk()->assertSee('Actual gold')->assertSee('Actual silver')->assertDontSee('Actual bitcoin');
        $this->assertDatabaseCount('category_translations', 4);
        $this->get('/fr/news?topic=crypto&category[]=bad')->assertUnprocessable();
    }

    #[DataProvider('publicLanguages')]
    public function test_full_demo_tickers_render_twenty_crypto_and_five_metals_in_every_edition(string $locale, string $headline): void
    {
        config(['markets.mode' => 'demo']);
        Http::preventStrayRequests();
        $snapshot = collect(app(MarketDataService::class)->snapshot()['quotes'])->keyBy('asset');
        $cryptoSymbols = ['BTC', 'ETH', 'SOL', 'BNB', 'XRP', 'ADA', 'DOGE', 'TRX', 'AVAX', 'LINK', 'TON', 'DOT', 'LTC', 'BCH', 'XLM', 'NEAR', 'UNI', 'SUI', 'APT', 'ETC'];
        $metalSymbols = ['XAU/USD', 'XAG/USD', 'XPT/USD', 'XPD/USD', 'HG'];
        $this->assertCount(25, $snapshot);
        $this->assertCount(25, config('markets.demo'));
        $this->assertCount(25, collect(config('markets.demo'))->unique('name'));
        $this->assertSame([...$cryptoSymbols, ...$metalSymbols], $snapshot->pluck('symbol')->all());
        foreach ($snapshot as $asset => $quote) {
            $this->assertGreaterThan(0, $quote->price);
            $this->assertSame('demo', $quote->status);
            $this->assertSame('demo', $quote->provider);
            $this->assertSame('USD', $quote->currency);
            $this->assertNull($quote->providerTimestamp);
            $this->assertNull($quote->fetchedAt);
            $this->assertSame($asset === 'copper' ? 'pound' : (in_array($quote->symbol, $metalSymbols, true) ? 'troy_ounce' : 'coin'), $quote->unit);
            $fixture = collect(config('markets.demo'))->firstWhere('name', $asset);
            $this->assertSame((float) $fixture['price'], $quote->price);
            $this->assertSame((float) $fixture['change'], $quote->change24h);
        }
        Article::factory()->published()->has(ArticleTranslation::factory()->state(['locale' => $locale]), 'translations')->create();
        foreach (['/'.$locale, '/'.$locale.'/news'] as $url) {
            $response = $this->get($url)->assertOk()->assertSee(trans('market.demo_label', [], $locale));
            $xpath = $this->dom($response->getContent());
            $this->assertSame(20, $xpath->query('//section[@data-ticker-group="crypto"]//div[@data-ticker-copy="original"]/div[@data-ticker-asset]')->length);
            $this->assertSame(5, $xpath->query('//section[@data-ticker-group="metals"]//div[@data-ticker-copy="original"]/div[@data-ticker-asset]')->length);
            $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//button')->length);
            $this->assertSame(2, $xpath->query('//div[@data-ticker-copy="clone" and @aria-hidden="true" and @inert]')->length);
            $this->assertSame(2, $xpath->query('//section[@data-market-ticker]/div[@role="region" and @tabindex="0"]')->length);
            $items = '//div[@data-ticker-copy="original"]/div[@data-ticker-asset]';
            $this->assertSame(25, $xpath->query($items.'/img[@width="20" and @height="20" and @aria-hidden="true" and @alt="" and @data-market-logo]')->length);
            $this->assertSame(25, $xpath->query($items.'[@role="group" and @aria-label and @title]')->length);
            $this->assertSame(4, $xpath->query('//section[@data-ticker-group="metals"]'.$items.'//small[@class="sr-only"]')->length);
            $this->assertSame(trans('market.usd_pound', [], $locale), $xpath->evaluate('string('.$items.'[@data-ticker-asset="copper"]//small[@class="market-ticker-unit"])'));
            $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//p[@role="status"]')->length);
            $this->assertStringNotContainsString('market.assets.', $response->getContent());
            if ($url === '/'.$locale) {
                $this->assertSame(
                    $xpath->evaluate('string('.$items.'[@data-ticker-asset="bitcoin"]//span[@class="market-price-number"])'),
                    $xpath->evaluate('string(//div[@data-market-asset="bitcoin"]//span[@class="market-price-number"])'),
                );
            }
        }
        $this->assertSame('demo', app(MarketDataService::class)->refresh('crypto')['status']);
        $this->assertSame('demo', app(MarketDataService::class)->refresh('metals')['status']);
        Http::assertNothingSent();
    }

    #[DataProvider('publicLanguages')]
    public function test_empty_live_tickers_have_one_localized_state_outside_any_moving_sequence(string $locale, string $headline): void
    {
        config(['markets.mode' => 'live', 'markets.rights_confirmed' => true]);
        Http::preventStrayRequests();
        $response = $this->get('/'.$locale)->assertOk();
        $xpath = $this->dom($response->getContent());
        $this->assertSame(2, $xpath->query('//section[@data-market-ticker]/p[@role="status"]')->length);
        foreach ($xpath->query('//section[@data-market-ticker]/p[@role="status"]') as $state) {
            $this->assertSame(trans('market.unavailable', [], $locale), trim($state->textContent));
        }
        $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//div[@data-ticker-copy]')->length);
        $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//button')->length);
        $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//div[@tabindex]')->length);
        Http::assertNothingSent();
    }

    public function test_partial_cached_live_quotes_show_only_usable_assets_and_keep_stale_information_accessible(): void
    {
        config(['markets.mode' => 'live', 'markets.rights_confirmed' => true, 'markets.coingecko.key' => 'not-a-real-test-key']);
        Http::preventStrayRequests();
        Http::fake(['api.coingecko.com/*' => Http::response([
            'bitcoin' => ['usd' => 90000, 'usd_24h_change' => -2.34, 'last_updated_at' => now()->timestamp],
            'ripple' => ['usd' => 0.123456, 'usd_24h_change' => 1.25, 'last_updated_at' => now()->timestamp],
            'cardano' => ['usd' => 0, 'usd_24h_change' => 0, 'last_updated_at' => now()->timestamp],
        ])]);
        $this->assertSame(2, app(MarketDataService::class)->refresh('crypto')['updated']);
        config(['markets.mode' => 'demo']);
        $this->assertCount(25, array_filter(app(MarketDataService::class)->snapshot()['quotes'], fn ($quote) => $quote->status === 'demo'));
        config(['markets.mode' => 'live']);
        $liveQuotes = app(MarketDataService::class)->snapshot()['quotes'];
        $this->assertCount(2, array_filter($liveQuotes, fn ($quote) => $quote->price !== null));
        foreach ($liveQuotes as $quote) {
            $this->assertNotSame('demo', $quote->provider);
            $this->assertNotSame('demo', $quote->status);
            if (! in_array($quote->asset, ['bitcoin', 'xrp'], true)) {
                $this->assertNull($quote->price, $quote->asset.' must never receive a DEMO fallback in LIVE mode');
            }
        }
        $this->travel(31)->minutes();
        $response = $this->get('/en')->assertOk();
        $xpath = $this->dom($response->getContent());
        $items = '//section[@data-ticker-group="crypto"]//div[@data-ticker-copy="original"]/div[@data-ticker-asset]';
        $this->assertSame(2, $xpath->query($items)->length);
        $this->assertSame(1, $xpath->query($items.'[@data-ticker-asset="xrp"]/img[@data-market-logo]')->length);
        $this->assertSame(0, $xpath->query($items.'[@data-ticker-asset="cardano"]')->length);
        $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//*[@data-ticker-asset="platinum" or @data-ticker-asset="palladium" or @data-ticker-asset="copper" or @data-ticker-asset="toncoin"]')->length);
        $this->assertStringNotContainsString(trans('market.demo_label'), $xpath->evaluate('string(//section[@data-ticker-group="crypto"])'));
        $this->assertSame(2, $xpath->query($items.'/span[@class="sr-only"]//time[@datetime]')->length);
        $this->assertStringContainsString(trans('market.stale'), $xpath->evaluate('string(//section[@data-ticker-group="crypto"]/div[@class="market-ticker-label"])'));
        $this->assertSame(1, $xpath->query($items.'/span[contains(@class,"negative")]')->length);
        $this->assertSame(1, $xpath->query($items.'/span[contains(@class,"positive")]')->length);
        $this->assertStringNotContainsString(trans('market.unavailable'), $xpath->evaluate('string(//section[@data-ticker-group="crypto"]//div[@class="market-ticker-track"])'));
        $response->assertSee('0.123456');
        Http::assertSentCount(1);
    }

    public function test_market_icons_are_local_safe_images_with_a_neutral_fallback(): void
    {
        $this->assertCount(25, config('markets.icons'));
        $this->assertSame('images/markets/neutral.svg', config('markets.icons.cardano'));
        foreach (config('markets.icons') as $asset => $path) {
            $this->assertMatchesRegularExpression('/\Aimages\/markets\/[a-z0-9-]+\.(?:png|svg)\z/D', $path);
            $this->assertFileExists(public_path($path));
            $html = (string) $this->blade('<x-market-asset-icon :asset="$asset" />', ['asset' => $asset]);
            $xpath = $this->dom($html);
            $this->assertSame(asset($path), $xpath->evaluate('string(//img/@src)'));
            $this->assertSame(1, $xpath->query('//img[@width="20" and @height="20" and @alt="" and @data-market-logo and @data-fallback]')->length);
            if (str_ends_with($path, '.png')) {
                $this->assertSame(IMAGETYPE_PNG, getimagesize(public_path($path))[2]);
            } else {
                $svg = file_get_contents(public_path($path));
                $this->assertDoesNotMatchRegularExpression('/<!DOCTYPE|<!ENTITY|<script|<foreignObject|\bon[a-z]+\s*=|(?:href|src)\s*=|javascript:|data:/i', $svg);
                $this->assertNotFalse(simplexml_load_string($svg, options: LIBXML_NONET));
            }
        }
        foreach (['images/markets/missing.svg', '../private.svg', 'https://untrusted.invalid/icon.svg'] as $path) {
            config(['markets.icons.bitcoin' => $path]);
            $this->blade('<x-market-asset-icon asset="bitcoin" />')
                ->assertSee('src="'.asset('images/markets/neutral.svg').'"', false)
                ->assertDontSee('untrusted.invalid');
        }
        $this->blade('<x-market-asset-icon asset="unlisted" />')
            ->assertSee('src="'.asset('images/markets/neutral.svg').'"', false);
    }

    public function test_five_live_metal_quotes_share_cache_and_copper_keeps_its_distinct_unit_after_a_bad_refresh(): void
    {
        config(['markets.mode' => 'live', 'markets.rights_confirmed' => true, 'markets.goldapi.key' => 'not-a-real-test-key']);
        Http::preventStrayRequests();
        $copperUnit = 'lb';
        Http::fake(function (Request $request) use (&$copperUnit) {
            $symbol = basename(dirname(parse_url($request->url(), PHP_URL_PATH)));

            return Http::response(['symbol' => $symbol, 'currency' => 'USD', 'unit' => $symbol === 'HG' ? $copperUnit : 'oz',
                'price' => $symbol === 'HG' ? 4.125 : 1234.56, 'updatedAt' => now()->toIso8601String(), 'chPercent' => 7]);
        });
        $markets = app(MarketDataService::class);
        $this->assertSame(5, $markets->refresh('metals')['updated']);
        $original = collect($markets->snapshot()['quotes'])->firstWhere('asset', 'copper');
        foreach (array_keys(config('news.locales')) as $locale) {
            $response = $this->get('/'.$locale)->assertOk();
            $xpath = $this->dom($response->getContent());
            $items = '//section[@data-ticker-group="metals"]//div[@data-ticker-copy="original"]/div[@data-ticker-asset]';
            $this->assertSame(5, $xpath->query($items)->length);
            $this->assertSame(0, $xpath->query('//section[@data-market-ticker]//button')->length);
            $this->assertSame(trans('market.usd_pound', [], $locale), $xpath->evaluate('string('.$items.'[@data-ticker-asset="copper"]//small[@class="market-ticker-unit"])'));
            $this->assertSame(4, $xpath->query($items.'//small[@class="sr-only"]')->length);
            $this->assertSame(1, $xpath->query('//section[@data-ticker-group="metals"]/div[@class="market-ticker-label"]//time[@datetime]')->length);
            $this->assertSame(0, $xpath->query($items.'/span[contains(@class,"positive") or contains(@class,"negative")]')->length);
        }
        Http::assertSentCount(5);
        $this->travel(30)->minutes();
        $copperUnit = 'oz';
        $this->assertSame('partial', $markets->refresh('metals')['status']);
        $copper = collect($markets->snapshot()['quotes'])->firstWhere('asset', 'copper');
        $this->assertSame('pound', $copper->unit);
        $this->assertSame($original->price, $copper->price);
        $this->assertSame($original->providerTimestamp, $copper->providerTimestamp);
        $this->assertSame('delayed', $copper->status);
        Http::assertSentCount(10);
    }

    public function test_new_crypto_assets_use_the_same_batch_cache_and_accurate_sub_dollar_formatting(): void
    {
        config(['markets.mode' => 'live', 'markets.rights_confirmed' => true, 'markets.coingecko.key' => 'not-a-real-test-key']);
        Http::preventStrayRequests();
        Http::fake(function (Request $request) {
            $body = [];
            foreach (config('markets.assets') as $asset => $definition) {
                if ($definition['group'] === 'crypto') {
                    $body[$definition['provider_id']] = ['usd' => $asset === 'xrp' ? 0.123456 : 12, 'usd_24h_change' => null, 'last_updated_at' => now()->timestamp];
                }
            }

            return Http::response($body);
        });
        $markets = app(MarketDataService::class);
        $this->assertSame(20, $markets->refresh('crypto')['updated']);
        foreach (['/en', '/de', '/ja', '/zh/news'] as $path) {
            $locale = explode('/', trim($path, '/'))[0];
            $response = $this->get($path)->assertOk()->assertSee(Number::format(0.123456, 6, locale: $locale));
            $xpath = $this->dom($response->getContent());
            $this->assertSame(20, $xpath->query('//section[@data-ticker-group="crypto"]//div[@data-ticker-copy="original"]/div[@data-ticker-asset]')->length);
        }
        Http::assertSentCount(1);
        $this->assertSame('demo', config('markets.coingecko.tier'));
        $this->travel(31)->minutes();
        $this->get('/de')->assertOk()->assertSee(trans('market.stale', [], 'de'));
        Http::assertSentCount(1);
    }
}
