<?php

namespace Tests\Feature;

use App\Models\NewsSource;
use App\Support\FeedFailure;
use App\Support\FeedParser;
use App\Support\NewsClassifier;
use App\Support\NewsNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FeedParsingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-17 08:00:00 UTC'));
    }

    public function test_rss_fixture_extracts_only_bounded_metadata_not_full_content(): void
    {
        $items = app(FeedParser::class)->parse(file_get_contents(base_path('tests/Fixtures/discovery-rss.xml')), 'rss', 2);
        $this->assertCount(2, $items);
        $this->assertSame('test-bitcoin-001', $items[0]['id']);
        $this->assertStringContainsString('TEST FIXTURE', $items[0]['title']);
        $this->assertArrayNotHasKey('body', $items[0]);
        $this->assertSame('https://publisher.com/news/fictional-gold', $items[1]['url']);
    }

    public function test_atom_extracts_namespace_links_original_languages_and_dates(): void
    {
        $items = app(FeedParser::class)->parse(file_get_contents(base_path('tests/Fixtures/discovery-atom.xml')), 'atom', 20);
        $this->assertCount(2, $items);
        $this->assertSame('fr', $items[0]['language']);
        $this->assertSame('ja', $items[1]['language']);
        $this->assertSame('https://publisher.com/news/fictional-ethereum', $items[0]['url']);
        $this->assertSame('2026-11-16T11:00:00Z', $items[1]['published']);
    }

    public function test_summary_requires_explicit_rights_and_unsafe_markup_never_survives(): void
    {
        $source = NewsSource::factory()->make();
        $entry = ['id' => 'one', 'title' => '<b>Bitcoin</b><script>alert(1)</script>',
            'url' => 'https://publisher.com/story?utm_source=rss&a=1', 'summary' => '<p>Licensed snippet</p>', 'published' => '2026-11-16T10:00:00Z'];
        $without = app(NewsNormalizer::class)->normalize($entry, $source);
        $this->assertNull($without['summary']);
        $this->assertStringNotContainsString('<', $without['headline']);
        $source->summary_permitted = true;
        $with = app(NewsNormalizer::class)->normalize($entry, $source);
        $this->assertSame('Licensed snippet', $with['summary']);
        $this->assertSame('en', $with['language']);
        $this->assertSame('2026-11-16T10:00:00+00:00', $with['published_at']->toIso8601String());
    }

    public function test_normalized_urls_preserve_semantic_query_values_and_remove_tracking_only(): void
    {
        $normalizer = app(NewsNormalizer::class);
        $this->assertSame('https://publisher.com/story?a=1&lang=fr', $normalizer->normalizedUrl('https://publisher.com:443/story?utm_source=rss&lang=fr&a=1#top'));
        $this->assertNotSame($normalizer->normalizedUrl('https://publisher.com/story?id=1'), $normalizer->normalizedUrl('https://publisher.com/story?id=2'));
        $this->assertNotSame($normalizer->normalizedUrl('https://publisher.com/story?id=1&id=2'), $normalizer->normalizedUrl('https://publisher.com/story?id=2&id=1'));
        $this->assertSame('résumé bitcoin 123', $normalizer->headline('RÉSUMÉ — Bitcoin: 123!'));
    }

    public function test_future_invalid_and_missing_timestamps_are_uncertain_not_invented(): void
    {
        $source = NewsSource::factory()->make();
        foreach (['tomorrow', 'yesterday', '2026-02-31T10:00:00Z', 'Fri, 16 Nov 2026 08:00:00 +0000', 'not-a-date', null] as $date) {
            $item = app(NewsNormalizer::class)->normalize(['title' => 'Bitcoin article', 'url' => 'https://publisher.com/story', 'published' => $date], $source);
            $this->assertNull($item['published_at']);
            $this->assertNotEmpty($item['warnings']);
        }
    }

    public function test_foreign_and_unsafe_entry_links_are_not_saved(): void
    {
        $source = NewsSource::factory()->make();
        foreach (['javascript:alert(1)', 'https://other-publisher.com/story', 'https://127.0.0.1/private'] as $url) {
            try {
                app(NewsNormalizer::class)->normalize(['title' => 'Untrusted entry', 'url' => $url], $source);
                $this->fail('Unsafe link accepted');
            } catch (FeedFailure $error) {
                $this->assertContains($error->reason, ['unsafe_url', 'unsafe_host', 'foreign_article_host']);
            }
        }
    }

    public function test_classification_is_explainable_and_ambiguous_or_unknown_topics_require_review(): void
    {
        $classifier = app(NewsClassifier::class);
        $source = NewsSource::factory()->make();
        $bitcoin = $classifier->classify(['headline' => 'Bitcoin custody study', 'summary' => null], $source);
        $this->assertSame('bitcoin', $bitcoin['topic']);
        $this->assertSame(['bitcoin'], $bitcoin['classification']['matches']['bitcoin']);
        $this->assertFalse($bitcoin['classification']['uncertain']);
        $unknown = $classifier->classify(['headline' => 'A general editorial briefing'], $source);
        $this->assertNull($unknown['topic']);
        $this->assertTrue($unknown['classification']['uncertain']);
        $mixed = $classifier->classify(['headline' => 'Gold and Bitcoin demand'], $source);
        $this->assertNull($mixed['topic']);
        $this->assertTrue($mixed['classification']['uncertain']);
        $this->assertCount(21, config('discovery.topics'));
    }

    public function test_multilingual_keywords_and_source_hints_preserve_uncertainty(): void
    {
        $classifier = app(NewsClassifier::class);
        $source = NewsSource::factory()->make();
        $this->assertSame('bitcoin', $classifier->classify(['headline' => '比特币研究'], $source)['topic']);
        $this->assertSame('ethereum', $classifier->classify(['headline' => 'イーサリアムの調査'], $source)['topic']);
        $source->topic = 'regulation';
        $hint = $classifier->classify(['headline' => 'A regional policy update'], $source);
        $this->assertSame('source_assignment', $hint['classification']['method']);
        $this->assertTrue($hint['classification']['uncertain']);
    }

    public function test_source_type_mismatches_are_rejected(): void
    {
        $this->expectExceptionMessage('unexpected_feed_format');
        app(FeedParser::class)->parse(file_get_contents(base_path('tests/Fixtures/discovery-atom.xml')), 'rss', 10);
    }

    public function test_explicit_canonical_links_and_inherited_feed_language_are_preserved(): void
    {
        $source = NewsSource::factory()->make();
        $normalizer = app(NewsNormalizer::class);
        $one = $normalizer->normalize(['title' => 'Actual headline', 'url' => 'https://publisher.com/tracking?id=1', 'canonical' => 'https://publisher.com/original'], $source);
        $two = $normalizer->normalize(['title' => 'Actual headline', 'url' => 'https://publisher.com/original'], $source);
        $this->assertSame($one['url_hash'], $two['url_hash']);
        $xml = str_replace('<entry xml:lang="fr">', '<entry>', file_get_contents(base_path('tests/Fixtures/discovery-atom.xml')));
        $items = app(FeedParser::class)->parse($xml, 'atom', 10);
        $this->assertSame('fr', $items[0]['language']);
    }
}
