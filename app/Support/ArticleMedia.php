<?php

namespace App\Support;

use App\Models\Article;
use Illuminate\Support\Facades\Storage;

class ArticleMedia
{
    public function __construct(private PublicUrl $urls) {}

    public function resolve(?string $path): ?string
    {
        if (! $path || ! preg_match('/\.(?:avif|gif|jpe?g|png|svg|webp)$/i', $path) || str_contains($path, '..') || strpbrk($path, ':\\?#%') !== false
            || str_starts_with($path, '/') || preg_match('/[\p{C}]/u', $path)) {
            return null;
        }

        if (preg_match('#^editorial/[a-z-]+\.svg$#', $path)) {
            return is_file(public_path('images/'.$path)) ? $this->urls->base().'/images/'.$path : null;
        }

        if (! Storage::disk('public')->exists($path)) {
            return null;
        }

        return $this->urls->base().'/storage/'.implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    /** @return array{width: int, height: int, type: string}|null */
    public function metadata(?string $path): ?array
    {
        if (! $this->resolve($path)) {
            return null;
        }
        if (preg_match('#^editorial/[a-z-]+\.svg$#', $path)) {
            $svg = file_get_contents(public_path('images/'.$path));
            if (preg_match('/<svg\b[^>]*\bwidth="(\d+)"[^>]*\bheight="(\d+)"/', $svg, $dimensions)) {
                return ['width' => (int) $dimensions[1], 'height' => (int) $dimensions[2], 'type' => 'image/svg+xml'];
            }

            return null;
        }
        $dimensions = @getimagesizefromstring(Storage::disk('public')->get($path));

        return $dimensions ? ['width' => $dimensions[0], 'height' => $dimensions[1], 'type' => $dimensions['mime']] : null;
    }

    public function hero(Article $article, ?string $categorySlug = null): array
    {
        $source = $this->resolve($article->featured_image);
        $artwork = match ($categorySlug) {
            'bitcoin' => 'bitcoin',
            'ethereum' => 'ethereum',
            'gold' => 'gold',
            'silver' => 'silver',
            'ai', 'altcoins', 'blockchain' => 'network',
            default => 'markets',
        };
        $fallbackUrl = $this->resolve('editorial/'.$artwork.'.svg') ?? $this->urls->base().'/images/editorial/markets.svg';

        return [
            'url' => $source ?? $fallbackUrl,
            'fallbackUrl' => $fallbackUrl,
            'fallback' => $source === null,
            'theme' => match ($categorySlug) {
                'gold' => 'gold',
                'silver' => 'silver',
                'ethereum', 'ai' => 'violet',
                default => 'emerald',
            },
        ];
    }
}
