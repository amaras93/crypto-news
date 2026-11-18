<?php

namespace App\Filament\Pages;

use App\Rules\SafePasswordBytes;
use Filament\Auth\Pages\Login;
use Filament\Schemas\Components\Component;

class SecureLogin extends Login
{
    protected function getPasswordFormComponent(): Component
    {
        return parent::getPasswordFormComponent()->rules(['bail', 'string', new SafePasswordBytes]);
    }
}
