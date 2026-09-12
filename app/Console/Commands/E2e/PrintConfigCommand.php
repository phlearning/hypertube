<?php

namespace App\Console\Commands\E2e;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-support only: lets the Playwright e2e suite read a config value (e.g.
 * the internal worker's shared secret, to call the real completion callback
 * in broadcast-outage.spec.ts) without hardcoding or duplicating it — this
 * command and the app it's calling into always agree by construction.
 */
#[Signature('e2e:print-config {key : Dot-notation config key, e.g. services.torrent_worker.secret}')]
#[Description('Print a config value for the Playwright e2e suite.')]
class PrintConfigCommand extends Command
{
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run e2e:print-config in production.');

            return self::FAILURE;
        }

        $this->line((string) config($this->argument('key')));

        return self::SUCCESS;
    }
}
