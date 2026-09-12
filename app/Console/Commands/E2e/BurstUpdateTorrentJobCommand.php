<?php

namespace App\Console\Commands\E2e;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-support only: applies several updates to the same TorrentJob back to
 * back within a single process, so the Playwright e2e suite can exercise
 * the broadcast throttle honestly. A real burst happens in milliseconds;
 * shelling out to a separate `docker compose exec ... artisan` process per
 * update takes long enough on its own that the 1-second throttle window
 * would already have elapsed between calls, defeating the point.
 */
#[Signature('e2e:burst-update-torrent-job {job_id} {updates : JSON array of attribute-diffs applied in order}')]
#[Description('Apply several updates to a TorrentJob in quick succession, for exercising the broadcast throttle.')]
class BurstUpdateTorrentJobCommand extends Command
{
    use FindsTorrentJobOrFails;

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run e2e:burst-update-torrent-job in production.');

            return self::FAILURE;
        }

        $torrentJob = $this->findTorrentJobOrFail($this->argument('job_id'));

        if ($torrentJob === null) {
            return self::FAILURE;
        }

        /** @var array<int, array<string, mixed>> $updates */
        $updates = json_decode((string) $this->argument('updates'), associative: true, flags: JSON_THROW_ON_ERROR);

        foreach ($updates as $attributes) {
            $torrentJob->update($attributes);
        }

        $this->line($torrentJob->fresh()->toJson());

        return self::SUCCESS;
    }
}
