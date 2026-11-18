<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WatchlistItem;
use App\Support\ReaderEngagement;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\EngagementTestCase;

class WatchlistTest extends EngagementTestCase
{
    public function test_add_duplicate_remove_and_reorder_are_owned_and_idempotent(): void
    {
        $service = app(ReaderEngagement::class);
        $first = $service->addAsset($this->reader, ['asset' => 'bitcoin']);
        $second = $service->addAsset($this->reader, ['asset' => 'gold']);
        $this->assertSame($first->id, $service->addAsset($this->reader, ['asset' => 'bitcoin'])->id);
        $service->reorder($this->reader, [$second->id, $first->id]);
        $this->assertSame([$second->id, $first->id], WatchlistItem::orderBy('position')->pluck('id')->all());
        $service->removeAsset($this->reader, $first);
        $this->assertDatabaseCount('watchlist_items', 1);
        Http::assertNothingSent();
    }

    public function test_unknown_assets_and_owner_spoofing_are_rejected(): void
    {
        $this->post('/en/account/watchlist', ['asset' => 'invented'])->assertSessionHasErrors();
        $this->post('/en/account/watchlist', ['asset' => 'bitcoin', 'user_id' => 999])->assertSessionHasErrors();
        $this->assertDatabaseCount('watchlist_items', 0);
    }

    public function test_limit_is_checked_atomically_before_a_new_asset(): void
    {
        config(['engagement.watchlist_limit' => 1]);
        app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => 'bitcoin']);
        $this->expectException(ValidationException::class);
        app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => 'gold']);
    }

    public function test_cross_user_mutations_and_partial_or_duplicate_orders_are_rejected(): void
    {
        $first = app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => 'bitcoin']);
        $second = app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => 'gold']);
        $other = app(ReaderEngagement::class)->addAsset(User::factory()->create(), ['asset' => 'silver']);
        $this->delete('/en/account/watchlist/'.$other->id)->assertForbidden();
        $this->patch('/en/account/watchlist/'.$other->id.'/move', ['direction' => 'up'])->assertForbidden();
        foreach ([[$first->id], [$first->id, $first->id], [$first->id, $other->id]] as $ids) {
            $this->put('/en/account/watchlist/order', ['order' => $ids])->assertSessionHasErrors();
        }
        $this->patch('/en/account/watchlist/'.$second->id.'/move', ['direction' => 'up'])->assertRedirect();
        $this->assertSame([$second->id, $first->id], WatchlistItem::where('user_id', $this->reader->id)->orderBy('position')->pluck('id')->all());
    }

    public function test_all_twenty_five_supported_assets_use_shared_cached_quotes_without_requests(): void
    {
        $this->assertSame(30, config('engagement.watchlist_limit'));
        foreach (array_keys(config('markets.assets')) as $asset) {
            app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => $asset]);
        }
        $this->get('/en/account/watchlist')->assertOk()->assertSee(__('market.demo_label'))->assertSee('BTC')->assertSee('HG');
        $this->assertDatabaseCount('watchlist_items', 25);
        Http::assertNothingSent();
    }
}
