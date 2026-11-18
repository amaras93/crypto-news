<?php

namespace Tests\Feature;

use App\Mail\EngagementMessage;
use App\Models\EngagementDelivery;
use App\Support\NewsletterSubscriptions;
use App\Support\ReaderEngagement;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\EngagementTestCase;

class EngagementLocaleTest extends EngagementTestCase
{
    public static function locales(): array
    {
        return array_map(fn (string $locale): array => [$locale], ['en', 'ru', 'fr', 'de', 'es', 'it', 'ja', 'zh']);
    }

    #[DataProvider('locales')]
    public function test_account_sections_and_public_subscription_form_are_localized_private_and_offline(string $locale): void
    {
        app(ReaderEngagement::class)->addAsset($this->reader, ['asset' => 'bitcoin']);
        $this->alert();
        foreach (['watchlist', 'saved', 'alerts', 'notifications', 'newsletter'] as $section) {
            $response = $this->get('/'.$locale.'/account/'.$section)->assertOk()
                ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
                ->assertSee('<html lang="'.config('news.language_tags.'.$locale).'">', false);
            $response->assertSee(__('engagement.'.($section === 'newsletter' ? 'newsletter' : $section), [], $locale))
                ->assertDontSee('engagement.')->assertDontSee('rel="canonical"', false);
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->get('/'.$locale.'/account/watchlist')->assertSee(__('market.demo_label', [], $locale));
        $this->app['auth']->forgetGuards();
        $this->get('/'.$locale.'/newsletter')->assertOk()->assertSee(__('engagement.consent_label', [], $locale));
        Http::assertNothingSent();
    }

    #[DataProvider('locales')]
    public function test_email_html_and_text_render_in_selected_language_without_english_fallback(string $locale): void
    {
        $subscriber = $this->subscriber($locale);
        app()->setLocale('en');
        $message = (new EngagementMessage(['kind' => 'confirmation', 'confirmation_url' => 'https://example.test/confirm'], Str::uuid()->toString()))->locale($locale);
        $html = $message->render();
        $this->assertStringContainsString(e(__('engagement.confirm_mail', [], $locale)), $html);
        $this->assertStringContainsString('<html lang="'.config('news.language_tags.'.$locale).'">', $html);
        $this->assertStringNotContainsString('engagement.', $html);
        $message->assertSeeInText(__('engagement.confirm_action', [], $locale));
        $this->assertSame('en', app()->getLocale());
        if ($locale !== 'en') {
            $this->assertStringNotContainsString(__('engagement.confirm_mail', [], 'en'), $html);
        }
    }

    public function test_all_engagement_catalogs_have_matching_keys_and_interpolation_parameters(): void
    {
        $english = require lang_path('en/engagement.php');
        foreach (array_keys(config('news.locales')) as $locale) {
            $translated = require lang_path($locale.'/engagement.php');
            $this->assertEqualsCanonicalizing(array_keys($english), array_keys($translated));
            foreach ($english as $key => $value) {
                preg_match_all('/:[a-z_]+/', $value, $expected);
                preg_match_all('/:[a-z_]+/', $translated[$key], $actual);
                sort($expected[0]);
                sort($actual[0]);
                $this->assertSame($expected[0], $actual[0], $locale.'.'.$key);
                $this->assertNotSame('', $translated[$key]);
            }
        }
    }

    #[DataProvider('locales')]
    public function test_localized_digest_emails_include_short_excerpts_links_dates_and_unsubscribe(string $locale): void
    {
        $story = $this->article($locale);
        $translation = $story->translation($locale);
        $translation->update(['excerpt' => null, 'body' => '<p>'.str_repeat('Editorial context. ', 40).'PRIVATE_FULL_ARTICLE_TAIL</p>']);
        $story->load('translations');
        $message = (new EngagementMessage(['kind' => 'digest', 'articles' => collect([$story]), 'frequency' => 'weekly',
            'unsubscribe_url' => 'https://example.test/unsubscribe'], Str::uuid()->toString()))->locale($locale);
        $html = $message->render();
        $this->assertStringContainsString(e(__('engagement.digest_subject_weekly', [], $locale)), $html);
        $this->assertStringContainsString(e(__('engagement.unsubscribe', [], $locale)), $html);
        $this->assertStringContainsString('/'.$locale.'/news/'.$translation->slug, $html);
        $this->assertStringContainsString('Editorial context.', $html);
        $this->assertStringNotContainsString('PRIVATE_FULL_ARTICLE_TAIL', $html);
        $this->assertStringNotContainsString('engagement.', $html);
        $message->assertSeeInText('https://example.test/unsubscribe');
    }

    #[DataProvider('locales')]
    public function test_validation_errors_use_the_selected_locale(string $locale): void
    {
        $this->from('/'.$locale.'/account/alerts')->post('/'.$locale.'/account/alerts', $this->alertData(['target_price' => 'NaN']))
            ->assertSessionHasErrors(['target_price' => __('engagement.invalid_threshold', [], $locale)]);
        $this->post('/'.$locale.'/account/watchlist', ['asset' => 'invented'])
            ->assertSessionHasErrors(['asset' => __('engagement.invalid', [], $locale)]);
    }

    public function test_unsupported_locale_and_private_token_language_switches_do_not_leak_secrets(): void
    {
        $this->get('/hy/account/watchlist')->assertNotFound();
        $this->get('/hy/newsletter')->assertNotFound();
        app(NewsletterSubscriptions::class)->request(['email' => 'secret@example.com', 'locale' => 'ja', 'frequency' => 'daily', 'consent' => true]);
        $url = EngagementDelivery::sole()->payload['confirmation_url'];
        $response = $this->get($url)->assertOk();
        $response->assertSee(route('newsletter.form', ['locale' => 'en']), false)
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertSame(1, substr_count($response->getContent(), e(parse_url($url, PHP_URL_PATH))));
    }
}
