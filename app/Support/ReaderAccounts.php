<?php

namespace App\Support;

use App\Models\User;
use App\Rules\SafePasswordBytes;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidationValidator;

class ReaderAccounts
{
    public static function passwordRules(): array
    {
        return ['bail', 'required', 'string', 'max:64', new SafePasswordBytes, 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()];
    }

    /** @param array<string, mixed> $data */
    public function register(array $data, string $locale): User
    {
        $this->rejectProtected($data);
        $data['email'] = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
        $values = Validator::make($data, [
            'name' => ['required', 'string', 'max:80', 'not_regex:/[<>\p{C}]/u'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')],
            'password' => self::passwordRules(), 'website' => ['nullable', 'size:0'],
        ])->validate();
        abort_unless(array_key_exists($locale, config('news.locales')), 404);

        return DB::transaction(function () use ($values, $locale): User {
            $user = new User(['name' => trim($values['name']), 'email' => $values['email'], 'password' => $values['password']]);
            $user->is_admin = false;
            $user->is_editor = false;
            $user->preferred_locale = $locale;
            $user->save();
            event(new Registered($user));

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function update(User $actor, array $data, ?UploadedFile $avatar = null): User
    {
        $this->rejectProtected($data);

        return DB::transaction(function () use ($actor, $data, $avatar): User {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $data['email'] = is_string($data['email'] ?? null) ? mb_strtolower(trim($data['email'])) : null;
            $values = Validator::make($data, [
                'name' => ['required', 'string', 'max:80', 'not_regex:/[<>\p{C}]/u'],
                'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
                'preferred_locale' => ['required', Rule::in(array_keys(config('news.locales')))],
                'reply_notifications' => ['required', 'boolean'],
                'current_password' => ['bail', $data['email'] !== $user->email ? 'required' : 'nullable', 'string', new SafePasswordBytes],
                'avatar' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048'],
            ])->after(function (ValidationValidator $validator) use ($data, $user): void {
                if ($data['email'] !== $user->email && ! $validator->errors()->has('current_password') && ! Hash::check((string) ($data['current_password'] ?? ''), $user->password)) {
                    $validator->errors()->add('current_password', __('community.password_incorrect'));
                }
            })->validate();
            if ($avatar) {
                Validator::make(['avatar' => $avatar], ['avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:max_width=2048,max_height=2048']])->validate();
                $path = $avatar->storeAs('avatars', Str::uuid().'.'.$avatar->extension(), 'public');
                if (! is_string($path)) {
                    throw ValidationException::withMessages(['avatar' => __('community.invalid')]);
                }
                $user->avatar_path = $path;
            }
            $emailChanged = $user->email !== $values['email'];
            $user->fill(['name' => trim($values['name']), 'email' => $values['email']]);
            $user->preferred_locale = $values['preferred_locale'];
            $user->reply_notifications = (bool) $values['reply_notifications'];
            if ($emailChanged) {
                $user->email_verified_at = null;
                $user->remember_token = Str::random(60);
                DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
            }
            $user->save();
            if ($emailChanged) {
                $user->sendEmailVerificationNotification();
            }

            return $user;
        });
    }

    /** @param array<string, mixed> $data */
    public function changePassword(User $actor, array $data): void
    {
        $this->rejectProtected($data);
        $values = Validator::make($data, ['current_password' => ['bail', 'required', 'string', new SafePasswordBytes, 'current_password:web'], 'password' => self::passwordRules()])->validate();
        DB::transaction(function () use ($actor, $values): void {
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            $user->password = $values['password'];
            $user->remember_token = Str::random(60);
            $user->save();
            DB::table('sessions')->where('user_id', $user->id)->where('id', '!=', session()->getId())->delete();
        });
    }

    /** @param array<string, mixed> $data */
    private function rejectProtected(array $data): void
    {
        if (array_intersect(['id', 'user_id', 'role', 'is_admin', 'is_editor', 'email_verified_at', 'community_suspended_at', 'avatar_path'], array_keys($data))) {
            throw ValidationException::withMessages(['community' => __('community.protected_fields')]);
        }
    }
}
