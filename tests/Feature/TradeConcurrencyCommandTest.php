<?php

use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('trade concurrency command is registered', function (): void {
    $this->artisan('trades:test-concurrency', ['tradeId' => 999])
        ->expectsOutput('Trade 999 was not found.')
        ->assertExitCode(Command::FAILURE);
});
