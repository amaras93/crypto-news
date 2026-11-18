<?php

namespace App\Support;

use App\Models\ArticleTranslation;
use InvalidArgumentException;

class PublicUrl
{
    public function base(): string
    {
        $base = rtrim((string) config('app.url'), '/');
        $parts = parse_url($base);

        if (! $parts || ! in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('APP_URL must be an absolute HTTP(S) application URL.');
        }

        return $base;
    }

    public function route(string $name, array $parameters = []): string
    {
        return $this->base().route($name, $parameters, false);
    }

    public function article(ArticleTranslation $translation): string
    {
        $path = route('news.show', ['locale' => $translation->locale, 'slug' => '__article_slug__'], false);
        $path = str_replace('__article_slug__', rawurlencode($translation->slug), $path);

        return $this->base().$path;
    }

    public function isPublicTranslation(ArticleTranslation $translation): bool
    {
        return ($translation->ai_review_status === null || $translation->ai_review_status === 'approved')
            && $this->hasSafeArticleUrl($translation);
    }

    public function hasSafeArticleUrl(ArticleTranslation $translation): bool
    {
        $slug = $translation->slug;

        return array_key_exists($translation->locale, config('news.locales'))
            && is_string($slug) && trim($slug) !== '' && ! in_array($slug, ['.', '..'], true)
            && ! preg_match('/[\p{C}\/\\\\]/u', $slug)
            && strlen($this->article($translation)) < 2048;
    }
}
