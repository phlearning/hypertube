<?php

namespace App\Console\Commands;

use App\Jobs\PingTorrentWorker;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('torrent:ping-worker')]
#[Description('Dispatch a dummy ping job to prove the Laravel <-> Redis <-> Python worker wiring.')]
class PingTorrentWorkerCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        PingTorrentWorker::dispatch();

        $this->info('Ping dispatched. Check the worker_pings table for its resolution.');
    }
}
