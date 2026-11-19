<?php

namespace App\Support;

use App\Models\AiGenerationRun;
use App\Models\ArticleTranslation;
use Illuminate\Support\Str;

class AiQuality
{
    public function document(array $document, AiGenerationRun $run): array
    {
        $text = implode(' ', [$document['headline'], $document['excerpt'], $document['seo_title'], $document['seo_description'], ...array_column($document['sections'], 'heading'), ...$document['takeaways'],
            ...collect($document['sections'])->flatMap(fn (array $section): array => array_column($section['paragraphs'], 'text'))->all()]);
        $report = $this->text($text, $run->evidence_snapshot);
        if ($run->is_automated) {
            $report['human_review_required'] = false;
            $report['warnings'] = ['Separate automated semantic verification is required before this content can be published.'];
            $translation = new ArticleTranslation(['article_id' => $run->article_id, 'locale' => $run->locale,
                'title' => $document['headline'], 'slug' => $document['slug'], 'meta_title' => $document['seo_title'],
                'meta_description' => $document['seo_description'],
                'body' => app(AiOutputSchema::class)->body($document, $run->evidence_snapshot)]);
            $report['critical'] = array_merge($report['critical'], app(ArticleSeo::class)->automaticIssues($translation));
        }
        $script = match ($run->locale) {
            'ru' => '/\p{Cyrillic}/u', 'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u',
            'zh' => '/\p{Han}/u', default => '/\p{Latin}/u',
        };
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 30 || preg_match_all($script, $text) / $letters < 0.45) {
            $report['critical'][] = 'language_script_mismatch';
        }
        $report['critical'] = array_merge($report['critical'], $this->provenanceIssues($document['slug'].' '.implode(' ', $document['suggested_tags']), $run->evidence_snapshot));
        $allowedEntities = $this->allowedEntities($run->evidence_snapshot);
        foreach ($document['entities'] as $entity) {
            if (! in_array($entity, $allowedEntities, true)) {
                $report['critical'][] = 'unsupported_entity:'.$entity;
            }
        }
        if ($document['unsupported_claim_warnings']) {
            $report['critical'][] = 'model_reported_unsupported_claims';
        }
        if (preg_match('/conflict|contradict|unverif|disput|unsupported prediction/i', implode(' ', $document['editorial_notes']))) {
            $report['critical'][] = 'reported_conflicting_or_unverified_information';
        }
        $report['claim_links'] = collect($document['sections'])->flatMap(fn (array $section): array => array_map(
            fn (array $paragraph): array => ['text_digest' => hash('sha256', $paragraph['text']), 'claim_ids' => $paragraph['claim_ids']],
            $section['paragraphs'],
        ))->all();
        foreach ($document['sections'] as $section) {
            foreach ($section['paragraphs'] as $paragraph) {
                $citedClaims = array_values(array_filter($run->evidence_snapshot['claims'], fn (array $claim): bool => in_array($claim['id'], $paragraph['claim_ids'], true)));
                foreach ($this->text($paragraph['text'], ['claims' => $citedClaims])['critical'] as $failure) {
                    $report['critical'][] = 'claim_mismatch:'.$failure;
                }
            }
        }
        if ($run->task === 'translation' && $run->input_payload) {
            $original = $run->input_payload['english_text'] ?? '';
            if ($this->numbers($this->normalizeCalendarMonths($text, $run->evidence_snapshot)) !== $this->numbers($original)) {
                $report['critical'][] = 'translation_numbers_or_dates_changed';
            }
            $mentions = collect($document['entity_mentions'] ?? [])->keyBy('canonical');
            foreach ($run->input_payload['preserved_entities'] ?? [] as $entity) {
                $mention = $run->schema_version === 'translation-v2' ? ($mentions->get($entity)['localized'] ?? '') : $entity;
                if ($mention === '' || ! str_contains(mb_strtolower($text), mb_strtolower($mention))) {
                    $report['critical'][] = 'translation_entity_missing:'.$entity;
                }
            }
        }
        $report['critical'] = array_values(array_unique($report['critical']));
        $report['originality'] = $this->originalityPreflight($document, $run->evidence_snapshot);

