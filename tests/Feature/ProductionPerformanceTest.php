<?php

namespace Tests\Feature;

use App\Filament\Resources\AdCampaigns\Pages\ManageAdCampaigns;
use App\Models\AdCampaign;
use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ProductionPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_campaign_permissions_do_not_issue_one_user_query_per_table_row(): void
    {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->actingAs(User::factory()->admin()->create());
        AdCampaign::factory()->create();
        $small = $this->measure(fn () => Livewire::test(ManageAdCampaigns::class));
        AdCampaign::factory()->count(9)->create();
        $large = $this->measure(fn () => Livewire::test(ManageAdCampaigns::class));
        $this->evidence('campaign_table', ['one_record' => $small, 'ten_records' => $large]);
        $this->assertLessThanOrEqual($small['queries'] + 3, $large['queries'], json_encode([$small, $large]));
    }

    public function test_public_query_counts_remain_bounded_as_the_article_catalogue_grows(): void
    {
        Http::preventStrayRequests();
        Article::factory()->published()->count(12)->has(ArticleTranslation::factory(), 'translations')->create();
        $small = [];
        foreach (['/en', '/en/news', '/en/news/'.ArticleTranslation::firstOrFail()->slug] as $path) {
            $this->get($path)->assertOk();
            $small[$path] = $this->measure(fn () => $this->get($path)->assertOk());
        }
        Article::factory()->published()->count(24)->has(ArticleTranslation::factory(), 'translations')->create();
        $large = [];
        foreach ($small as $path => $measurement) {
            $large[$path] = $this->measure(fn () => $this->get($path)->assertOk());
            $this->assertLessThanOrEqual($measurement['queries'] + 3, $large[$path]['queries'], $path);
        }
        $this->evidence('public_pages', ['twelve_articles' => $small, 'thirty_six_articles' => $large]);
        Http::assertNothingSent();
    }

    /** @return array{queries: int, user_queries: int, milliseconds: float} */
    private function measure(callable $render): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();
        $started = hrtime(true);
        try {
            $render();
            $queries = DB::getQueryLog();

            return ['queries' => count($queries), 'user_queries' => count(array_filter($queries, fn (array $query): bool => str_contains($query['query'], 'from "users"'))), 'milliseconds' => round((hrtime(true) - $started) / 1000000, 2)];
        } finally {
            DB::disableQueryLog();
        }
    }

    /** @param array<string, mixed> $measurements */
    private function evidence(string $name, array $measurements): void
    {
        if (! is_dir(storage_path('framework/testing'))) {
            mkdir(storage_path('framework/testing'), 0700, true);
        }
        file_put_contents(storage_path('framework/testing/phase11-performance-'.$name.'.json'), json_encode($measurements, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
