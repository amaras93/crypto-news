<?php

namespace App\Models;

use Database\Factories\CategoryTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

#[Fillable(['category_id', 'locale', 'name', 'slug', 'description', 'meta_title', 'meta_description', 'canonical_url'])]
class CategoryTranslation extends Model
{
    /** @use HasFactory<CategoryTranslationFactory> */
    use HasFactory;

    /** @return list<string|In> */
    public static function publicLocaleRules(): array
    {
        return ['required', 'string', Rule::in(array_keys(config('news.locales')))];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }
}
