<?php

namespace AwaisJameel\DiditLaravelClient\Commands;

use Illuminate\Console\Command;

class DiditLaravelClientCommand extends Command
{
    public $signature = 'didit-laravel-client';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
