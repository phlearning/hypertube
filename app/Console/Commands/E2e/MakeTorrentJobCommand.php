<?php

namespace App\Console\Commands\E2e;

use App\Models\TorrentJob;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Test-support only: lets the Playwright e2e suite put a TorrentJob straight
 * into whatever state a scenario needs (e.g. "failed after 99% downloaded")
 * without depending on a real torrent swarm. Prints the created row as JSON
 * on its own line — the only thing this command writes to stdout — so a
 * test can capture and parse it directly.
 */
#[Signature('e2e:make-torrent-job {attributes : JSON-encoded attributes, merged over sane defaults}')]
#[Description('Create a TorrentJob for the Playwright e2e suite and print it as JSON.')]
class MakeTorrentJobCommand extends Command
{
    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run e2e:make-torrent-job in production.');

            return self::FAILURE;
        }

        /** @var array<string, mixed> $overrides */
        $overrides = json_decode((string) $this->argument('attributes'), associative: true, flags: JSON_THROW_ON_ERROR);

        // Same sane defaults as tests/Pest.php's makeTorrentJob() helper —
        // this command exists so the same fixture shape is reachable from a
        // separate Node process, not because it needs different defaults.
        $torrentJob = TorrentJob::create(array_merge([
            'job_id' => (string) Str::uuid(),
            'type' => 'download',
            'title' => 'E2E Movie',
            'status' => 'queued',
            'torrent_url' => 'http://e2e.test/movie.torrent',
            'info_hash' => Str::random(40),
            'remaining_candidates' => [],
        ], $overrides));

        $this->line($torrentJob->fresh()->toJson());

        return self::SUCCESS;
    }
}
