<?php

namespace Tests\Feature;

use App\Models\EngagementDelivery;
use App\Models\NewsletterSubscriber;
use App\Models\User;
use App\Support\NewsletterSubscriptions;
use App\Support\ReaderAccounts;
use App\Support\ReaderEngagement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\EngagementTestCase;

class NewsletterSubscriptionTest extends EngagementTestCase
{
    private function requestData(array $changes = []): array
    {
        return array_replace(['email' => 'reader@example.com', 'locale' => 'en', 'frequency' => 'daily', 'consent' => '1'], $changes);
    }

    public function test_consent_does_not_subscribe_until_owner_confirmation_and_get_does_not_mutate_state(): void
    {
        $this->post('/en/newsletter', $this->requestData())->assertRedirect('/en/newsletter');
        $subscriber = NewsletterSubscriber::sole();
        $this->assertNull($subscriber->confirmed_at);
        $delivery = EngagementDelivery::sole();
        $url = $delivery->payload['confirmation_url'];
        $this->get($url)->assertOk();
        $this->assertNull($subscriber->fresh()->confirmed_at);
        $this->post($url)->assertOk()->assertSee(__('engagement.confirmed'));
        $this->assertNotNull($subscriber->fresh()->confirmed_at);
        $this->post($url)->assertOk();
        $this->assertDatabaseCount('newsletter_consents', 2);
        Http::assertNothingSent();
    }

