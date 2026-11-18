<?php

namespace App\Console\Commands;

use App\Support\NewsDiscovery;
use Illuminate\Console\Command;

class DiscoverNews extends Command
{
    protected $signature = 'news:discover {--source= : Restrict to one due approved source}';

    protected $description = 'Queue bounded due sources only when discovery is explicitly enabled';

    public function handle(NewsDiscovery $discovery): int
    {
        $source = $this->option('source');
        if ($source !== null && (! ctype_digit($source) || (int) $source < 1)) {
            $this->error('Supply a positive source ID.');

            return self::FAILURE;
        }
        $count = $discovery->queueDue(sourceId: $source === null ? null : (int) $source);
        $this->info('Queued source runs: '.$count.'. Disabled discovery, cooldowns and existing leases are respected.');

        return self::SUCCESS;
    }
}
