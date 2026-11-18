<?php

namespace App\Console\Commands;

use App\ArticleStatus;
use App\Models\Article;
use App\Support\ArticlePublication;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PublishScheduledArticles extends Command
{
    protected $signature = 'articles:publish-scheduled';

    protected $description = 'Publish due, still-approved editorial schedules without duplicate transitions';

    public function handle(ArticlePublication $publication): int
    {
        $published = 0;
        $invalid = 0;
        Article::query()->where('status', ArticleStatus::Scheduled)->where('published_at', '<=', now())
            ->select('id')->chunkById(100, function (Collection $articles) use ($publication, &$published, &$invalid): void {
                foreach ($articles as $article) {
                    try {
                        $published += (int) $publication->publishScheduled($article);
                    } catch (ValidationException) {
                        $invalid++;
                    }
                }
            });
        $this->info($published.' scheduled article(s) published; '.$invalid.' invalid schedule(s) left private.');

        return self::SUCCESS;
    }
}