    public function test_missing_consent_and_unsupported_language_are_rejected(): void
    {
        $data = $this->requestData();
        unset($data['consent']);
        $this->post('/en/newsletter', $data)->assertSessionHasErrors();
        $this->post('/en/newsletter', $this->requestData(['locale' => 'hy']))->assertSessionHasErrors();
        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_unsubscribe_is_authenticated_by_its_link_and_is_idempotent(): void
    {
        app(NewsletterSubscriptions::class)->request($this->requestData());
        $this->post(EngagementDelivery::sole()->payload['confirmation_url'])->assertOk();
        $subscriber = NewsletterSubscriber::sole();
        $url = app(NewsletterSubscriptions::class)->unsubscribeUrl($subscriber);
        $this->get($url)->assertOk();
        $this->assertNull($subscriber->fresh()->unsubscribed_at);
        $this->post($url)->assertOk();
        $this->post($url)->assertOk();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
        $this->assertDatabaseCount('newsletter_consents', 3);
    }

    public function test_expired_and_tampered_confirmation_links_cannot_subscribe(): void
    {
        app(NewsletterSubscriptions::class)->request($this->requestData());
        $url = EngagementDelivery::sole()->payload['confirmation_url'];
        $this->post($url.'&tampered=1')->assertStatus(410);
        $this->travel(49)->hours();
        $this->post($url)->assertStatus(410);
        $this->assertNull(NewsletterSubscriber::sole()->confirmed_at);
    }

    public function test_repeated_requests_are_generic_and_do_not_change_active_preferences_without_confirmation(): void
    {
        $subscriber = $this->subscriber('ja', 'weekly');
        $this->travel(31)->minutes();
        $data = $this->requestData(['email' => $subscriber->email, 'locale' => 'en', 'frequency' => 'daily']);
        $this->post('/en/newsletter', $data)->assertSessionHas('community_status', __('engagement.subscription_requested'));
        $this->post('/en/newsletter', $data)->assertSessionHas('community_status', __('engagement.subscription_requested'));
        $this->assertSame('ja', $subscriber->fresh()->locale);
        $this->assertSame('weekly', $subscriber->fresh()->frequency);
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->assertSame(2, EngagementDelivery::where('kind', 'confirmation')->count());
        $url = EngagementDelivery::latest('created_at')->where('confirmation_generation', 2)->sole()->payload['confirmation_url'];
        $this->post($url)->assertOk();
        $this->assertSame('en', $subscriber->fresh()->locale);
    }

    public function test_unsubscribe_revokes_pending_confirmation_and_resubscription_requires_a_new_link(): void
    {
        app(NewsletterSubscriptions::class)->request($this->requestData());
        $old = EngagementDelivery::sole()->payload['confirmation_url'];
        $this->post($old)->assertOk();
        $subscriber = NewsletterSubscriber::sole();
        $unsubscribe = app(NewsletterSubscriptions::class)->unsubscribeUrl($subscriber);
        $this->post($unsubscribe.'&tampered=1')->assertStatus(410);
        $this->post($unsubscribe)->assertOk();
        $this->post($old)->assertStatus(410);
        $this->travel(31)->minutes();
        app(NewsletterSubscriptions::class)->request($this->requestData());
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
        $fresh = EngagementDelivery::where('confirmation_generation', 2)->sole()->payload['confirmation_url'];
        $this->post($fresh)->assertOk();
        $this->assertNull($subscriber->fresh()->unsubscribed_at);
        $this->post($unsubscribe)->assertStatus(410);
    }

    public function test_only_verified_email_owners_can_manage_account_preferences(): void
    {
        $subscriber = $this->subscriber(actor: $this->reader);
        $this->patch('/en/account/newsletter', ['locale' => 'ja', 'frequency' => 'weekly'])->assertRedirect();
        $this->assertSame('ja', $subscriber->fresh()->locale);
        $this->actingAs(User::factory()->create());
        $this->patch('/en/account/newsletter', ['locale' => 'de', 'frequency' => 'daily', 'user_id' => $this->reader->id])->assertForbidden();
        $this->reader->forceFill(['email_verified_at' => null])->save();
        $this->actingAs($this->reader);
        $this->patch('/en/account/newsletter', ['locale' => 'de', 'frequency' => 'daily'])->assertForbidden();
        $this->assertSame('ja', $subscriber->fresh()->locale);
    }

    public function test_sensitive_email_and_unsubscribe_secrets_are_encrypted_and_not_serialized(): void
    {
        app(NewsletterSubscriptions::class)->request($this->requestData());
        $subscriber = NewsletterSubscriber::sole();
        $raw = DB::table('newsletter_subscribers')->first();
        $this->assertNotSame('reader@example.com', $raw->email);
        $this->assertNotSame($subscriber->unsubscribe_secret, $raw->unsubscribe_secret);
        foreach (['email', 'email_hash', 'unsubscribe_secret', 'unsubscribe_token_hash', 'confirmation_token_hash'] as $secret) {
            $this->assertArrayNotHasKey($secret, $subscriber->toArray());
        }
        $delivery = EngagementDelivery::sole();
        $this->assertStringNotContainsString('confirmation_url', $delivery->getRawOriginal('payload'));
        $this->assertArrayNotHasKey('payload', $delivery->toArray());
    }

    public function test_subscription_endpoint_is_rate_limited_and_rejects_honeypot_or_owner_spoofing(): void
    {
        $this->post('/en/newsletter', $this->requestData(['website' => 'spam']))->assertSessionHasErrors();
        $this->post('/en/newsletter', $this->requestData(['user_id' => 999]))->assertSessionHasErrors();
        $this->post('/en/newsletter', $this->requestData(['email' => ['malformed']]))->assertSessionHasErrors('email');
        for ($index = 0; $index < 4; $index++) {
            $this->post('/en/newsletter', $this->requestData(['email' => 'flood@example.com']));
        }
        $this->assertDatabaseCount('newsletter_subscribers', 1);
        $this->post('/en/newsletter', $this->requestData(['email' => 'new@example.com']))->assertSessionHasErrors();
    }

    public function test_authorized_account_deletion_withdraws_linked_and_verified_guest_subscriptions(): void
    {
        $subscriber = $this->subscriber(actor: $this->reader);
        $subscriber->forceFill(['user_id' => null])->save();
        app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => 'bitcoin']);
        $this->alert();
        $this->assertFalse(Gate::forUser($this->reader)->allows('delete', $this->reader));
        $this->reader->delete();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
        $this->assertDatabaseCount('watchlist_items', 0);
        $this->assertDatabaseCount('price_alerts', 0);
    }

    public function test_registering_a_reader_never_subscribes_them_even_if_an_extra_newsletter_field_is_posted(): void
    {
        Notification::fake();
        $reader = app(ReaderAccounts::class)->register(['name' => 'New reader', 'email' => 'new@example.com',
            'password' => 'FreshReader!2026', 'password_confirmation' => 'FreshReader!2026', 'newsletter' => true], 'ja');
        $this->assertFalse($reader->hasVerifiedEmail());
        $this->assertDatabaseCount('newsletter_subscribers', 0);
        $this->assertDatabaseCount('newsletter_consents', 0);
        $this->assertDatabaseCount('engagement_deliveries', 0);
    }
}
