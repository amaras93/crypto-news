<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class EditorialTaxonomy
{
    /** @param array<string, mixed> $data */
    public function category(User $actor, array $data, ?Category $category = null): Category
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;

        return DB::transaction(function () use ($actor, $data, $category): Category {
            $record = $category ? Category::query()->lockForUpdate()->findOrFail($category->id) : new Category;
            Gate::forUser($actor)->authorize($record->exists ? 'update' : 'create', $record->exists ? $record : Category::class);
            $values = Validator::make($data, [
                'sort_order' => ['required', 'integer', 'min:0', 'max:100000'],
                'translations' => ['required', 'array:'.implode(',', array_keys(config('news.locales')))],
            ])->validate();
            $record->loadMissing('translations');
            $translations = [];
            foreach ($values['translations'] as $locale => $translation) {
                if (! is_array($translation)) {
                    throw ValidationException::withMessages(['translations' => 'Invalid category translation.']);
                }
                if (! ($translation['enabled'] ?? false)) {
                    continue;
                }
                $existing = $record->translation($locale);
                if ($existing && ($translation['slug'] ?? null) !== $existing->slug) {
                    throw ValidationException::withMessages(['translations.'.$locale.'.slug' => 'Existing category topic slugs are locked to preserve navigation.']);
                }
                $validator = Validator::make($translation, [
                    'name' => ['required', 'string', 'max:255'],
                    'slug' => ['required', 'string', 'max:190', 'regex:/\A[\p{L}\p{N}]+(?:[-_][\p{L}\p{N}]+)*\z/u',
                        Rule::unique('category_translations', 'slug')->where('locale', $locale)->ignore($existing?->id)],
                    'description' => ['nullable', 'string', 'max:2000'],
                    'meta_title' => ['nullable', 'string', 'max:255'], 'meta_description' => ['nullable', 'string', 'max:2000'],
                ]);
                if ($validator->fails()) {
                    throw ValidationException::withMessages(collect($validator->errors()->messages())
                        ->mapWithKeys(fn (array $messages, string $key): array => ['translations.'.$locale.'.'.$key => $messages])->all());
                }
                $translations[$locale] = $validator->validated();
            }
            if (! $translations && ! $record->translations->whereIn('locale', array_keys(config('news.locales')))->count()) {
                throw ValidationException::withMessages(['translations' => 'Complete at least one public category translation.']);
            }
            $record->fill(['sort_order' => $values['sort_order']])->save();
            foreach ($translations as $locale => $translation) {
                $record->translations()->updateOrCreate(['locale' => $locale], $translation);
            }

            return $record->fresh('translations');
        });
    }

    /** @param array<string, mixed> $data */
    public function tag(User $actor, array $data, ?Tag $tag = null): Tag
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;
        Gate::forUser($actor)->authorize($tag ? 'update' : 'create', $tag ?? Tag::class);
        if ($tag && ($data['slug'] ?? null) !== $tag->slug) {
            throw ValidationException::withMessages(['slug' => 'Existing tag slugs are locked to preserve editorial filters.']);
        }
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:190', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', Rule::unique('tags', 'slug')->ignore($tag?->id)],
        ])->validate();
        $tag ??= new Tag;
        $tag->fill($values)->save();

        return $tag;
    }

    public function delete(User $actor, Category|Tag $record): void
    {
        $actor = $actor->fresh() ?? throw new AuthorizationException;
        DB::transaction(function () use ($actor, $record): void {
            $record = $record->newQuery()->lockForUpdate()->findOrFail($record->id);
            Gate::forUser($actor)->authorize('delete', $record);
            $record->delete();
        });
    }
}
