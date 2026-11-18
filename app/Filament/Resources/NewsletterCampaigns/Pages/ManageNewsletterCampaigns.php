<?php

namespace App\Filament\Resources\NewsletterCampaigns\Pages;

use App\Filament\Resources\NewsletterCampaigns\NewsletterCampaignResource;
use App\Models\NewsletterCampaign;
use App\Support\NewsDigests;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Resources\Pages\ManageRecords;

class ManageNewsletterCampaigns extends ManageRecords
{
    protected static string $resource = NewsletterCampaignResource::class;

    protected function getHeaderActions(): array
    {
        return [Action::make('build_digest')->label('Build draft digest')->schema([
            Select::make('locale')->options(config('news.locales'))->required()->default('en'),
            Select::make('frequency')->options(['daily' => 'Daily', 'weekly' => 'Weekly'])->required()->default('daily'),
        ])->action(fn (array $data): NewsletterCampaign => app(NewsDigests::class)->build(auth()->user(), $data['locale'], $data['frequency']))];
    }
}
