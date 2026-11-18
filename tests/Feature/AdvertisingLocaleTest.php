<?php

namespace Tests\Feature;

use App\Models\Advertiser;
use App\Support\AdvertisingManager;
use App\Support\ArticleEditor;
use App\Support\ArticlePublication;
use App\Support\SponsoredContentService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AdvertisingTestCase;

class AdvertisingLocaleTest extends AdvertisingTestCase
{
    public static function locales(): array
    {
        return array_map(fn (string $locale): array => [$locale], ['en', 'ru', 'fr', 'de', 'es', 'it', 'ja', 'zh']);
    }

    #[DataProvider('locales')]
    public function test_direct_placements_have_localized_labels_and_matching_creative_language(string $locale): void
    {
        $this->enableAds();
        $this->campaign(['languages' => [$locale], 'alt_text' => [$locale => 'Localized creative fixture '.$locale]]);
        $story = $this->story($locale);
        $this->get('/'.$locale.'/news/'.$story->translation($locale)->slug)->assertOk()
            ->assertSee(__('advertising.advertisement', [], $locale))->assertSee('Localized creative fixture '.$locale)
            ->assertDontSee('advertising.')->assertDontSee('googlesyndication');
    }

    #[DataProvider('locales')]
    public function test_sponsored_editorial_disclosure_and_safe_links_work_in_each_locale(string $locale): void
    {
        $sponsor = Advertiser::factory()->create(['status' => 'active', 'public_brand_name' => 'Public fixture brand']);
        $article = app(ArticleEditor::class)->save($this->admin, ['author_id' => $this->admin->id, 'translations' => [$locale => [
            'enabled' => true, 'title' => 'Localized paid article '.$locale, 'slug' => 'paid-'.$locale, 'body' => '<p>Factual product information.</p><p><a href="https://example.com/">Sponsor link</a></p>']],
            'sponsorship' => ['classification' => 'sponsored', 'sponsor_id' => $sponsor->id, 'disclosures' => [$locale => 'Explicit paid relationship disclosure for this isolated language fixture.']]]);
        app(ArticlePublication::class)->transition($this->admin, $article, 'submit_review');
        app(SponsoredContentService::class)->review($this->admin, $article->fresh(), $this->reviewData());
        app(ArticlePublication::class)->transition($this->admin, $article->fresh(), 'publish');
        $this->get('/'.$locale.'/news/paid-'.$locale)->assertOk()->assertSee(__('advertising.sponsored', [], $locale))
            ->assertSee(__('advertising.disclosure', [], $locale))->assertSee('Public fixture brand')->assertDontSee('advertising.')
            ->assertSee('rel="sponsored nofollow noopener noreferrer"', false)->assertSee('hreflang=', false);
    }

    public function test_translation_keys_and_interpolation_parameters_match_without_modifying_the_armenian_archive(): void
    {
        $english = require lang_path('en/advertising.php');
        foreach (array_keys(config('news.locales')) as $locale) {
            $actual = require lang_path($locale.'/advertising.php');
            $this->assertSame(array_keys($english), array_keys($actual));
            foreach ($english as $key => $text) {
                preg_match_all('/:[a-z_]+/', $text, $expected);
                preg_match_all('/:[a-z_]+/', $actual[$key], $parameters);
                $this->assertSame($expected[0], $parameters[0]);
                $this->assertNotSame('', $actual[$key]);
            }
        }
        $this->assertArrayNotHasKey('hy', config('news.locales'));
        $this->get('/hy/news')->assertRedirect('/en/news');
    }

    #[DataProvider('locales')]
    public function test_destination_validation_uses_current_locale(string $locale): void
    {
        app()->setLocale($locale);
        try {
            app(AdvertisingManager::class)->safeUrl('javascript:alert(1)');
            $this->fail('Unsafe URL accepted.');
        } catch (ValidationException $exception) {
            $this->assertSame([__('advertising.invalid_url', [], $locale)], $exception->errors()['destination_url']);
        }
    }
}
