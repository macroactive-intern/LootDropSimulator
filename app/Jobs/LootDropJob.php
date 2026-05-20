<?php

namespace App\Jobs;

use App\Services\LootService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class LootDropJob implements ShouldQueue
{
    use Queueable;

    public $tries = 3;

    public $backoff = [5, 30, 60];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public int $userId,
        public string $source,
        public float $legendaryMultiplier = 1.0,
    )
    {
    }

    /**
     * Execute the job.
     */
    public function handle(LootService $lootService): void
    {
        $lootService->roll(
            $this->userId,
            $this->source,
            $this->legendaryMultiplier,
        );
    }

    public function failed(Throwable $exception): void
    {
        Log::build([
            'driver' => 'single',
            'path' => storage_path('logs/loot-errors.log'),
        ])->error('Loot drop job failed', [
            'user_id' => $this->userId,
            'source' => $this->source,
            'legendary_multiplier' => $this->legendaryMultiplier,
            'exception' => $exception->getMessage(),
        ]);
    }
}
