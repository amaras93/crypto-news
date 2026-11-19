<?php

namespace App\Support;

use App\CandidateStatus;
use App\Models\AiBudgetPeriod;
use App\Models\AiGenerationRun;
use App\Models\AiSpendReservation;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AiBudgetLedger
{
    public function reserve(User $actor, NewsCandidate $candidate, string $requestKey, int $estimatedMicrousd, array $limits = [], bool $countsArticle = true): AiSpendReservation
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        Validator::make(['request_key' => $requestKey, 'estimate' => $estimatedMicrousd], [
            'request_key' => ['required', 'uuid'], 'estimate' => ['required', 'integer', 'between:1,1000000000000'],
        ])->validate();
        if (config('ai.emergency_stop')) {
            throw ValidationException::withMessages(['budget' => 'The AI emergency stop is active.']);
        }

        return DB::transaction(function () use ($actor, $candidate, $requestKey, $estimatedMicrousd, $limits, $countsArticle): AiSpendReservation {
            abort_unless($actor->fresh()?->is_admin, 403);
            $record = NewsCandidate::query()->lockForUpdate()->findOrFail($candidate->id);
            if ($record->status !== CandidateStatus::ApprovedForDraft) {
                throw ValidationException::withMessages(['candidate' => 'Only an approved-for-draft candidate can reserve future work.']);
            }
            $keys = ['day:'.now()->utc()->toDateString(), 'month:'.now()->utc()->format('Y-m')];
            $periods = $this->periods($keys);
            $existing = AiSpendReservation::query()->where('request_key', $requestKey)->first();
            if ($existing) {
                if ($existing->news_candidate_id !== $record->id || $existing->estimated_microusd !== $estimatedMicrousd || $existing->user_id !== $actor->id || $existing->counts_article !== $countsArticle) {
                    throw ValidationException::withMessages(['budget' => 'Reservation key conflicts with existing work.']);
                }

                return $existing;
            }
            $day = $periods->firstWhere('period_key', $keys[0]);
            $month = $periods->firstWhere('period_key', $keys[1]);
            $this->assertAllowance($day, $month, $estimatedMicrousd, $limits, $countsArticle);
            foreach ($periods as $period) {
                $period->reserved_microusd += $estimatedMicrousd;
                $period->reserved_articles += (int) $countsArticle;
                $period->save();
            }

            $reservation = AiSpendReservation::query()->create([
                'request_key' => $requestKey, 'news_candidate_id' => $record->id, 'user_id' => $actor->id,
                'day_key' => $keys[0], 'month_key' => $keys[1], 'provider' => (string) config('ai.provider'),
                'model' => (string) config('ai.model'), 'estimated_microusd' => $estimatedMicrousd,
            ])->fresh();
            $reservation->counts_article = $countsArticle;
            $reservation->save();

            return $reservation;
        }, 3);
    }

    public function assertAvailable(int $estimatedMicrousd, array $limits = [], bool $countsArticle = true): void
    {
        if (config('ai.emergency_stop')) {
            throw ValidationException::withMessages(['budget' => 'The AI emergency stop is active.']);
        }
        $day = AiBudgetPeriod::where('period_key', 'day:'.now()->utc()->toDateString())->first();
        $month = AiBudgetPeriod::where('period_key', 'month:'.now()->utc()->format('Y-m'))->first();
        $this->assertAllowance($day, $month, $estimatedMicrousd, $limits, $countsArticle);
    }

    private function assertAllowance(?AiBudgetPeriod $day, ?AiBudgetPeriod $month, int $estimatedMicrousd, array $limits, bool $countsArticle): void
    {
        $dayLimit = min((int) config('ai.daily_limit_microusd'), $limits['daily'] ?? PHP_INT_MAX);
        $monthLimit = min((int) config('ai.monthly_limit_microusd'), $limits['monthly'] ?? PHP_INT_MAX);
        $articleLimit = min((int) config('ai.max_generation_attempts_per_day'), $limits['attempts'] ?? PHP_INT_MAX);
        $sentAttempts = AiGenerationRun::where('is_simulated', false)->where('task', 'english')
            ->where('request_sent_at', '>=', now()->utc()->startOfDay())->sum('request_count');
        $attempts = max(($day?->actual_articles ?? 0) + ($day?->reserved_articles ?? 0), (int) $sentAttempts);
        if ($estimatedMicrousd <= 0 || $dayLimit <= 0 || $monthLimit <= 0 || $articleLimit <= 0
            || ($day?->actual_microusd ?? 0) + ($day?->reserved_microusd ?? 0) + $estimatedMicrousd > $dayLimit
            || ($month?->actual_microusd ?? 0) + ($month?->reserved_microusd ?? 0) + $estimatedMicrousd > $monthLimit
            || ($countsArticle && $attempts >= $articleLimit)) {
            throw ValidationException::withMessages(['budget' => 'The configured budget or daily generation attempt allowance is exhausted.']);
        }
    }

    public function settle(User $actor, AiSpendReservation $reservation, int $actualMicrousd, ?int $inputTokens = null, ?int $outputTokens = null, ?int $cachedTokens = null, ?int $writeTokens = null): AiSpendReservation
    {
        Validator::make(['actual' => $actualMicrousd, 'input' => $inputTokens, 'output' => $outputTokens, 'cached' => $cachedTokens, 'write' => $writeTokens], [
            'actual' => ['required', 'integer', 'between:0,1000000000000'],
            'input' => ['nullable', 'integer', 'between:0,1000000000'], 'output' => ['nullable', 'integer', 'between:0,1000000000'],
            'cached' => ['nullable', 'integer', 'between:0,1000000000'], 'write' => ['nullable', 'integer', 'between:0,1000000000'],
        ])->validate();

        return $this->finish($actor, $reservation, $actualMicrousd, $inputTokens, $outputTokens, $cachedTokens, $writeTokens);
    }

    public function release(User $actor, AiSpendReservation $reservation): AiSpendReservation
    {
        return $this->finish($actor, $reservation, null, null, null, null, null);
    }

    public function uncertain(User $actor, AiSpendReservation $reservation): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        DB::transaction(function () use ($reservation): void {
            $record = AiSpendReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($record->status === 'reserved') {
                $record->forceFill(['status' => 'uncertain'])->save();
            }
        });
    }

    private function finish(User $actor, AiSpendReservation $reservation, ?int $actual, ?int $input, ?int $output, ?int $cached, ?int $write): AiSpendReservation
    {
        abort_unless($actor->fresh()?->is_admin, 403);

        return DB::transaction(function () use ($actor, $reservation, $actual, $input, $output, $cached, $write): AiSpendReservation {
            abort_unless($actor->fresh()?->is_admin, 403);
            $stored = AiSpendReservation::query()->findOrFail($reservation->id);
            $keys = [$stored->day_key, $stored->month_key];
            $periods = $this->periods($keys);
            $record = AiSpendReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            $status = $actual === null ? 'released' : 'settled';
            if (! in_array($record->status, ['reserved', 'uncertain'], true)) {
                if ($record->status !== $status || $record->actual_microusd !== $actual || $record->input_tokens !== $input || $record->output_tokens !== $output || $record->cached_input_tokens !== $cached || $record->cache_write_tokens !== $write) {
                    throw ValidationException::withMessages(['budget' => 'Finalized accounting cannot be rewritten.']);
                }

                return $record;
            }
            foreach ($periods as $period) {
                $period->reserved_microusd -= $record->estimated_microusd;
                $period->reserved_articles -= (int) $record->counts_article;
                if ($actual !== null) {
                    $period->actual_microusd += $actual;
                    $period->actual_articles += (int) $record->counts_article;
                }
                $period->save();
            }
            $record->forceFill(['actual_microusd' => $actual, 'input_tokens' => $input, 'output_tokens' => $output, 'cached_input_tokens' => $cached, 'cache_write_tokens' => $write, 'status' => $status, 'settled_at' => now()])->save();

            return $record;
        }, 3);
    }

    /** @param list<string> $keys
     * @return Collection<int, AiBudgetPeriod>
     */
    private function periods(array $keys): Collection
    {
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            DB::table('ai_budget_periods')->insertOrIgnore(['period_key' => $key, 'created_at' => now(), 'updated_at' => now()]);
        }

        return AiBudgetPeriod::query()->whereIn('period_key', $keys)->orderBy('period_key')->lockForUpdate()->get();
    }
}
