<?php

namespace App\Support;

use App\CandidateStatus;
use App\Models\CandidateSourceReference;
use App\Models\IngestionRun;
use App\Models\NewsCandidate;
use App\Models\NewsSource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;

class NewsCandidateStore
{
    public function __construct(private NewsClassifier $classifier, private NewsNormalizer $normalizer) {}

    /** Caller holds the shared candidate lock and database transaction.
     * @param  array<string, mixed>  $item
     * @return array{created: bool, duplicate: bool}
     */
    public function store(NewsSource $source, IngestionRun $run, array $item): array
    {
        $reference = CandidateSourceReference::query()->where('news_source_id', $source->id)->where('external_hash', $item['external_hash'])->first();
        if ($reference) {
            $reference->last_seen_at = now();
            $reference->save();

            return ['created' => false, 'duplicate' => false];
        }
        $candidate = NewsCandidate::query()->where('url_hash', $item['url_hash'])->first();
        $created = false;
        if (! $candidate) {
            $classification = $this->classifier->classify($item, $source);
            [$duplicate, $reasons] = $this->duplicate($item);
            $candidate = new NewsCandidate(Arr::only($item, [
                'headline', 'original_url', 'canonical_url', 'url_hash', 'headline_hash',
                'content_fingerprint', 'language', 'summary', 'published_at',
            ]) + $classification + ['discovered_at' => now(), 'duplicate_of_id' => $duplicate?->id, 'duplicate_reasons' => $reasons ?: null]);
            $candidate->status = $duplicate ? CandidateStatus::Duplicate
                : ($classification['classification']['uncertain'] || $item['warnings'] ? CandidateStatus::ReviewRequired : CandidateStatus::New);
            $candidate->classification = $classification['classification'] + ['warnings' => $item['warnings']];
            $candidate->save();
            $created = true;
        }
        CandidateSourceReference::query()->create([
            'news_candidate_id' => $candidate->id, 'news_source_id' => $source->id, 'ingestion_run_id' => $run->id,
            'external_id' => $item['external_id'], 'external_hash' => $item['external_hash'],
            'original_url' => $item['original_url'], 'headline' => $item['headline'], 'language' => $item['language'],
            'source_published_at' => $item['published_at'],
            'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        return ['created' => $created, 'duplicate' => $candidate->status === CandidateStatus::Duplicate];
    }

    /** @param array<string, mixed> $item
     * @return array{?NewsCandidate, list<string>}
     */
    private function duplicate(array $item): array
    {
        if (mb_strlen($item['normalized_headline']) < 25) {
            return [null, []];
        }
        $exact = NewsCandidate::query()->where('language', $item['language'])
            ->where(fn (Builder $query): Builder => $query->where('headline_hash', $item['headline_hash'])->orWhere('content_fingerprint', $item['content_fingerprint']))
            ->oldest('id')->first();
        if ($exact) {
            return [$exact, [$exact->content_fingerprint === $item['content_fingerprint'] ? 'stable_fingerprint' : 'normalized_headline']];
        }
        if (! $item['published_at']) {
            return [null, []];
        }
        $words = array_unique(explode(' ', $item['normalized_headline']));
        if (count($words) < 8) {
            return [null, []];
        }
        $nearby = NewsCandidate::query()->where('language', $item['language'])
            ->whereBetween('published_at', [$item['published_at']->subDay(), $item['published_at']->addDay()])
            ->latest('id')->limit(100)->get(['id', 'headline', 'published_at']);
        foreach ($nearby as $candidate) {
            $other = array_unique(explode(' ', $this->normalizer->headline($candidate->headline)));
            $common = count(array_intersect($words, $other));
            preg_match_all('/\b[0-9]+\b/', $item['normalized_headline'], $numbers);
            preg_match_all('/\b[0-9]+\b/', $this->normalizer->headline($candidate->headline), $otherNumbers);
            if ($common >= 8 && $common / count(array_unique(array_merge($words, $other))) >= 0.9 && $numbers[0] === $otherNumbers[0]) {
                return [$candidate, ['headline_similarity_review']];
            }
        }

        return [null, []];
    }
}
