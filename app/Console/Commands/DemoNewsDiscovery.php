<?php

namespace App\Console\Commands;

use App\Models\NewsSource;
use App\Support\FeedParser;
use App\Support\NewsClassifier;
use App\Support\NewsNormalizer;
use Illuminate\Console\Command;

class DemoNewsDiscovery extends Command
{
    protected $signature = 'news:demo';

    protected $description = 'Preview deterministic fictional RSS metadata offline without creating records';

    public function handle(FeedParser $parser, NewsNormalizer $normalizer, NewsClassifier $classifier): int
    {
        $source = new NewsSource(['name' => 'OFFLINE TEST FIXTURE', 'website' => 'https://publisher.com/',
            'feed_url' => 'https://publisher.com/feed', 'source_type' => 'rss', 'language' => 'en', 'summary_permitted' => true]);
        $items = $parser->parse(file_get_contents(base_path('tests/Fixtures/discovery-rss.xml')), 'rss', 10);
        $this->warn('OFFLINE TEST DATA — fictional metadata; no network calls or database writes.');
        $rows = [];
        foreach ($items as $entry) {
            $item = $normalizer->normalize($entry, $source);
            $classification = $classifier->classify($item, $source);
            $rows[] = [$item['headline'], $item['language'], $classification['topic'] ?? 'Uncertain / review', $item['summary'] ?? ''];
        }
        $this->table(['Fictional headline', 'Original language', 'Rule topic', 'Permitted fixture snippet'], $rows);
        $this->info('No Article, candidate, source or AI request was created.');

        return self::SUCCESS;
    }
}
