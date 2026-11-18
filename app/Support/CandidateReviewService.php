<?php

namespace App\Support;

use App\CandidateStatus;
use App\Models\CandidateReview;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class CandidateReviewService
{
    public function transition(User $actor, NewsCandidate $candidate, CandidateStatus $status, ?string $notes = null, ?int $duplicateOf = null): NewsCandidate
    {
        return DB::transaction(function () use ($actor, $candidate, $status, $notes, $duplicateOf): NewsCandidate {
            $actor = $actor->fresh() ?? throw new AuthorizationException;
            $record = NewsCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            Gate::forUser($actor)->authorize('review', $record);
            Validator::make(['notes' => $notes], ['notes' => ['nullable', 'string', 'max:2000']])->validate();
            if ($status === CandidateStatus::Duplicate && (! $duplicateOf || $duplicateOf >= $record->id || ! NewsCandidate::query()->whereKey($duplicateOf)->exists())) {
                throw ValidationException::withMessages(['duplicate_of_id' => 'Select an earlier existing candidate to avoid circular duplicate groups.']);
            }
            if ($status === CandidateStatus::ApprovedForDraft && $record->duplicate_of_id && ! filled($notes)) {
                throw ValidationException::withMessages(['notes' => 'Explain why this duplicate warning can be overridden for drafting.']);
            }
            if ($record->status === $status && ($status !== CandidateStatus::Duplicate || $record->duplicate_of_id === $duplicateOf)) {
                return $record;
            }
            $previous = $record->status;
            $record->status = $status;
            if ($status === CandidateStatus::Duplicate) {
                $record->duplicate_of_id = $duplicateOf;
                $record->duplicate_reasons = ['editorial_review'];
            }
            $record->reviewed_by = $actor->id;
            $record->reviewed_at = now();
            $record->save();
            CandidateReview::query()->create(['news_candidate_id' => $record->id, 'user_id' => $actor->id, 'previous_status' => $previous->value, 'new_status' => $status->value, 'notes' => $notes]);

            return $record;
        });
    }
}
