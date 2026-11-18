<?php

namespace App\Models;

use App\ArticleStatus;
use App\ContentSource;
use Database\Factories\ArticleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable(['author_id', 'category_id', 'status', 'published_at', 'featured_image', 'image_credit', 'content_source', 'reviewer_id', 'approved_at'])]
#[Hidden(['sponsorship_review_hash'])]
class Article extends Model
{
    /** @use HasFactory<ArticleFactory> */
    use HasFactory, SoftDeletes;

    protected function casts(): array
    {
        return [
            'status' => ArticleStatus::class,
            'content_source' => ContentSource::class,
            'ai_simulated' => 'boolean',
            'published_at' => 'immutable_datetime',
            'approved_at' => 'immutable_datetime',
            'sponsorship_disclosures' => 'array',
            'sponsorship_reviewed_at' => 'immutable_datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function aiRun(): BelongsTo
    {
        return $this->belongsTo(AiGenerationRun::class, 'ai_generation_run_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function translations(): HasMany
    {
        return $this->hasMany(ArticleTranslation::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    public function translation(?string $locale = null): ?ArticleTranslation
    {
        return $this->translations->firstWhere('locale', $locale ?? app()->getLocale());
    }

    public function isDemo(): bool
    {
        return $this->tags->contains('slug', 'cryptonews-demo');
    }

    public function isSponsored(): bool
    {
        return in_array($this->sponsor_classification, ['sponsored', 'advertisement'], true);
    }

    public function sponsor(): BelongsTo
    {
        return $this->belongsTo(Advertiser::class, 'sponsor_id')->select(['id', 'public_brand_name']);
    }

    public function sponsorshipReviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sponsorship_reviewed_by');
    }

    public function imageUrl(): string
    {
        $path = $this->featured_image;

        if ($path && preg_match('#^editorial/[a-z-]+\\.svg$#', $path)) {
            return asset('images/'.$path);
        }

        if ($path && ! str_contains($path, '..') && ! str_contains($path, ':') && ! str_starts_with($path, '/')) {
            return Storage::disk('public')->url($path);
        }

        return asset('images/editorial/markets.svg');
    }

    /** @param Builder<Article> $query */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', ArticleStatus::Published)
            ->where('ai_simulated', false)
            ->where(fn (Builder $sponsorship): Builder => $sponsorship->whereNull('sponsor_classification')
                ->orWhere(fn (Builder $paid): Builder => $paid->whereIn('sponsor_classification', ['sponsored', 'advertisement'])
                    ->whereNotNull('sponsor_id')->whereNotNull('sponsorship_review_hash')->whereNotNull('sponsorship_reviewed_at')
                    ->whereHas('sponsorshipReviewer', fn (Builder $reviewer): Builder => $reviewer->where('is_admin', true))))
            ->where(fn (Builder $editorial): Builder => $editorial->whereNull('ai_generation_run_id')->orWhereHas('translations',
                fn (Builder $translation): Builder => $translation->where('locale', 'en')->where('ai_review_status', 'approved')))
            ->whereNotNull('published_at')
            ->where('published_at', '<=', now());
    }

    /** @param Builder<Article> $query */
    public function scopeInLocale(Builder $query, string $locale): void
    {
        $query->withWhereHas('translations', fn (Builder|Relation $translationQuery) => $translationQuery->where('locale', $locale)->publicReady());
    }
}
