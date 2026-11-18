<?php

namespace App\Support;

use App\Models\Category;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Builder;

class NewsClassifier
{
    /** @param array<string, mixed> $item
     * @return array{topic: ?string, category_id: ?int, classification: array{method: string, matches: array<string, list<string>>, uncertain: bool}}
     */
    public function classify(array $item, NewsSource $source): array
    {
        $text = mb_strtolower($item['headline'].' '.($item['summary'] ?? ''));
        $matches = [];
        foreach (config('discovery.topics') as $topic => $rules) {
            foreach (array_slice($rules, 1) as $term) {
                $quoted = preg_quote(mb_strtolower($term), '/');
                $pattern = preg_match('/\p{Han}|\p{Hiragana}|\p{Katakana}/u', $term)
                    ? '/'.$quoted.'/u' : '/(?<![\p{L}\p{N}])'.$quoted.'(?![\p{L}\p{N}])/u';
                if (preg_match($pattern, $text)) {
                    $matches[$topic][] = $term;
                }
            }
        }
        $topic = count($matches) === 1 ? array_key_first($matches) : null;
        $method = 'keyword';
        if (! $topic && count($matches) > 1 && $source->topic && isset($matches[$source->topic])) {
            $topic = $source->topic;
        }
        if (! $matches && $source->topic) {
            $topic = $source->topic;
            $method = 'source_assignment';
        }
        $uncertain = count($matches) !== 1 || $method === 'source_assignment';
        $categoryId = $source->category_id;
        if (! $categoryId && $topic) {
            $categoryId = Category::query()->whereHas('translations', fn (Builder $query): Builder => $query->where('locale', 'en')->where('slug', $topic))->value('id');
        }

        return ['topic' => $topic, 'category_id' => $categoryId,
            'classification' => ['method' => $method, 'matches' => $matches, 'uncertain' => $uncertain]];
    }
}
