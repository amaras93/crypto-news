<?php

namespace App\Models;

use Database\Factories\ArticleTranslationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

#[Fillable(['article_id', 'locale', 'title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description', 'canonical_url', 'og_image', 'summary', 'summary_source', 'image_alt'])]
class ArticleTranslation extends Model
{
    /** @use HasFactory<ArticleTranslationFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return ['summary' => 'array', 'ai_reviewed_at' => 'immutable_datetime', 'ai_human_edited_at' => 'immutable_datetime'];
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiGenerationRun::class, 'ai_run_id');
    }

    public function scopePublicReady(Builder $query): void
    {
        $query->where(fn (Builder $review): Builder => $review->whereNull('ai_review_status')->orWhereIn('ai_review_status', ['approved', 'automated']));
    }

    public function contentDigest(): string
    {
        return hash('sha256', json_encode($this->only(['title', 'slug', 'excerpt', 'body', 'summary', 'summary_source', 'meta_title', 'meta_description', 'image_alt']), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @return list<string|In> */
    public static function publicLocaleRules(): array
    {
        return ['required', 'string', Rule::in(array_keys(config('news.locales')))];
    }

    /** @return list<string> */
    public function summaryPoints(): array
    {
        $points = $this->summary;

        if (! is_array($points) || ! array_is_list($points) || count($points) < 3 || count($points) > 5) {
            return [];
        }

        foreach ($points as $point) {
            if (! is_string($point) || trim($point) === '') {
                return [];
            }
        }

        return array_map('trim', $points);
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }
}
