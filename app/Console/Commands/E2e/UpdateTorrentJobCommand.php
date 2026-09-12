<?php

namespace App\Console\Commands\E2e;

use App\Models\TorrentJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Test-support only: lets the Playwright e2e suite mutate an already-seeded
 * TorrentJob mid-test (e.g. flipping it to "completed" right as a page is
 * loading, to exercise the channel-auth race). Prints the updated row as
 * JSON on its own line, same convention as e2e:make-torrent-job.
 */
#[Signature('e2e:update-torrent-job {job_id} {attributes : JSON-encoded attributes to apply}')]
#[Description('Update a TorrentJob by job_id for the Playwright e2e suite and print it as JSON.')]
class UpdateTorrentJobCommand extends Command
{
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run e2e:update-torrent-job in production.');

            return self::FAILURE;
        }

        $torrentJob = TorrentJob::query()->where('job_id', $this->argument('job_id'))->first();

        if ($torrentJob === null) {
            $this->error("No torrent job found with job_id {$this->argument('job_id')}.");

            return self::FAILURE;
        }

        /** @var array<string, mixed> $attributes */
        $attributes = json_decode((string) $this->argument('attributes'), associative: true, flags: JSON_THROW_ON_ERROR);

        $torrentJob->update($attributes);

        $this->line($torrentJob->fresh()->toJson());

        return self::SUCCESS;
    }
}
