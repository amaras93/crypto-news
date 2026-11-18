<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['sort_order'])]
class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['sort_order' => 'integer'];
    }

    public function translations(): HasMany
    {
        return $this->hasMany(CategoryTranslation::class);
    }

    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }

    public function translation(?string $locale = null): ?CategoryTranslation
    {
        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale());
    }

    public function hasTopic(string $slug): bool
    {
        return $this->translation('en')?->slug === $slug;
    }

    public function displayName(?string $locale = null): ?string
    {
        $locale ??= app()->getLocale();
        if ($translated = $this->translation($locale)) {
            return $translated->name;
        }
        $topic = $this->translation('en')?->slug;
        $known = array_merge(config('news.crypto_topics'), config('news.metal_topics'), ['markets']);

        return in_array($topic, $known, true) ? trans('ui.'.$topic, [], $locale) : null;
    }
}
