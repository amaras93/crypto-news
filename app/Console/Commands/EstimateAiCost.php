<?php

namespace App\Console\Commands;

use App\Support\AiFailure;
use App\Support\AiPricing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

class EstimateAiCost extends Command
{
    protected $signature = 'ai:estimate {--model= : Must match server-verified pricing} {--input=6000} {--output=2000} {--translation-input=3500} {--translation-output=2500} {--verification-input=10000} {--verification-output=1000} {--attempts=1} {--languages=7} {--articles=1}';

    protected $description = 'Estimate configured-rate USD costs offline; no credentials or provider requests';

    public function handle(AiPricing $pricing): int
    {
        $options = collect(['input', 'output', 'translation-input', 'translation-output', 'verification-input', 'verification-output', 'attempts', 'languages', 'articles'])->mapWithKeys(fn (string $key): array => [$key => $this->option($key)])->all();
        $rules = array_fill_keys(array_keys($options), ['required', 'integer', 'between:0,1000000']);
        $rules['languages'] = ['integer', 'between:0,7'];
        $rules['articles'] = ['integer', 'between:1,10000'];
        $rules['attempts'] = ['integer', 'between:1,3'];
        $validator = Validator::make($options, $rules);
        if ($validator->fails()) {
            $this->error('Invalid workload. Token counts must be integers; languages 0–7; articles 1–10000.');

            return self::FAILURE;
        }
        try {
            $model = $this->option('model') ?: config('ai.model');
            $rates = $pricing->snapshot((string) $model);
            $english = $pricing->cost(['input_tokens' => (int) $options['input'], 'cached_input_tokens' => 0, 'output_tokens' => (int) $options['output']], $rates);
            $translation = $pricing->cost(['input_tokens' => (int) $options['translation-input'], 'cached_input_tokens' => 0, 'output_tokens' => (int) $options['translation-output']], $rates);
            $verification = $pricing->cost(['input_tokens' => (int) $options['verification-input'], 'cached_input_tokens' => 0,
                'output_tokens' => (int) $options['verification-output']], $rates);
            $article = ($english + (int) $options['languages'] * $translation + (1 + (int) $options['languages']) * $verification) * (int) $options['attempts'];
            $this->table(['Model', 'Verified pricing date', 'USD / article', 'USD / workload'], [[$model, $rates['verified_at'], number_format($article / 1000000, 6, '.', ''), number_format($article * (int) $options['articles'] / 1000000, 6, '.', '')]]);
            $this->warn('Includes generation, translations, separate verification per language and the specified attempt multiplier. Conservative reservations also hold uncertain costs. Illustrative estimate; excludes tax and provider billing adjustments. No request sent.');

            return self::SUCCESS;
        } catch (AiFailure $failure) {
            $this->error($failure->reason.': configure current model-specific prices before estimating.');

            return self::FAILURE;
        }
    }
}
