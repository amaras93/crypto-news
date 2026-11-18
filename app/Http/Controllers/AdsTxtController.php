<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\AdvertisingSettings;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class AdsTxtController extends Controller
{
    public function __invoke(AdvertisingSettings $settings): Response
    {
        $state = $settings->current();
        $lines = [];
        if ($state['ads_txt_verified'] && User::find($state['updated_by'])?->is_admin) {
            try {
                $lines = $settings->sellerEntries($state['ads_txt']);
            } catch (ValidationException) {
                $lines = [];
            }
        }

        return response($lines ? implode("\n", $lines)."\n" : "# No verified authorized sellers configured.\n", 200,
            ['Content-Type' => 'text/plain; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=300']);
    }
}
