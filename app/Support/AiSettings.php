<?php

namespace App\Support;

use App\Models\AiPipelineSetting;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class AiSettings
{
    public function current(): array
    {
        $defaults = [
            'candidate_processing_enabled' => false, 'generation_enabled' => false, 'translation_enabled' => false,
            'scheduled_generation_enabled' => false, 'emergency_stop' => true, 'provider' => config('ai.provider'),
            'model' => config('ai.model'), 'daily_limit_microusd' => config('ai.daily_limit_microusd'),
            'monthly_limit_microusd' => config('ai.monthly_limit_microusd'), 'max_articles_per_day' => config('ai.max_articles_per_day'),
            'max_concurrent_jobs' => config('ai.max_concurrent_jobs'), 'max_translation_languages' => config('ai.max_translation_languages'),
            'author_id' => null,
            'updated_by' => null,
        ];
        $stored = AiPipelineSetting::query()->find(1);

        return $stored ? array_replace($defaults, $stored->only(array_keys($defaults))) : $defaults;
    }

    public function enabledFor(string $task): bool
    {
        $settings = $this->current();
        $flag = $task === 'translation' ? 'translation_enabled' : 'generation_enabled';

        return ! config('ai.emergency_stop') && ! $settings['emergency_stop']
            && config('ai.candidate_processing_enabled') && $settings['candidate_processing_enabled']
            && config('ai.'.$flag) && $settings[$flag];
    }

    public function assertLive(string $task): array
    {
        $settings = $this->current();
        if (! $this->enabledFor($task)) {
            throw new AiFailure('generation_stopped');
        }
        if ($settings['provider'] !== 'openai' || ! preg_match('/\A[a-zA-Z0-9._:-]{1,128}\z/D', $settings['model'])) {
            throw new AiFailure('invalid_provider_configuration');
        }
        $key = config('ai.openai_key');
        if (! is_string($key) || ! preg_match('/\A[\x21-\x7e]{12,512}\z/D', $key)) {
            throw new AiFailure('missing_credentials');
        }

        return $settings;
    }

    /** @return array{daily: int, monthly: int, articles: int, concurrent: int, languages: int} */
    public function limits(): array
    {
        $current = $this->current();

        return [
            'daily' => min((int) config('ai.daily_limit_microusd'), (int) $current['daily_limit_microusd']),
            'monthly' => min((int) config('ai.monthly_limit_microusd'), (int) $current['monthly_limit_microusd']),
            'articles' => min((int) config('ai.max_articles_per_day'), (int) $current['max_articles_per_day']),
            'concurrent' => max(1, min((int) config('ai.max_concurrent_jobs'), (int) $current['max_concurrent_jobs'])),
            'languages' => min((int) config('ai.max_translation_languages'), (int) $current['max_translation_languages']),
        ];
    }

    public function save(User $actor, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $rules = [];
        foreach (['candidate_processing_enabled', 'generation_enabled', 'translation_enabled', 'scheduled_generation_enabled', 'emergency_stop'] as $flag) {
            $rules[$flag] = ['required', 'boolean'];
        }
        $values = Validator::make($data, $rules + [
            'provider' => ['required', Rule::in(['openai'])], 'model' => ['nullable', 'string', 'max:128', 'regex:/\A[a-zA-Z0-9._:-]*\z/D'],
            'daily_limit_microusd' => ['required', 'integer', 'between:0,1000000000000'],
            'monthly_limit_microusd' => ['required', 'integer', 'between:0,1000000000000'],
            'max_articles_per_day' => ['required', 'integer', 'between:0,1000'],
            'max_concurrent_jobs' => ['required', 'integer', 'between:1,5'],
            'max_translation_languages' => ['required', 'integer', 'between:0,7'],
            'author_id' => ['nullable', 'integer', 'exists:users,id'], 'automatic_publishing' => ['prohibited'],
        ])->validate();
        $values['model'] = $values['model'] ?? '';
        AiPipelineSetting::updateOrCreate(['id' => 1], $values + ['updated_by' => $actor->id]);
    }

    public function stop(User $actor): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $settings = AiPipelineSetting::firstOrNew(['id' => 1]);
        $settings->emergency_stop = true;
        $settings->updated_by = $actor->id;
        $settings->save();
    }
}
