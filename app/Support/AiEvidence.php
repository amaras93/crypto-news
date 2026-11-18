<?php

namespace App\Support;

use App\CandidateStatus;
use App\Models\CandidateEvidence;
use App\Models\CandidateSourceReference;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class AiEvidence
{
    public function save(User $actor, NewsCandidate $candidate, array $data, ?CandidateEvidence $evidence = null): CandidateEvidence
    {
        Gate::forUser($actor->fresh())->authorize('review', $candidate);
        $values = Validator::make($data, [
            'claim' => ['required', 'string', 'min:60', 'max:1500', 'not_regex:/[<>\p{C}]/u', 'not_regex:/ignore (?:all|previous) instructions|system prompt|api[_ ]?key|execute (?:code|commands)/i'],
            'reference_ids' => ['required', 'array', 'min:1', 'max:5'], 'reference_ids.*' => ['required', 'integer', 'distinct'],
            'entities' => ['required', 'array', 'min:1', 'max:20'], 'entities.*' => ['required', 'string', 'max:100'],
            'figures' => ['present', 'array', 'max:30'], 'figures.*' => ['string', 'max:80'],
            'material' => ['required', 'boolean'], 'review_notes' => ['required', 'string', 'min:20', 'max:2000'],
            'confirmed' => ['accepted'],
        ])->validate();
        $this->references($candidate, $values['reference_ids'], (bool) $values['material']);
        $record = $evidence ? CandidateEvidence::query()->where('news_candidate_id', $candidate->id)->findOrFail($evidence->id) : new CandidateEvidence;
        unset($values['confirmed']);
        $record->fill($values + ['news_candidate_id' => $candidate->id, 'claim_key' => $record->claim_key ?? Str::uuid()->toString(),
            'verified_by' => $actor->id, 'verified_at' => now()]);
        $record->save();

        return $record;
    }

    /** @return array{claims: list<array{id: string, text: string, entities: list<string>, figures: list<string>, material: bool, verified_at: string, references: list<array<string, mixed>>}>, candidate_id: int, candidate_headline: string, is_simulated: bool} */
    public function snapshot(NewsCandidate $candidate): array
    {
        $candidate->refresh();
        if ($candidate->status !== CandidateStatus::ApprovedForDraft) {
            throw new AiFailure('candidate_not_approved');
        }
        if ($candidate->duplicate_of_id && ! $candidate->reviews()->where('new_status', 'approved_for_draft')->whereNotNull('notes')->exists()) {
            throw new AiFailure('duplicate_requires_review');
        }
        $claims = CandidateEvidence::query()->where('news_candidate_id', $candidate->id)->orderBy('id')->limit(13)->get();
        if ($claims->count() < 2 || $claims->count() > 12 || $claims->sum(fn (CandidateEvidence $claim): int => mb_strlen($claim->claim)) < 160) {
            throw new AiFailure('insufficient_evidence');
        }
        $result = [];
        foreach ($claims as $claim) {
            if (! $claim->verifier?->isEditorial() || ! $claim->verified_at) {
                throw new AiFailure('evidence_reviewer_unavailable');
            }
            $references = $this->references($candidate, $claim->reference_ids, $claim->material);
            $result[] = [
                'id' => $claim->claim_key, 'text' => $claim->claim, 'entities' => $claim->entities, 'figures' => $claim->figures,
                'material' => $claim->material, 'verified_at' => $claim->verified_at->toIso8601String(),
                'references' => $references,
            ];
        }

        return ['claims' => $result, 'candidate_id' => $candidate->id, 'candidate_headline' => $candidate->headline,
            'is_simulated' => (bool) $candidate->is_simulated];
    }

    public function digest(array $snapshot): string
    {
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function references(NewsCandidate $candidate, array $ids, bool $material): array
    {
        $references = CandidateSourceReference::query()->where('news_candidate_id', $candidate->id)->whereIn('id', $ids)->with('source.approver')->orderBy('id')->get();
        if ($references->count() !== count($ids)) {
            throw new AiFailure('invalid_evidence_reference');
        }
        $result = [];
        foreach ($references as $reference) {
            $source = $reference->source;
            if (! $source || ! $source->enabled || ! $source->approved_at || ! $source->approver?->is_admin
                || ! $source->ai_usage_allowed || ! filled($source->publisher_group)
                || (bool) $source->is_simulated !== (bool) $candidate->is_simulated) {
                throw new AiFailure('source_not_eligible_for_ai');
            }
            app(FeedUrlSafety::class)->inspect($reference->original_url);
            $published = $reference->source_published_at;
            if (! $published || $published->isFuture() || $published->lessThan(now()->subHours(config('ai.evidence_max_age_hours')))) {
                throw new AiFailure('outdated_or_undated_evidence');
            }
            $result[] = ['reference_id' => $reference->id, 'url' => $reference->original_url, 'publisher' => $source->name,
                'publisher_group' => $source->publisher_group, 'primary' => (bool) $source->is_primary,
                'source_timestamp' => $published->toIso8601String(), 'retrieved_at' => $reference->first_seen_at->toIso8601String(),
                'licensing_notes' => $source->licensing_notes, 'source_version' => $source->version];
        }
        $groups = array_unique(array_column($result, 'publisher_group'));
        if ($material && count($groups) < 2 && ! collect($result)->contains('primary', true)) {
            throw new AiFailure('corroboration_required');
        }

        return $result;
    }
}
