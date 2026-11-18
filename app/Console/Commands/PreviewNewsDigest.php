<?php

namespace App\Console\Commands;

use App\Support\NewsDigests;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Support\Traits\Localizable;

class PreviewNewsDigest extends Command
{
    use Localizable;

    protected $signature = 'engagement:preview {--locale=en} {--frequency=daily}';

    protected $description = 'Write a private noindex digest preview without creating a campaign or sending email';

    public function handle(NewsDigests $digests): int
    {
        $locale = (string) $this->option('locale');
        $frequency = (string) $this->option('frequency');
        if (! array_key_exists($locale, config('news.locales')) || ! in_array($frequency, ['daily', 'weekly'], true)) {
            $this->error('Use a public locale and daily or weekly.');

            return self::FAILURE;
        }
        $path = base_path('.tools/engagement-preview-'.Str::uuid().'.html');
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        $html = $this->withLocale($locale, fn (): string => view('emails.engagement', ['messageData' => ['kind' => 'digest',
            'articles' => $digests->select($locale, $frequency), 'frequency' => $frequency, 'is_preview' => true, 'unsubscribe_url' => null]])->render());
        file_put_contents($path, $html);
        $this->info('Private preview: '.$path);
        $this->info('No campaign, subscriber or delivery created; no email sent.');

        return self::SUCCESS;
    }
}
