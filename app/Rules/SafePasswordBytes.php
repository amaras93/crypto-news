<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class SafePasswordBytes implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && (str_contains($value, "\0") || ((string) config('hashing.driver', 'bcrypt') === 'bcrypt' && strlen($value) > 72))) {
            $fail(__('community.password_bytes'));
        }
    }
}
