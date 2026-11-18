<?php

namespace App\Support;

use App\ArticleStatus;
use App\ContentSource;
use App\Models\Article;
use App\Models\EditorialEvent;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ArticleEditor
{
    public function __construct(private ArticleContent $content, private EditorialMedia $media) {}

    /** @param array<string, mixed> $data */
    public function save(User $actor, array $data, ?Article $article = null, ContentSource $source = ContentSource::Manual): Article
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;

        return DB::transaction(function () use ($actor, $data, $article, $source): Article {
            $record = $article ? Article::query()->lockForUpdate()->findOrFail($article->id) : new Article;
            $sponsoredBeforeDigest = $record->isSponsored() ? app(SponsoredContentService::class)->digest($record) : null;
            Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : Article::class);
            if (! $record->exists) {
                $record->content_source = $source;
            }
            $validated = Validator::make($data, [
                'author_id' => ['required', 'integer', 'exists:users,id'],
                'category_id' => ['nullable', 'integer', 'exists:categories,id'],
                'tag_ids' => ['sometimes', 'array'], 'tag_ids.*' => ['integer', 'distinct', 'exists:tags,id'],
                'featured_image' => ['nullable', 'string', 'max:255'],
                'existing_featured_image' => ['nullable', 'string', 'max:255'],
                'image_credit' => ['nullable', 'string', 'max:500'],
                'translations' => ['required', 'array:'.implode(',', array_keys(config('news.locales')))],
                'status' => ['prohibited'], 'reviewer_id' => ['prohibited'], 'approved_at' => ['prohibited'], 'published_at' => ['prohibited'],
            ])->validate();
            if (! $actor->is_admin && (int) $validated['author_id'] !== $actor->id) {
                throw new AuthorizationException;
            }
            $path = $validated['existing_featured_image'] ?? ($validated['featured_image'] ?? null);
            $this->media->validatePath($path, $record->featured_image);
            $record->fill([
                'author_id' => $validated['author_id'], 'category_id' => $validated['category_id'] ?? null,
                'featured_image' => $path ?: null, 'image_credit' => $validated['image_credit'] ?? null,
            ]);
            $record->loadMissing('translations');
            $translations = [];
            foreach ($validated['translations'] as $locale => $values) {
                if (! is_array($values)) {
                    throw ValidationException::withMessages(['translations.'.$locale => 'Invalid translation.']);
                }
                Validator::make($values, ['enabled' => ['sometimes', 'boolean']])->validate();
                if (! ($values['enabled'] ?? false)) {
                    continue;
                }
                $existing = $record->translation($locale);
                if ($record->published_at && $existing && ($values['slug'] ?? null) !== $existing->slug) {
                    throw ValidationException::withMessages(['translations.'.$locale.'.slug' => 'Existing publication URLs are locked.']);
                }
                if (array_key_exists('summary_text', $values)) {
                    $values['summary'] = array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $values['summary_text']) ?: []), fn (string $point): bool => $point !== ''));
                }
                if (empty($values['summary'])) {
                    $values['summary'] = null;
                    $values['summary_source'] = null;
                } else {
                    $values['summary_source'] = $values['summary_source'] ?? 'manual';
                }
                $slugRules = ['required', 'string', 'max:190', Rule::unique('article_translations', 'slug')->where('locale', $locale)->ignore($existing?->id)];
                if (! $existing || ($values['slug'] ?? null) !== $existing->slug) {
                    $slugRules[] = 'regex:/\A[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*\z/u';
                    $slugRules[] = 'lowercase';
                }
                $validator = Validator::make($values, [
                    'title' => ['required', 'string', 'max:255'], 'slug' => $slugRules,
                    'excerpt' => ['nullable', 'string', 'max:2000'], 'body' => ['required', 'string', 'max:500000'],
                    'meta_title' => ['nullable', 'string', 'max:255'], 'meta_description' => ['nullable', 'string', 'max:2000'],
                    'image_alt' => ['nullable', 'string', 'max:255'],
                    'summary' => ['nullable', 'array', 'min:3', 'max:5'], 'summary.*' => ['required', 'string', 'max:500'],
                    'summary_source' => ['nullable', Rule::in(['manual', 'ai'])],
                ]);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(collect($validator->errors()->messages())
                        ->mapWithKeys(fn (array $messages, string $key): array => ['translations.'.$locale.'.'.$key => $messages])->all());
                }
                $translation = $validator->validated();
                $rendered = $this->content->render($translation['body']);
                if ($rendered['text'] === '') {
                    throw ValidationException::withMessages(['translations.'.$locale.'.body' => 'Enter readable article content.']);
                }
                $translation['body'] = $this->content->sanitize($translation['body']);
                $translations[$locale] = $translation;
            }
            if (! $translations && ! $record->translations->whereIn('locale', array_keys(config('news.locales')))->count()) {
                throw ValidationException::withMessages(['translations' => 'Enable and complete at least one public-language translation.']);
            }
            if ($record->status !== ArticleStatus::Published) {
                $record->status = $actor->is_admin && $record->status === ArticleStatus::PendingReview ? ArticleStatus::PendingReview : ArticleStatus::Draft;
                $record->reviewer_id = null;
                $record->approved_at = null;
            } else {
                $record->reviewer_id = $actor->id;
                $record->approved_at = now();
            }
            $record->save();
            $englishChanged = false;
            foreach ($translations as $locale => $translation) {
                $saved = $record->translations()->firstOrNew(['locale' => $locale]);
                $beforeDigest = $saved->exists ? $saved->contentDigest() : null;
                $saved->fill($translation);
                if ($record->ai_generation_run_id && $saved->contentDigest() !== $beforeDigest) {
                    $saved->forceFill(['ai_review_status' => 'pending', 'ai_review_hash' => null, 'ai_reviewed_by' => null,
                        'ai_reviewed_at' => null, 'ai_human_edited_at' => now()]);
                    $englishChanged = $englishChanged || $locale === 'en';
                    $record->forceFill(['status' => ArticleStatus::Draft, 'approved_at' => null, 'reviewer_id' => null])->save();
                }
                $saved->save();
            }
            if ($englishChanged) {
                $record->translations()->where('locale', '<>', 'en')->whereIn('locale', array_keys(config('news.locales')))
                    ->update(['ai_review_status' => 'pending', 'ai_review_hash' => null, 'ai_reviewed_by' => null, 'ai_reviewed_at' => null]);
            }
            $record->tags()->sync($validated['tag_ids'] ?? []);
            if ($sponsoredBeforeDigest !== null && $sponsoredBeforeDigest !== app(SponsoredContentService::class)->digest($record->fresh(['translations', 'tags']))) {
                app(SponsoredContentService::class)->invalidate($record);
                $record->save();
            }
            if (array_key_exists('sponsorship', $data) && $data['sponsorship'] !== []) {
                if (! is_array($data['sponsorship'])) {
                    throw ValidationException::withMessages(['sponsorship' => 'Invalid sponsorship information.']);
                }
                if (! $actor->is_admin) {
                    $unchanged = ['classification' => $record->sponsor_classification, 'sponsor_id' => $record->sponsor_id, 'disclosures' => $record->sponsorship_disclosures ?? []];
                    if ($data['sponsorship'] != $unchanged) {
                        throw new AuthorizationException;
                    }
                } else {
                    try {
                        app(SponsoredContentService::class)->configure($actor, $record, $data['sponsorship']);
                    } catch (ValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['sponsorship.'.$key => $messages])->all());
                    }
                }
            }
            EditorialEvent::create(['article_id' => $record->id, 'user_id' => $actor->id, 'action' => 'content_saved']);

            return $record->fresh(['translations', 'tags', 'author', 'category.translations']);
        });
    }

    /** @return array<string, mixed> */
    public function formData(Article $article): array
    {
        $article->load('translations', 'tags');
        $data = $article->only(['author_id', 'category_id', 'featured_image', 'image_credit']);
        $data['tag_ids'] = $article->tags->modelKeys();
        $data['sponsorship'] = ['classification' => $article->sponsor_classification, 'sponsor_id' => $article->sponsor_id, 'disclosures' => $article->sponsorship_disclosures ?? []];
        $data['translations'] = [];
        foreach (array_keys(config('news.locales')) as $locale) {
            $translation = $article->translation($locale);
            $data['translations'][$locale] = $translation ? array_merge(
                $translation->only(['title', 'slug', 'excerpt', 'body', 'meta_title', 'meta_description', 'summary_source', 'image_alt']),
                ['enabled' => true, 'summary_text' => implode("\n", $translation->summary ?? [])],
            ) : ['enabled' => false];
        }

        return $data;
    }
}
