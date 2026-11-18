<?php

namespace App\Support;

use App\Models\Article;

class FrontendLinks
{
    public static function forLocale(string $locale, ?Article $article = null): ?string
    {
        if (! array_key_exists($locale, config('news.locales'))) {
            return null;
        }
        if (request()->routeIs('news.show')) {
            $translation = $article?->translation($locale);

            return $translation && app(PublicUrl::class)->isPublicTranslation($translation)
                ? app(PublicUrl::class)->article($translation)
                : route('news.index', ['locale' => $locale]);
        }

        $parameters = ['locale' => $locale];

        if (request()->routeIs('newsletter.confirm*', 'newsletter.unsubscribe*')) {
            return route('newsletter.form', $parameters);
        }

        if (request()->routeIs('password.reset', 'verification.verify')) {
            return route('login', $parameters);
        }

        if (request()->routeIs('news.index')) {
            $parameters += request()->only(['q', 'category', 'topic']);
        }

        return route(request()->route()->getName(), $parameters);
    }
}
