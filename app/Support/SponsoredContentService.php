<?php

namespace App\Support;

use App\ArticleStatus;
use App\Models\Advertiser;
use App\Models\Article;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SponsoredContentService
{
    public function __construct(private AdvertisingSettings $settings) {}

    public function configure(User $actor, Article $article, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $values = Validator::make($data, [
            'classification' => ['nullable', Rule::in(['sponsored', 'advertisement'])],
            'sponsor_id' => ['nullable', 'integer', 'exists:advertisers,id'],
            'disclosures' => ['nullable', 'array:'.implode(',', array_keys(config('news.locales')))],
            'disclosures.*' => ['nullable', 'string', 'max:2000', 'not_regex:/[<>]/u'],
            'reviewed_by' => ['prohibited'], 'review_hash' => ['prohibited'],
        ])->validate();
        $classification = ($values['classification'] ?? null) ?: null;
        if ($classification && empty($values['sponsor_id'])) {
            throw ValidationException::withMessages(['sponsor_id' => 'Select the actual sponsor.']);
        }
        DB::transaction(function () use ($actor, $article, $values, $classification): void {
            $record = Article::query()->lockForUpdate()->findOrFail($article->id);
            $disclosures = array_filter($values['disclosures'] ?? [], fn (?string $value): bool => filled($value));
            ksort($disclosures);
            $new = ['sponsor_classification' => $classification, 'sponsor_id' => $classification ? (int) $values['sponsor_id'] : null,
                'sponsorship_disclosures' => $classification ? $disclosures : null];
            if ($record->only(array_keys($new)) === $new) {
                return;
            }
            $before = $record->only(array_keys($new));
            $record->forceFill($new);
            $this->invalidate($record);
            $record->save();
            $this->settings->audit($actor, 'sponsorship.configured', 'article', $record->id, ['before' => $before, 'after' => $new]);
        });
    }

    public function invalidate(Article $article): void
    {
        $article->forceFill(['sponsorship_reviewed_by' => null, 'sponsorship_reviewed_at' => null, 'sponsorship_review_hash' => null,
            'status' => ArticleStatus::Draft, 'reviewer_id' => null, 'approved_at' => null]);
    }

    public function digest(Article $article): string
    {
        $article->loadMissing('translations', 'tags');
        $translations = $article->translations->whereIn('locale', array_keys(config('news.locales')))->sortBy('locale')
            ->mapWithKeys(fn ($translation): array => [$translation->locale => $translation->contentDigest()])->all();
        $fields = $article->only(['sponsor_id', 'sponsor_classification', 'sponsorship_disclosures', 'author_id', 'category_id', 'featured_image', 'image_credit']);
        if (is_array($fields['sponsorship_disclosures'])) {
            ksort($fields['sponsorship_disclosures']);
        }

        return hash('sha256', json_encode([$fields,
            $translations, $article->tags->pluck('id')->sort()->values()->all()], JSON_THROW_ON_ERROR));
    }

    public function assertPublishable(Article $article, bool $requireHuman = true): void
    {
        if (! $article->isSponsored()) {
            return;
        }
        $article->loadMissing('sponsor', 'translations', 'sponsorshipReviewer');
        if (! $article->sponsor) {
            throw ValidationException::withMessages(['sponsorship' => 'A real sponsor association is required.']);
        }
        foreach ($article->translations->whereIn('locale', array_keys(config('news.locales'))) as $translation) {
            if (mb_strlen(trim($article->sponsorship_disclosures[$translation->locale] ?? '')) < 30) {
                throw ValidationException::withMessages(['sponsorship' => 'Provide an accurate disclosure of at least 30 characters in each available public translation.']);
            }
            app(AdvertisingManager::class)->assertNoGuarantees($translation->title.' '.strip_tags($translation->body));
        }
        if ($requireHuman && (! $article->sponsorship_reviewed_at || ! $article->sponsorshipReviewer?->is_admin || ! $article->sponsorship_review_hash
            || ! hash_equals($article->sponsorship_review_hash, $this->digest($article)))) {
            throw ValidationException::withMessages(['sponsorship' => 'A current administrator sponsorship/commercial review is required before editorial approval or publication.']);
        }
    }

    public function review(User $actor, Article $article, array $data): void
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        $checks = app(AdvertisingManager::class)->review($data);
        DB::transaction(function () use ($actor, $article, $checks): void {
            $record = Article::query()->lockForUpdate()->with(['translations', 'tags'])->findOrFail($article->id);
            if (! $record->isSponsored() || $record->status !== ArticleStatus::PendingReview) {
                throw ValidationException::withMessages(['notes' => 'Submit the sponsored article to the existing editorial review workflow first.']);
            }
            if (Advertiser::find($record->sponsor_id)?->status !== 'active') {
                throw ValidationException::withMessages(['notes' => 'Verify and activate the actual sponsor before commercial review.']);
            }
            $this->assertPublishable($record, false);
            $record->forceFill(['sponsorship_reviewed_by' => $actor->id, 'sponsorship_reviewed_at' => now(), 'sponsorship_review_hash' => $this->digest($record)])->save();
            $this->settings->audit($actor, 'sponsorship.reviewed', 'article', $record->id, ['checks' => $checks, 'hash' => $record->sponsorship_review_hash]);
        });
    }
}
