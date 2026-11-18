<?php

namespace Tests\Feature;

use App\Models\AiBudgetPeriod;
use App\Models\NewsCandidate;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProductionConcurrencyTest extends TestCase
{
    #[DataProvider('reservationKeys')]
    public function test_two_isolated_workers_preserve_shared_sqlite_budget_and_idempotency(bool $sameKey): void
    {
        $directory = storage_path('framework/testing/phase11-concurrency-'.Str::uuid());
        mkdir($directory, 0700, true);
        $database = $directory.'/fixture.sqlite';
        touch($database);
        $original = DB::getDefaultConnection();
        $connection = 'phase11_concurrency_fixture';
        config(['database.connections.'.$connection => array_replace(config('database.connections.sqlite'), ['database' => $database, 'url' => null])]);
        $workers = [];
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true, '--no-interaction' => true]));
            DB::setDefaultConnection($connection);
            $admin = User::factory()->admin()->create();
            $candidate = NewsCandidate::factory()->approvedForDraft()->create();
            $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
\Illuminate\Support\Facades\Http::preventStrayRequests();
config(['ai.emergency_stop' => false, 'ai.daily_limit_microusd' => 1000, 'ai.monthly_limit_microusd' => 1000, 'ai.max_articles_per_day' => 2]);
file_put_contents($argv[1].'/ready-'.$argv[2], 'ready');
$deadline = microtime(true) + 15;
while (! is_file($argv[1].'/go')) {
    if (microtime(true) > $deadline) { exit(2); }
    usleep(10000);
}
try {
    $reservation = app(\App\Support\AiBudgetLedger::class)->reserve(\App\Models\User::findOrFail($argv[3]), \App\Models\NewsCandidate::findOrFail($argv[4]), $argv[5], 600);
    echo json_encode(['status' => 'reserved', 'id' => $reservation->id]);
} catch (\Illuminate\Validation\ValidationException) {
    echo json_encode(['status' => 'budget_blocked']);
}
\Illuminate\Support\Facades\Http::assertNothingSent();
PHP;
            $environment = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(str_repeat('C', 32)),
                'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $database, 'DB_URL' => '', 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync',
                'MAIL_MAILER' => 'array', 'MARKET_DATA_MODE' => 'demo', 'AI_GENERATION_ENABLED' => 'false', 'AI_TRANSLATION_ENABLED' => 'false',
                'OPENAI_API_KEY' => '', 'COINGECKO_API_KEY' => '', 'GOLDAPI_API_KEY' => ''];
            $requestKey = Str::uuid()->toString();
            foreach ([1, 2] as $number) {
                $worker = new Process([PHP_BINARY, '-r', $code, $directory, (string) $number, (string) $admin->id, (string) $candidate->id, $sameKey ? $requestKey : Str::uuid()->toString()], base_path(), $environment);
                $worker->setTimeout(40)->start();
                $workers[] = $worker;
            }
            $deadline = microtime(true) + 15;
            while (! (is_file($directory.'/ready-1') && is_file($directory.'/ready-2')) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($directory.'/ready-1');
            $this->assertFileExists($directory.'/ready-2');
            file_put_contents($directory.'/go', 'go');
            $statuses = [];
            $reservationIds = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getOutput().$worker->getErrorOutput());
                $result = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR);
                $statuses[] = $result['status'];
                if (isset($result['id'])) {
                    $reservationIds[] = $result['id'];
                }
            }
            sort($statuses);
            $this->assertSame($sameKey ? ['reserved', 'reserved'] : ['budget_blocked', 'reserved'], $statuses);
            $this->assertCount(1, array_unique($reservationIds));
            $this->assertSame(1, DB::table('ai_spend_reservations')->count());
            foreach (AiBudgetPeriod::all() as $period) {
                $this->assertSame(600, $period->reserved_microusd);
                $this->assertSame(0, $period->actual_microusd);
            }
            $this->assertSame(2, AiBudgetPeriod::count());
            $this->assertSame(0, DB::table('articles')->count());
            file_put_contents(storage_path('framework/testing/phase11-concurrency-'.($sameKey ? 'same_key' : 'distinct_keys').'.json'), json_encode(['status' => 'PASS', 'engine' => 'SQLite', 'workers' => 2, 'attempts' => 2, 'same_request_key' => $sameKey, 'outcomes' => $statuses, 'reservation_each_microusd' => 600, 'budget_microusd' => 1000, 'committed_reservations' => 1, 'paid_requests' => 0, 'mysql_concurrency' => 'BLOCKED'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
            DB::purge($connection);
            DB::setDefaultConnection($original);
        }
    }

    public static function reservationKeys(): array
    {
        return ['competing requests' => [false], 'duplicate request key' => [true]];
    }
}
