<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LocaleRoutingTest extends TestCase
{
    use RefreshDatabase;

    public function test_root_redirects_to_english(): void
    {
        $this->get('/')->assertRedirect('/en');
    }

    #[DataProvider('locales')]
    public function test_homepage_uses_the_url_language(string $locale, string $welcome): void
    {
        $this->get('/'.$locale)
            ->assertOk()
            ->assertSee('<html lang="'.config('news.language_tags.'.$locale).'">', false)
            ->assertSee($welcome)
            ->assertSee('charset="utf-8"', false)
            ->assertSee(route('news.index', ['locale' => $locale]), false);
    }

    public static function locales(): array
    {
        return [
            'english' => ['en', 'Welcome to CryptoNews'],
            'russian' => ['ru', 'Добро пожаловать в CryptoNews'],
            'french' => ['fr', 'Bienvenue sur CryptoNews'],
            'german' => ['de', 'Willkommen bei CryptoNews'],
            'spanish' => ['es', 'Bienvenido a CryptoNews'],
            'italian' => ['it', 'Benvenuti su CryptoNews'],
            'japanese' => ['ja', 'CryptoNewsへようこそ'],
            'chinese' => ['zh', '欢迎来到CryptoNews'],
        ];
    }

    public function test_unsupported_languages_and_paths_are_not_routed(): void
    {
        $this->get('/pt')->assertNotFound();
        $this->get('/pt/news')->assertNotFound();
        $this->get('/en/missing')->assertNotFound();
    }

    public function test_language_switcher_preserves_page_type_and_exposes_alternate_urls(): void
    {
        $response = $this->get('/zh');

        foreach (['en', 'ru', 'fr', 'de', 'es', 'it', 'ja', 'zh'] as $locale) {
            $response->assertSee('href="'.route('home', ['locale' => $locale]).'"', false)
                ->assertSee('hreflang="'.config('news.language_tags.'.$locale).'"', false);
        }
    }
}
