<?php

namespace Tests\Feature;

use App\Models\PriceAlert;
use App\Models\User;
use App\Support\MarketQuote;
use App\Support\PriceAlerts;
use App\Support\PriceThreshold;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\EngagementTestCase;

class PriceAlertTest extends EngagementTestCase
{
    public function test_initial_above_price_does_not_trigger_but_later_crossing_does_and_replays_are_idempotent(): void
    {
        $this->liveAlerts();
        $alert = $this->alert();
        $this->snapshot($this->quote(110));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(90));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(101));
        $this->assertSame(1, app(PriceAlerts::class)->evaluate());
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertDatabaseCount('price_alert_events', 1);
        $this->assertDatabaseCount('engagement_deliveries', 1);
        $this->assertNotNull($alert->fresh()->last_triggered_at);
        Http::assertNothingSent();
    }

    public function test_demo_mode_never_creates_a_real_alert_or_keeps_a_live_baseline(): void
    {
        $this->liveAlerts();
        $alert = $this->alert();
        $this->snapshot($this->quote(90));
        app(PriceAlerts::class)->evaluate();
        config(['markets.mode' => 'demo']);
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(110));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertNull($alert->fresh()->last_price);
        $this->assertDatabaseCount('price_alert_events', 0);
    }

    public function test_stale_data_breaks_observation_continuity(): void
    {
        $this->liveAlerts();
        $alert = $this->alert();
        $this->snapshot($this->quote(90));
        app(PriceAlerts::class)->evaluate();
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(80, status: 'stale'));
        app(PriceAlerts::class)->evaluate();
        $this->assertNull($alert->fresh()->last_price);
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(110));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertDatabaseCount('price_alert_events', 0);
    }

    public static function invalidThresholds(): array
    {
        return [['0'], ['-1'], ['NaN'], ['INF'], ['1e3'], ['1,000'], ['0.00000000001'], ['1000000000000001'], [['bad']]];
    }

    #[DataProvider('invalidThresholds')]
    public function test_invalid_thresholds_are_rejected(mixed $value): void
    {
        $this->post('/en/account/alerts', $this->alertData(['target_price' => $value]))->assertSessionHasErrors('target_price');
        $this->assertDatabaseCount('price_alerts', 0);
    }

    public function test_units_and_duplicate_thresholds_are_checked(): void
    {
        $this->post('/en/account/alerts', $this->alertData(['asset' => 'gold', 'unit' => 'pound']))->assertSessionHasErrors('asset');
        $this->alert();
        $this->post('/en/account/alerts', $this->alertData())->assertSessionHasErrors('target_price');
        $this->assertDatabaseCount('price_alerts', 1);
    }

    public function test_create_edit_pause_delete_and_restore_do_not_reuse_observations(): void
    {
        $this->post('/en/account/alerts', $this->alertData())->assertRedirect();
        $alert = PriceAlert::sole();
        $alert->forceFill(['last_price' => '90.0000000000', 'last_state' => 'below'])->save();
        $this->patch('/en/account/alerts/'.$alert->id, $this->alertData(['target_price' => '105']))->assertRedirect();
        $this->assertSame('105.0000000000', $alert->fresh()->target_price);
        $this->assertNull($alert->fresh()->last_price);
        $this->patch('/en/account/alerts/'.$alert->id.'/pause')->assertRedirect();
        $this->assertFalse($alert->fresh()->enabled);
        $this->delete('/en/account/alerts/'.$alert->id)->assertRedirect();
        $this->assertSoftDeleted($alert);
        $this->post('/en/account/alerts', $this->alertData(['target_price' => '105']))->assertRedirect();
        $this->assertSame($alert->id, PriceAlert::sole()->id);
        $this->assertTrue($alert->fresh()->enabled);
        $this->assertNull($alert->fresh()->last_price);
    }

    public function test_active_limit_is_twenty_and_pausing_frees_one_slot(): void
    {
        for ($index = 1; $index <= 20; $index++) {
            $this->alert(['target_price' => (string) $index]);
        }
        $this->post('/en/account/alerts', $this->alertData(['target_price' => '999']))->assertSessionHasErrors('enabled');
        app(PriceAlerts::class)->pause($this->reader, PriceAlert::firstOrFail());
        $this->post('/en/account/alerts', $this->alertData(['target_price' => '999']))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(20, PriceAlert::where('enabled', true)->count());
    }

    public function test_personal_alert_mutations_never_allow_another_user_or_owner_spoofing(): void
    {
        $other = User::factory()->create();
        $alert = app(PriceAlerts::class)->save($other, $this->alertData());
        $this->patch('/en/account/alerts/'.$alert->id, $this->alertData(['target_price' => '200']))->assertForbidden();
        $this->patch('/en/account/alerts/'.$alert->id.'/pause')->assertForbidden();
        $this->delete('/en/account/alerts/'.$alert->id)->assertForbidden();
        $this->post('/en/account/alerts', $this->alertData(['user_id' => $other->id]))->assertSessionHasErrors('user_id');
        $this->assertSame('100.0000000000', $alert->fresh()->target_price);
    }

    public function test_below_crossings_rearm_only_after_returning_to_the_threshold_or_above(): void
    {
        $this->liveAlerts();
        $this->alert(['direction' => 'below']);
        foreach ([110 => 0, 100 => 0, 99 => 1, 98 => 0, 101 => 0, 97 => 1] as $price => $expected) {
            $this->travel(1)->minutes();
            $this->snapshot($this->quote($price));
            $this->assertSame($expected, app(PriceAlerts::class)->evaluate());
        }
        $this->assertDatabaseCount('price_alert_events', 2);
    }

    public static function unsafeQuotes(): array
    {
        return array_map(fn (string $case): array => [$case], ['zero', 'negative', 'nan', 'infinity', 'null', 'demo', 'delayed', 'unavailable', 'wrong_symbol', 'wrong_unit', 'wrong_currency', 'wrong_provider', 'future_provider', 'future_fetch', 'old_provider', 'old_fetch']);
    }

    #[DataProvider('unsafeQuotes')]
    public function test_invalid_unlicensed_or_unreliable_quotes_reset_the_baseline(string $case): void
    {
        $this->liveAlerts();
        $alert = $this->alert();
        $this->snapshot($this->quote(90));
        app(PriceAlerts::class)->evaluate();
        $this->travel(1)->minutes();
        $quote = new MarketQuote('bitcoin', $case === 'wrong_symbol' ? 'ETH' : 'BTC', 'bitcoin', $case === 'wrong_currency' ? 'EUR' : 'USD',
            match ($case) {
                'zero' => 0.0, 'negative' => -1.0, 'nan' => NAN, 'infinity' => INF, 'null' => null, default => 110.0
            }, 1.2,
            match ($case) {
                'demo' => 'demo', 'wrong_provider' => 'goldapi', default => 'coingecko'
            },
            now()->timestamp + match ($case) {
                'future_provider' => 1, 'old_provider' => -700, default => 0
            },
            now()->timestamp + match ($case) {
                'future_fetch' => 1, 'old_fetch' => -700, default => 0
            },
            $case === 'wrong_unit' ? 'troy_ounce' : 'coin', in_array($case, ['delayed', 'unavailable']) ? $case : 'current');
        $this->snapshot($quote);
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertNull($alert->fresh()->last_price);
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(110));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertDatabaseCount('price_alert_events', 0);
    }

    public function test_long_gaps_and_same_timestamp_corrections_never_count_as_crossings(): void
    {
        $this->liveAlerts();
        $alert = $this->alert();
        $at = now()->timestamp;
        $this->snapshot($this->quote(90, timestamp: $at));
        app(PriceAlerts::class)->evaluate();
        $this->snapshot($this->quote(110, timestamp: $at));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertNull($alert->fresh()->last_price);
        $this->travel(1)->minutes();
        $this->snapshot($this->quote(90));
        app(PriceAlerts::class)->evaluate();
        $this->travel(30)->minutes();
        $this->snapshot($this->quote(110));
        $this->assertSame(0, app(PriceAlerts::class)->evaluate());
        $this->assertDatabaseCount('price_alert_events', 0);
    }

    public function test_exact_decimal_thresholds_and_catalog_units_are_preserved(): void
    {
        $target = '999999999999999.1234567890';
        $alert = $this->alert(['asset' => 'copper', 'target_price' => $target]);
        $this->assertSame($target, $alert->fresh()->target_price);
        $this->assertSame('pound', $alert->unit);
        $this->assertSame('troy_ounce', $this->alert(['asset' => 'gold'])->unit);
        $prices = app(PriceThreshold::class);
        $this->assertSame(1, $prices->compare($target, '999999999999999.1234567889'));
        $this->assertSame(-1, $prices->compare('0.0000000001', '0.0000000002'));
        $this->assertSame('0.0000000001', $this->alert(['asset' => 'xrp', 'target_price' => '0.0000000001'])->fresh()->target_price);
    }
}
