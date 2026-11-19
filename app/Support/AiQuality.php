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
        }
        $script = match ($run->locale) {
            'ru' => '/\p{Cyrillic}/u', 'ja' => '/[\p{Hiragana}\p{Katakana}\p{Han}]/u',
            'zh' => '/\p{Han}/u', default => '/\p{Latin}/u',
        };
        $letters = preg_match_all('/\p{L}/u', $text);
        if ($letters < 30 || preg_match_all($script, $text) / $letters < 0.45) {
            $report['critical'][] = 'language_script_mismatch';
        }
        $allowedEntities = collect($run->evidence_snapshot['claims'])->flatMap(fn (array $claim): array => $claim['entities'])->unique()->all();
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
            if ($this->numbers($text) !== $this->numbers($original)) {
                $report['critical'][] = 'translation_numbers_or_dates_changed';
            }
            foreach ($run->input_payload['preserved_entities'] ?? [] as $entity) {
                if (! str_contains($text, $entity)) {
                    $report['critical'][] = 'translation_entity_missing:'.$entity;
                }
            }
        }
        $report['critical'] = array_values(array_unique($report['critical']));

        return $report;
    }

    public function saved(ArticleTranslation $translation, AiGenerationRun $run): array
    {
        $text = implode(' ', [$translation->title, $translation->excerpt ?? '', $translation->meta_title ?? '', $translation->meta_description ?? '', ...($translation->summary ?? []),
            app(ArticleContent::class)->render($translation->body)['text']]);
        $report = $this->text($text, $run->evidence_snapshot);
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
        $critical = [];
        $sourceText = implode(' ', array_column($evidence['claims'], 'text'));
        $figures = implode(' ', collect($evidence['claims'])->flatMap(fn (array $claim): array => $claim['figures'])->all());
        $allowedNumbers = $this->numbers($sourceText.' '.$figures);
        foreach ($this->numbers($text) as $number) {
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
        $tokens = preg_split('/\s+/u', mb_strtolower(Str::squish($text))) ?: [];
        for ($i = 0; $i + 12 <= count($tokens); $i++) {
            $phrase = implode(' ', array_slice($tokens, $i, 12));
            if (str_contains(mb_strtolower(Str::squish($sourceText)), $phrase)) {
                $critical[] = 'excessive_source_similarity';
                break;
            }
        }

        return ['critical' => array_values(array_unique($critical)),
            'warnings' => ['Human verification of all facts, entities, currencies, symbols, forecasts and attribution is required.',
                'Deterministic checks are screening, not independent semantic fact verification.'],
            'human_review_required' => true, 'checks_version' => 'quality-v1'];
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
