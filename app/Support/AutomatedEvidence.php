<?php

namespace App\Support;

use App\CandidateStatus;
use App\Models\CandidateEvidence;
use App\Models\CandidateSourceReference;
use App\Models\NewsCandidate;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AutomatedEvidence
{
    public function __construct(private FeedHttpClient $http, private NewsNormalizer $normalizer) {}

    public function retrieve(NewsCandidate $candidate): void
    {
        if ($candidate->duplicate_of_id || $candidate->is_simulated || ! $candidate->published_at
            || $candidate->published_at->isFuture() || $candidate->published_at->lessThan(now()->subHours(config('ai.evidence_max_age_hours')))
            || ! $candidate->category_id || ! $candidate->topic || ($candidate->classification['warnings'] ?? [])) {
            throw new AiFailure('candidate_not_eligible_for_automation');
        }
        $reference = $candidate->references()->whereHas('source', fn ($query) => $query->where('is_primary', true)
            ->where('primary_material_permitted', true)->where('ai_usage_allowed', true)->where('enabled', true)
            ->where('is_simulated', false)->whereNotNull('approved_at'))->oldest('id')->first();
        if (! $reference || ! $reference->source_published_at) {
            throw new AiFailure('permitted_primary_evidence_unavailable');
        }
        $payload = $this->http->fetch($reference->source, $reference->original_url);
        $html = $payload['body'] ?? '';
        if (! mb_check_encoding($html, 'UTF-8') || str_contains(strtolower($html), '<!entity')) {
            throw new AiFailure('unsupported_primary_material');
        }
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $xpath = new DOMXPath($document);
        $title = $xpath->evaluate('string((//meta[@property="og:title"]/@content | //article//h1 | //main//h1)[1])');
        if ($this->normalizer->headline($title) !== $this->normalizer->headline($reference->headline)) {
            throw new AiFailure('primary_material_identity_mismatch');
        }
        $timestamp = $xpath->evaluate('string((//meta[@property="article:published_time"]/@content | //article//time/@datetime | //main//time/@datetime)[1])');
        try {
            $date = preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})\z/D', $timestamp)
                ? CarbonImmutable::parse($timestamp) : null;
        } catch (\Throwable) {
            $date = null;
        }
        if (! $date || $date->isFuture() || abs($date->timestamp - $reference->source_published_at->timestamp) > 300) {
            throw new AiFailure('primary_material_date_mismatch');
        }
        $claims = [];
        foreach ($xpath->query('//article//p[not(ancestor::nav or ancestor::aside or ancestor::footer or ancestor::script or ancestor::style)] | //main//p[not(ancestor::article or ancestor::nav or ancestor::aside or ancestor::footer or ancestor::script or ancestor::style)]') as $paragraph) {
            $text = Str::squish($paragraph->textContent);
            if (mb_strlen($text) < 80 || mb_strlen($text) > 1500 || preg_match('/[<>\p{C}]|ignore (?:all|previous) instructions|system prompt|api[_ ]?key|execute (?:code|commands)/iu', $text)) {
                continue;
            }
            $claims[] = $text;
            if (count($claims) === 6) {
                break;
            }
        }
        $claims = array_values(array_unique($claims));
        if (count($claims) < 2) {
            throw new AiFailure('insufficient_primary_material');
        }
        DB::transaction(function () use ($candidate, $reference, $html, $date, $claims): void {
            $record = NewsCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            if (! in_array($record->status, [CandidateStatus::New, CandidateStatus::ReviewRequired, CandidateStatus::ProcessingFailed], true)
                || $record->duplicate_of_id || $record->aiRuns()->exists()
                || CandidateEvidence::where('news_candidate_id', $record->id)->exists()) {
                throw new AiFailure('candidate_changed_during_verification');
            }
            foreach ($claims as $text) {
                preg_match_all('/\b[A-Z][\p{L}\p{N}-]{2,}(?:\s+[A-Z][\p{L}\p{N}-]{2,})*/u', $text, $entities);
                $names = array_map(fn (string $name): string => preg_replace('/\A(?:The|This|That|These|Those|Its|Our|Their)\s+/', '', $name), $entities[0]);
                $names = array_values(array_filter(array_unique($names), fn (string $name): bool => ! in_array($name,
                    ['The', 'This', 'That', 'These', 'Those', 'Its', 'It', 'According', 'When', 'While', 'During', 'After', 'Before',
                        'For', 'From', 'With', 'Without', 'Under', 'Over', 'And', 'But', 'However', 'Both', 'Also', 'Not', 'Such',
                        'Some', 'All', 'Most', 'More', 'Our', 'Their', 'They', 'You', 'She', 'News', 'Today', 'Yesterday', 'Tomorrow', 'Since', 'Next', 'Despite'], true)));
                CandidateEvidence::create(['news_candidate_id' => $record->id, 'claim_key' => Str::uuid()->toString(),
                    'claim' => $text, 'reference_ids' => [$reference->id], 'entities' => array_slice($names, 0, 20),
                    'figures' => app(AiQuality::class)->numbers($text), 'material' => true,
                    'verification_method' => 'automated_primary', 'automated_verified_at' => now(),
                    'review_notes' => 'Automated retrieval of attributed primary-source assertions; no human fact verification is asserted.',
                    'verified_by' => null, 'verified_at' => null, 'retrieval_metadata' => [
                        'url' => $reference->original_url, 'source_version' => $reference->source->version,
                        'document_sha256' => hash('sha256', $html), 'claim_sha256' => hash('sha256', $text),
                        'source_published_at' => $date->toIso8601String(), 'method' => 'primary-html-v1',
                    ]]);
            }
            $record->forceFill(['status' => CandidateStatus::ApprovedForDraft, 'automation_status' => 'evidence_verified',
                'automation_error' => null, 'automation_retry_at' => null])->save();
            app(AiEvidence::class)->snapshot($record);
        }, 3);
    }

    public function assertVerified(CandidateEvidence $claim): void
    {
        $metadata = $claim->retrieval_metadata;
        $reference = count($claim->reference_ids) === 1 ? CandidateSourceReference::find($claim->reference_ids[0]) : null;
        $source = $reference?->source;
        if (! $claim->automated_verified_at || $claim->automated_verified_at->isFuture() || $claim->verified_by || $claim->verified_at
            || ! $metadata || ($metadata['claim_sha256'] ?? '') !== hash('sha256', $claim->claim)
            || ! $source?->is_primary || ! $source->primary_material_permitted
            || $reference->news_candidate_id !== $claim->news_candidate_id
            || ($metadata['method'] ?? '') !== 'primary-html-v1' || ! preg_match('/\A[a-f0-9]{64}\z/D', $metadata['document_sha256'] ?? '')
            || $claim->figures !== app(AiQuality::class)->numbers($claim->claim)
            || collect($claim->entities)->contains(fn (string $entity): bool => ! str_contains($claim->claim, $entity))
            || ($metadata['url'] ?? '') !== $reference->original_url || ($metadata['source_version'] ?? null) !== $source->version) {
            throw new AiFailure('automated_evidence_invalidated');
        }
    }
}
