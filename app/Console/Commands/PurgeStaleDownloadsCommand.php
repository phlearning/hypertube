<?php

namespace App\Console\Commands;

use App\Services\Torrent\StaleDownloadPurger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('torrent:purge-stale-downloads')]
#[Description('Delete the files of completed downloads not watched in over a month, keeping their metadata.')]
class PurgeStaleDownloadsCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(StaleDownloadPurger $purger): void
    {
        $purged = $purger->purge();

        $this->info("Purged {$purged} stale download(s).");
    }
}
