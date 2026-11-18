<?php

namespace Tests;

use App\Models\Article;
use App\Models\ArticleTranslation;
use App\Models\EngagementDelivery;
use App\Models\EngagementSetting;
use App\Models\NewsletterSubscriber;
use App\Models\PriceAlert;
use App\Models\User;
use App\Support\MarketDataService;
use App\Support\MarketQuote;
use App\Support\NewsletterSubscriptions;
use App\Support\PriceAlerts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

abstract class EngagementTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $reader;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        $this->reader = User::factory()->create();
        $this->actingAs($this->reader);
    }

    protected function article(string $locale = 'en', bool $published = true): Article
    {
        $article = $published ? Article::factory()->published()->create() : Article::factory()->create();
        ArticleTranslation::factory()->create(['article_id' => $article->id, 'locale' => $locale]);

        return $article->fresh(['translations']);
    }

    protected function liveAlerts(): void
    {
        config(['markets.mode' => 'live', 'markets.rights_confirmed' => true, 'engagement.price_alerts_enabled' => true]);
        EngagementSetting::updateOrCreate(['id' => 1], ['price_alerts_enabled' => true]);
    }

    protected function alertData(array $changes = []): array
    {
        return array_replace(['asset' => 'bitcoin', 'target_price' => '100', 'direction' => 'above',
            'enabled' => true, 'in_app' => true, 'email' => false], $changes);
    }

    protected function alert(array $changes = []): PriceAlert
    {
        return app(PriceAlerts::class)->save($this->reader, $this->alertData($changes));
    }

    protected function quote(float $price, string $asset = 'bitcoin', string $status = 'current', ?int $timestamp = null): MarketQuote
    {
        $definition = config('markets.assets.'.$asset);

        return new MarketQuote($asset, $definition['symbol'], $asset, 'USD', $price, 1.2,
            $definition['group'] === 'crypto' ? 'coingecko' : 'goldapi', $timestamp ?? now()->timestamp, now()->timestamp, $definition['unit'], $status);
    }

    protected function snapshot(MarketQuote $quote): void
    {
        $this->mock(MarketDataService::class, function ($mock) use ($quote): void {
            $mock->shouldReceive('snapshot')->andReturn(['mode' => config('markets.mode'), 'quotes' => [$quote]]);
            $mock->shouldReceive('interval')->andReturn(600);
            $mock->shouldReceive('blockReason')->andReturn(null);
        });
        app()->forgetInstance(PriceAlerts::class);
    }

    protected function subscriber(string $locale = 'en', string $frequency = 'daily', ?User $actor = null): NewsletterSubscriber
    {
        $email = $actor?->email ?? (Str::lower(Str::random(12)).'@example.com');
        $subscriptions = app(NewsletterSubscriptions::class);
        $subscriptions->request(['email' => $email, 'locale' => $locale, 'frequency' => $frequency, 'consent' => true], $actor);
        $subscriber = NewsletterSubscriber::where('email_hash', $subscriptions->emailHash($email))->firstOrFail();
        $delivery = EngagementDelivery::where('newsletter_subscriber_id', $subscriber->id)->where('kind', 'confirmation')->firstOrFail();
        $token = basename(parse_url($delivery->payload['confirmation_url'], PHP_URL_PATH));
        $this->assertTrue($subscriptions->confirm($subscriber->public_id, $token));

        return $subscriber->fresh();
    }
}
