<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EditorialMedia
{
    public function store(User $actor, UploadedFile $file, string $kind = 'featured'): string
    {
        if (! $actor->fresh()?->isEditorial()) {
            throw new AuthorizationException;
        }
        abort_unless(in_array($kind, ['featured', 'body'], true), 422);
        Validator::make(['image' => $file], [
            'image' => ['required', 'file', 'image', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120', 'dimensions:max_width=6000,max_height=6000'],
        ])->validate();
        $extension = match ($file->getMimeType()) {
            'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        };
        $path = $file->storeAs('articles/'.$kind, Str::uuid().'.'.$extension, 'public');
        if (! $path) {
            throw ValidationException::withMessages(['featured_image' => 'The image could not be stored. Check public disk permissions.']);
        }

        return $path;
    }

    public function validatePath(?string $path, ?string $original = null): void
    {
        if ($path === null || $path === '' || ($path === $original && $original !== null)) {
            return;
        }
        if (! preg_match('#\Aarticles/featured/[a-f0-9-]{36}\.(?:jpg|png|webp)\z#D', $path) || ! Storage::disk('public')->exists($path)) {
            throw ValidationException::withMessages(['featured_image' => 'Select or upload a valid local featured image.']);
        }
        $bytes = Storage::disk('public')->get($path);
        $dimensions = @getimagesizefromstring($bytes);
        if (! $dimensions || ! in_array($dimensions['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || strlen($bytes) > 5120 * 1024 || $dimensions[0] > 6000 || $dimensions[1] > 6000) {
            throw ValidationException::withMessages(['featured_image' => 'The stored image is invalid.']);
        }
    }

    /** @return array<string, string> */
    public function existing(): array
    {
        return collect(Storage::disk('public')->files('articles/featured'))
            ->filter(fn (string $path): bool => preg_match('#\Aarticles/featured/[a-f0-9-]{36}\.(?:jpg|png|webp)\z#D', $path) === 1)
            ->take(100)->mapWithKeys(fn (string $path): array => [$path => basename($path)])->all();
    }
}
