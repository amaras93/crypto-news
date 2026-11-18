<?php

namespace App\Filament\Widgets;

use App\Models\Article;
use App\Models\Category;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EditorialStats extends StatsOverviewWidget
{
    protected static ?int $sort = 1;

    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $query = Article::query();
        if (! auth()->user()->is_admin) {
            $query->where('author_id', auth()->id());
        }
        $counts = $query->selectRaw("COUNT(*) as total,
            SUM(CASE WHEN status = 'draft' THEN 1 ELSE 0 END) as drafts,
            SUM(CASE WHEN status = 'pending_review' THEN 1 ELSE 0 END) as reviews,
            SUM(CASE WHEN status = 'published' AND published_at <= ? THEN 1 ELSE 0 END) as published,
            SUM(CASE WHEN status = 'scheduled' OR (status = 'published' AND published_at > ?) THEN 1 ELSE 0 END) as scheduled", [now(), now()])->first();

        return [
            Stat::make('Total articles', (int) $counts->total)->description(auth()->user()->is_admin ? 'All non-deleted articles' : 'Your articles only'),
            Stat::make('Published articles', (int) $counts->published),
            Stat::make('Draft articles', (int) $counts->drafts),
            Stat::make('Awaiting review', (int) $counts->reviews),
            Stat::make('Scheduled articles', (int) $counts->scheduled),
            Stat::make('Categories', Category::count()),
        ];
    }
}
