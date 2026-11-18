<?php

namespace App\Console\Commands;

use App\AiRunStatus;
use App\Models\AiGenerationRun;
use App\Support\AiDemoFixtures;
use App\Support\AiDrafting;
use App\Support\AiEditorialReviewService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DemoAiPipeline extends Command
{
    protected $signature = 'ai:demo';

    protected $description = 'Run fictional English and seven-language drafting in a new isolated SQLite database, without network requests';

    public function handle(AiDemoFixtures $fixtures, AiDrafting $pipeline, AiEditorialReviewService $reviews): int
    {
        if (! app()->environment(['local', 'testing']) || ! extension_loaded('pdo_sqlite')) {
            $this->error('The offline demo requires local/testing and pdo_sqlite.');

            return self::FAILURE;
        }
        $original = config('database.default');
        $queue = config('queue.default');
        $directory = base_path('.tools/ai-demo-'.Str::uuid());
        mkdir($directory, 0700, true);
        $database = $directory.'/preview.sqlite';
        touch($database);
        config(['database.connections.ai_demo' => ['driver' => 'sqlite', 'url' => null, 'database' => $database,
            'prefix' => '', 'foreign_key_constraints' => true, 'busy_timeout' => 5000],
            'database.default' => 'ai_demo', 'queue.default' => 'sync']);
        DB::purge('ai_demo');
        try {
            if (Artisan::call('migrate', ['--database' => 'ai_demo', '--force' => true, '--no-interaction' => true]) !== self::SUCCESS) {
                throw new \RuntimeException;
            }
            $fixture = $fixtures->create();
            $run = $pipeline->generate($fixture['user'], $fixture['candidate'], simulated: true, dispatch: false);
            $this->completeDraft($pipeline, $run->id);
            $article = $run->fresh()->article->fresh(['translations']);
            $notes = 'OFFLINE SIMULATION ONLY: deterministic fictional content checked against the original test evidence. No real-world fact verification is asserted.';
            $reviews->review($fixture['user'], $article, 'en', true, true, $notes);
            $runs = $pipeline->translate($fixture['user'], $article, array_values(array_diff(array_keys(config('news.locales')), ['en'])), dispatch: false);
            foreach ($runs as $translationRun) {
                $this->completeDraft($pipeline, $translationRun->id);
                $reviews->review($fixture['user'], $article->fresh(['translations']), $translationRun->locale, true, true, $notes);
            }
            foreach ($article->fresh(['translations'])->translations as $translation) {
                $html = '<!doctype html><html lang="'.e($translation->locale).'"><meta charset="utf-8"><meta name="robots" content="noindex,nofollow">'
                    .'<title>OFFLINE SIMULATED PREVIEW</title><body><p><strong>PRIVATE OFFLINE SIMULATION — fictional content, simulated usage, no charges.</strong></p>'
                    .'<h1>'.e($translation->title).'</h1><p>'.e($translation->excerpt).'</p>'.$translation->body.'</body></html>';
                file_put_contents($directory.'/'.$translation->locale.'.html', $html);
            }
            $this->warn('SIMULATED: English draft, human-review simulation and seven translations completed. No paid provider was invoked.');
            $this->info('Private preview files: '.$directory);
            $this->info('Main application database unchanged. Simulated articles cannot be published.');

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Offline demonstration failed. The isolated files remain for inspection; no production content was written.');

            return self::FAILURE;
        } finally {
            DB::disconnect('ai_demo');
            DB::purge('ai_demo');
            config(['database.default' => $original, 'queue.default' => $queue, 'database.connections.ai_demo' => null]);
        }
    }

    private function completeDraft(AiDrafting $pipeline, int $id): void
    {
        $pipeline->process($id, false);
        $pipeline->validate($id, false);
        $pipeline->store($id);
        if (AiGenerationRun::findOrFail($id)->status !== AiRunStatus::Stored) {
            throw new \RuntimeException;
        }
    }
}