        return $report;
    }

    public function saved(ArticleTranslation $translation, AiGenerationRun $run): array
    {
        $text = implode(' ', [$translation->title, $translation->excerpt ?? '', $translation->meta_title ?? '', $translation->meta_description ?? '', ...($translation->summary ?? []),
            app(ArticleContent::class)->render($translation->body)['text']]);
        $report = $this->text($text, $run->evidence_snapshot);
        $report['critical'] = array_merge($report['critical'], $this->provenanceIssues($translation->slug, $run->evidence_snapshot));
        if ($run->is_automated) {
            $report['critical'] = array_merge($report['critical'], app(ArticleSeo::class)->automaticIssues($translation));
        }
        $allowed = collect($run->evidence_snapshot['claims'])->flatMap(fn (array $claim): array => array_column($claim['references'], 'url'))->unique()->all();
        preg_match_all('/<a\b[^>]*href="([^"]+)"/iu', $translation->body, $links);
        $cited = array_map(fn (string $url): string => html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $links[1]);
        if (! array_intersect($cited, $allowed) || array_diff($cited, $allowed)) {
            $report['critical'][] = 'missing_citations';
        }

        return $report;
    }

    /** @return array{critical: list<string>, warnings: list<string>, human_review_required: bool, checks_version: string} */
    public function text(string $text, array $evidence): array
    {
        $critical = $this->provenanceIssues($text, $evidence);
        $sourceText = implode(' ', array_column($evidence['claims'], 'text'));
        $figures = implode(' ', collect($evidence['claims'])->flatMap(fn (array $claim): array => $claim['figures'])->all());
        $allowedNumbers = $this->numbers($sourceText.' '.$figures);
        foreach ($this->numbers($this->normalizeCalendarMonths($text, $evidence)) as $number) {
            if (! in_array($number, $allowedNumbers, true)) {
                $critical[] = 'unsupported_number:'.$number;
            }
        }
        $permitted = $sourceText.' '.$figures.' '.implode(' ', collect($evidence['claims'])->flatMap(fn (array $claim): array => $claim['entities'])->all());
        preg_match_all('/(?<![\p{L}])[A-Z]{2,6}(?![\p{L}])/u', $text, $symbols);
        foreach (array_unique($symbols[0]) as $symbol) {
            if (! preg_match('/(?<![\p{L}])'.preg_quote($symbol, '/').'(?![\p{L}])/u', $permitted)) {
                $critical[] = 'unsupported_symbol:'.$symbol;
            }
        }
        preg_match_all('/[$€£¥₹₽]/u', $text, $currencies);
        foreach (array_unique($currencies[0]) as $currency) {
            if (! str_contains($permitted, $currency)) {
                $critical[] = 'unsupported_currency:'.$currency;
            }
        }
        if (preg_match('/[“«][^”»]{8,}[”»]|"[^"\r\n]{8,}"/u', $text)) {
            $critical[] = 'unverified_direct_quote';
        }
        if (preg_match('/guaranteed (?:profit|return)|risk.free|buy (?:now|bitcoin|ethereum)|will (?:soar|double|triple)|100\s*% guaranteed|ignore (?:all|previous) instructions|system prompt|api[_ ]?key/i', $text)) {
            $critical[] = 'promotional_prediction_or_prompt_injection';
        }
        if (preg_match('/гарантированн\p{L}* (?:прибыль|доход)|rendement garanti|profit garanti|garantierte\p{L}* (?:gewinne|rendite)|ganancias garantizadas|rendimiento garantizado|profitto garantito|rendimento garantito|必ず儲か|利益を保証|保证收益|稳赚/ui', $text)) {
            $critical[] = 'promotional_prediction_or_prompt_injection';
        }
        if ($this->sourceOverlaps($text, $evidence) !== []) {
            $critical[] = 'excessive_source_similarity';
        }

        return ['critical' => array_values(array_unique($critical)),
            'warnings' => ['Human verification of all facts, entities, currencies, symbols, forecasts and attribution is required.',
                'Deterministic checks are screening, not independent semantic fact verification.'],
            'human_review_required' => true, 'checks_version' => 'quality-v3'];
    }

    /** @return list<string> */
    public function sourceOverlaps(string $text, array $evidence): array
    {
        $source = mb_strtolower(Str::squish(implode(' ', array_column($evidence['claims'], 'text'))));
        $tokens = preg_split('/\s+/u', mb_strtolower(Str::squish($text))) ?: [];
        $overlaps = [];
        for ($index = 0; $index + 12 <= count($tokens); $index++) {
            $phrase = implode(' ', array_slice($tokens, $index, 12));
            if (str_contains($source, $phrase)) {
                $overlaps[] = $phrase;
                if (count($overlaps) === 5) {
                    break;
                }
            }
        }

        return array_values(array_unique($overlaps));
    }

    /** @return array{threshold_words: int, overlaps: list<array{field: string, phrase: string}>} */
    public function originalityPreflight(array $document, array $evidence): array
    {
        $fields = array_intersect_key($document, array_flip(['headline', 'excerpt', 'seo_title', 'seo_description']));
        foreach ($document['takeaways'] as $index => $takeaway) {
            $fields['takeaways.'.$index] = $takeaway;
        }
        foreach ($document['sections'] as $sectionIndex => $section) {
            $fields['sections.'.$sectionIndex.'.heading'] = $section['heading'];
            foreach ($section['paragraphs'] as $paragraphIndex => $paragraph) {
                $fields['sections.'.$sectionIndex.'.paragraphs.'.$paragraphIndex.'.text'] = $paragraph['text'];
            }
        }
        $overlaps = [];
        foreach ($fields as $field => $value) {
            foreach ($this->sourceOverlaps($value, $evidence) as $phrase) {
                $overlaps[] = ['field' => $field, 'phrase' => $phrase];
                if (count($overlaps) === 5) {
                    break 2;
                }
            }
        }

        return ['threshold_words' => 12, 'overlaps' => $overlaps];
    }

    /** @return array{verified_claim_count: int, target_body_words: array{int, int}, target_paragraphs: int, target_sections: int} */
    public function writingBrief(array $evidence): array
    {
        $count = count(array_unique(array_column($evidence['claims'], 'text')));

        return ['verified_claim_count' => $count, 'target_body_words' => $count <= 2 ? [60, 110] : [90, min(360, 60 * $count)],
            'target_paragraphs' => $count <= 2 ? 2 : min(6, $count), 'target_sections' => $count <= 2 ? 1 : min(4, $count)];
    }

    /** @return list<string> */
    public function allowedEntities(array $evidence): array
    {
        return collect($evidence['claims'])->flatMap(fn (array $claim): array => $claim['entities'])->unique()->values()->all();
    }

    /** @return list<string> */
    public function provenanceIssues(string $text, array $evidence): array
    {
        if (preg_match('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/iu', $text)) {
            return ['technical_evidence_id_in_public_text'];
        }
        foreach ($evidence['claims'] as $claim) {
            if (filled($claim['id'] ?? null) && str_contains(mb_strtolower($text), mb_strtolower($claim['id']))) {
                return ['technical_evidence_id_in_public_text'];
            }
        }

        return [];
    }

    public function normalizeCalendarMonths(string $text, array $evidence): string
    {
        $source = implode(' ', array_column($evidence['claims'], 'text'));
        $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

        return preg_replace_callback('/(?<![0-9])([1-9]|1[0-2])\s*月/u', function (array $match) use ($source, $months): string {
            $month = $months[(int) $match[1] - 1];
            if (! preg_match('/\b'.preg_quote($month, '/').'\s+[0-9]{1,2}\b/u', $source)) {
                return $match[0];
            }

            return $month.'月';
        }, $text);
    }

    /** @return list<string> */
    public function numbers(string $text): array
    {
        $text = strtr($text, ['０' => '0', '１' => '1', '２' => '2', '３' => '3', '４' => '4', '５' => '5', '６' => '6', '７' => '7', '８' => '8', '９' => '9']);
        preg_match_all('/[0-9]+(?:[.,][0-9]+)*(?:\s*%)?/u', $text, $matches);
        $numbers = array_unique(array_map(fn (string $value): string => str_replace(' ', '', $value), $matches[0]));
        sort($numbers, SORT_STRING);

        return array_values($numbers);
    }
}
