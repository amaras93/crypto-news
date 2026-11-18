<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AdCreative
{
    public function store(User $actor, UploadedFile $file): string
    {
        abort_unless($actor->fresh()?->is_admin, 403);
        Validator::make(['banner_image' => $file], ['banner_image' => ['required', 'file', 'image',
            'mimetypes:image/jpeg,image/png,image/webp', 'max:2048', 'dimensions:min_width=80,min_height=40,max_width=2400,max_height=1600']])->validate();
        if (! extension_loaded('gd')) {
            throw ValidationException::withMessages(['banner_image' => 'PHP GD is required to sanitize advertising creatives.']);
        }
        $source = file_get_contents($file->getRealPath());
        if (str_contains($source, 'acTL') || (str_starts_with($source, 'RIFF') && (str_contains($source, 'ANIM') || str_contains($source, 'ANMF')))) {
            throw ValidationException::withMessages(['banner_image' => 'Use a static image. Animated creatives are not supported.']);
        }
        $image = @imagecreatefromstring($source);
        if ($image === false) {
            throw ValidationException::withMessages(['banner_image' => 'The banner could not be decoded.']);
        }
        imagesavealpha($image, true);
        ob_start();
        try {
            imagepng($image, null, 6);
            $bytes = ob_get_contents();
        } finally {
            ob_end_clean();
            imagedestroy($image);
        }
        if (! is_string($bytes) || strlen($bytes) > 5120 * 1024) {
            throw ValidationException::withMessages(['banner_image' => 'The sanitized banner exceeds 5 MB.']);
        }
        $path = 'advertising/creatives/'.Str::uuid().'.png';
        if (! Storage::disk('public')->put($path, $bytes)) {
            throw ValidationException::withMessages(['banner_image' => 'The banner could not be stored.']);
        }

        return $path;
    }

    /** @return array{creative_hash: string, creative_width: int, creative_height: int} */
    public function inspect(string $path): array
    {
        if (! preg_match('#\Aadvertising/creatives/[a-f0-9-]{36}\.png\z#D', $path) || ! Storage::disk('public')->exists($path)) {
            throw ValidationException::withMessages(['banner_image' => 'Select a sanitized local advertising creative.']);
        }
        $bytes = Storage::disk('public')->get($path);
        $size = @getimagesizefromstring($bytes);
        if (! $size || $size['mime'] !== 'image/png' || strlen($bytes) > 5120 * 1024 || $size[0] < 80 || $size[1] < 40 || $size[0] > 2400 || $size[1] > 1600) {
            throw ValidationException::withMessages(['banner_image' => 'The creative file is invalid.']);
        }

        return ['creative_hash' => hash('sha256', $bytes), 'creative_width' => $size[0], 'creative_height' => $size[1]];
    }
}
