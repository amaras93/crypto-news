<?php

namespace Tests\Feature;

use App\Models\AdCampaign;
use App\Models\AdPlacement;
use App\Models\Advertiser;
use App\Models\AiBudgetPeriod;
use App\Models\AiSpendReservation;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\Category;
use App\Models\CategoryTranslation;
use App\Models\Comment;
use App\Models\NewsletterConsent;
use App\Models\NewsletterSubscriber;
use App\Models\Tag;
use App\Models\User;
use App\Support\MarketDataCache;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;
use ZipArchive;

class DisposableRestoreTest extends TestCase
{
    public function test_disposable_database_media_and_private_files_restore_with_unicode_and_encrypted_records_intact(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $directory = storage_path('framework/testing/phase11-restore-'.Str::uuid());
        mkdir($directory.'/original/public', 0700, true);
        mkdir($directory.'/original/private', 0700, true);
        touch($directory.'/source.sqlite');
        $originalConnection = DB::getDefaultConnection();
        $originalKey = config('app.key');
        $originalMarketStore = config('markets.cache_store');
        $connection = 'phase11_restore_fixture';
        $sqlite = array_replace(config('database.connections.sqlite'), ['database' => $directory.'/source.sqlite', 'url' => null, 'foreign_key_constraints' => true, 'journal_mode' => 'DELETE']);
        config(['database.connections.'.$connection => $sqlite,
            'cache.stores.phase11_restore' => ['driver' => 'database', 'connection' => $connection, 'table' => 'cache', 'lock_connection' => $connection, 'lock_table' => 'cache_locks'],
            'markets.cache_store' => 'phase11_restore', 'app.key' => 'base64:'.base64_encode(str_repeat('D', 32))]);
        $this->app->forgetInstance('encrypter');
        Crypt::clearResolvedInstance('encrypter');
        try {
            $this->assertSame(0, Artisan::call('migrate', ['--database' => $connection, '--force' => true, '--no-interaction' => true]));
            DB::setDefaultConnection($connection);
            $admin = User::factory()->admin()->create();
            $category = Category::factory()->has(CategoryTranslation::factory()->state(['locale' => 'ja', 'name' => '市場', 'slug' => '市場']), 'translations')->create();
            $article = Article::factory()->published()->for($admin, 'author')->for($category)->create();
            foreach (['en' => 'Restore fixture', 'ja' => '暗号資産市場の検証', 'zh' => '加密货币市场验证', 'hy' => 'Պահպանված արխիվ'] as $locale => $text) {
                ArticleTranslation::factory()->for($article)->create(['locale' => $locale, 'title' => $text, 'slug' => $text, 'body' => '<p>'.$text.'</p>', 'summary' => [$text]]);
            }
            $article->tags()->attach(Tag::factory()->create());
            Comment::factory()->approved()->for($article)->for($admin, 'user')->create(['body' => '日本語と中文のコメント', 'locale' => 'ja']);
            $reservation = AiSpendReservation::factory()->create(['user_id' => $admin->id, 'estimated_microusd' => 125000, 'counts_article' => true]);
            foreach ([$reservation->day_key, $reservation->month_key] as $period) {
                AiBudgetPeriod::factory()->create(['period_key' => $period, 'reserved_microusd' => 125000, 'reserved_articles' => 1]);
            }
            $advertiser = Advertiser::factory()->create(['contact_information' => 'Disposable private contract fixture']);
            $campaign = AdCampaign::factory()->for($advertiser)->create(['contract_amount' => '1234.50']);
            $campaign->placements()->attach(AdPlacement::factory()->create());
            $subscriber = NewsletterSubscriber::factory()->create(['email' => 'restore-fixture@example.test']);
            NewsletterConsent::factory()->for($subscriber, 'subscriber')->create();
            $marketCache = app(MarketDataCache::class);
            $state = $marketCache->read('crypto');
            $state['requests'] = 7;
            $marketCache->save('crypto', $state);
            $before = $this->databaseSnapshot($connection);
            $pdo = DB::connection($connection)->getPdo();
            $pdo->exec('VACUUM INTO '.$pdo->quote($directory.'/backup.sqlite'));
            $this->assertTrue(copy($directory.'/backup.sqlite', $directory.'/restored.sqlite'));

            $files = ['public/fixture.png' => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a7/8AAAAASUVORK5CYII='),
                'private/editorial-fixture.txt' => 'Disposable private file — 秘密ではないテスト',
                'private/secrets-inventory.txt' => "APP_KEY: external vault reference required\nDB_PASSWORD: external vault reference required\nNo secret values in this inventory.\n"];
            $archive = new ZipArchive;
            $this->assertTrue($archive->open($directory.'/files.zip', ZipArchive::CREATE | ZipArchive::EXCL));
            foreach ($files as $path => $bytes) {
                file_put_contents($directory.'/original/'.$path, $bytes);
                $this->assertTrue($archive->addFile($directory.'/original/'.$path, $path));
            }
            $this->assertTrue($archive->close());
            $this->assertTrue($archive->open($directory.'/files.zip'));
            $this->assertTrue($archive->extractTo($directory.'/restored-files'));
            $archive->close();
            foreach ($files as $path => $bytes) {
                $this->assertSame(hash('sha256', $bytes), hash_file('sha256', $directory.'/restored-files/'.$path));
            }

            DB::purge($connection);
            Cache::purge('phase11_restore');
            config(['database.connections.'.$connection.'.database' => $directory.'/restored.sqlite']);
            $this->assertSame($before, $this->databaseSnapshot($connection));
            $this->assertSame('ok', DB::connection($connection)->selectOne('PRAGMA integrity_check')->integrity_check);
            $this->assertSame([], DB::connection($connection)->select('PRAGMA foreign_key_check'));
            $this->assertSame('加密货币市场验证', ArticleTranslation::where('locale', 'zh')->sole()->title);
            $this->assertSame('Պահպանված արխիվ', ArticleTranslation::where('locale', 'hy')->sole()->title);
            $this->assertSame('Disposable private contract fixture', Advertiser::findOrFail($advertiser->id)->contact_information);
            $this->assertSame('1234.50', AdCampaign::findOrFail($campaign->id)->contract_amount);
            $this->assertSame('restore-fixture@example.test', NewsletterSubscriber::findOrFail($subscriber->id)->email);
            $this->assertSame(7, $marketCache->read('crypto')['requests']);
            $this->assertSame(250000, (int) AiBudgetPeriod::sum('reserved_microusd'));
            Http::assertNothingSent();
            Mail::assertNothingSent();
            Notification::assertNothingSent();
            Queue::assertNothingPushed();
            file_put_contents(storage_path('framework/testing/phase11-restore-evidence.json'), json_encode(['status' => 'PASS', 'engine' => 'SQLite only; MySQL restore BLOCKED', 'tables_compared' => count($before), 'file_hashes_verified' => count($files), 'unicode' => ['ja', 'zh', 'hy'], 'encrypted_records_restored' => true, 'integrity' => 'ok', 'foreign_key_violations' => 0], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } finally {
            Cache::purge('phase11_restore');
            DB::purge($connection);
            DB::setDefaultConnection($originalConnection);
            config(['app.key' => $originalKey, 'markets.cache_store' => $originalMarketStore]);
            $this->app->forgetInstance('encrypter');
            Crypt::clearResolvedInstance('encrypter');
        }
    }

    /** @return array<string, array{rows: int, sha256: string}> */
    private function databaseSnapshot(string $connection): array
    {
        $database = DB::connection($connection);
        $tables = $database->select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
        $snapshot = [];
        foreach ($tables as $table) {
            $rows = array_map(fn (object $row): string => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $database->table($table->name)->get()->all());
            sort($rows);
            $snapshot[$table->name] = ['rows' => count($rows), 'sha256' => hash('sha256', implode("\n", $rows))];
        }

        return $snapshot;
    }
}
