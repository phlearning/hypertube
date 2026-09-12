<?php

test('it prints a dot-notation config value', function () {
    config(['services.torrent_worker.secret' => 'a-test-secret']);

    $this->artisan('e2e:print-config', ['key' => 'services.torrent_worker.secret'])
        ->assertSuccessful()
        ->expectsOutput('a-test-secret');
});

test('it refuses to run in production', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('e2e:print-config', ['key' => 'app.name'])->assertFailed();
});
