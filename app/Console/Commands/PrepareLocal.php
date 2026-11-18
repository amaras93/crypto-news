<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

#[Signature('app:prepare-local')]
#[Description('Prepare local SQLite storage and generate an application key only when missing')]
class PrepareLocal extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->environment('local')) {
            $this->error('This setup command is only available in the local environment.');

            return self::FAILURE;
        }

        if (! config('app.key') && $this->call('key:generate') !== self::SUCCESS) {
            return self::FAILURE;
        }

        if (config('database.default') === 'sqlite') {
            $database = config('database.connections.sqlite.database');

            if ($database !== ':memory:' && ! File::exists($database)) {
                File::ensureDirectoryExists(dirname($database));
                File::put($database, '');
            }
        }

        $this->info('Local environment prepared. Existing keys and database files were preserved.');

        return self::SUCCESS;
    }
}
