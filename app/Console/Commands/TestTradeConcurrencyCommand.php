<?php

namespace App\Console\Commands;

use App\Models\InventoryItem;
use App\Models\Trade;
use App\Models\TradeItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Process\Process;

class TestTradeConcurrencyCommand extends Command
{
    protected $signature = 'trades:test-concurrency
        {tradeId : The trade ID to accept concurrently}
        {--base-url= : Base URL for the running Laravel app, defaults to APP_URL}';

    protected $description = 'Dev-only smoke test for concurrent trade accept HTTP requests.';

    public function handle(): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('This dev-only command cannot run in production.');

            return self::FAILURE;
        }

        $trade = Trade::query()
            ->with([
                'initiator',
                'recipient',
                'tradeItems.inventoryItem.item',
            ])
            ->find($this->argument('tradeId'));

        if ($trade === null) {
            $this->error("Trade {$this->argument('tradeId')} was not found.");

            return self::FAILURE;
        }

        if (! $trade->isPending()) {
            $this->warn("Trade {$trade->id} is currently {$trade->status}; expected pending for this test.");
        }

        if (! $this->curlIsAvailable()) {
            $this->error('curl is required for this command but was not found.');

            return self::FAILURE;
        }

        $baseUrl = rtrim((string) ($this->option('base-url') ?: config('app.url')), '/');

        if ($baseUrl === '') {
            $this->error('No base URL is configured. Set APP_URL or pass --base-url=http://127.0.0.1:8000.');

            return self::FAILURE;
        }

        $snapshots = $this->snapshotsFor($trade);
        $itemIds = $snapshots->pluck('item_id')->unique()->values();
        $inventoryIdsBefore = InventoryItem::query()
            ->whereIn('item_id', $itemIds->all())
            ->pluck('id')
            ->all();
        $totalQuantitiesBefore = $this->totalQuantitiesByItem($itemIds);
        $token = $trade->recipient->createToken("trade-concurrency-{$trade->id}")->plainTextToken;
        $url = "{$baseUrl}/api/trades/{$trade->id}/accept";

        $this->info("POST {$url}");
        $this->info('Starting 10 concurrent accept requests as recipient user '.$trade->recipient_id.'...');

        $processes = collect(range(1, 10))
            ->map(fn (): Process => $this->acceptProcess($url, $token));

        $processes->each->start();

        while ($processes->contains(fn (Process $process): bool => $process->isRunning())) {
            usleep(50_000);
        }

        $results = $processes->map(fn (Process $process, int $index): array => $this->resultFor($process, $index + 1));
        $successes = $results->where('status', 200);
        $failures = $results->reject(fn (array $result): bool => $result['status'] === 200);
        $trade->refresh()->load([
            'tradeItems.inventoryItem.item',
            'escrowItems',
        ]);

        $this->newLine();
        $this->info('Summary');
        $this->line('Success count: '.$successes->count());
        $this->line('Failure count: '.$failures->count());
        $this->line('Final trade status: '.$trade->status);
        $this->line('Escrow rows remaining: '.$trade->escrowItems()->count());

        $exactlyOneSucceeded = $successes->count() === 1;
        $exactlyNineFailed = $failures->count() === 9;
        $tradeCompleted = $trade->status === Trade::STATUS_COMPLETED;
        $escrowReleased = $trade->escrowItems()->count() === 0;
        $quantitiesPreserved = $totalQuantitiesBefore->toArray() === $this->totalQuantitiesByItem($itemIds)->toArray();
        $splitRowsExpected = $this->splitRowsAreExpected($snapshots, $inventoryIdsBefore);

        $this->newLine();
        $this->info('Consistency checks');
        $this->line($this->checkLine('Exactly one request succeeded', $exactlyOneSucceeded));
        $this->line($this->checkLine('Exactly nine requests failed', $exactlyNineFailed));
        $this->line($this->checkLine('Final trade status is completed', $tradeCompleted));
        $this->line($this->checkLine('No escrow rows remain', $escrowReleased));
        $this->line($this->checkLine('Item quantities are preserved', $quantitiesPreserved));
        $this->line($this->checkLine('No extra inventory split rows were created', $splitRowsExpected));

        $this->newLine();
        $this->info('Involved inventory item ownership/quantities');
        $this->printInventoryRows($itemIds);

        if ($failures->isNotEmpty()) {
            $this->newLine();
            $this->info('Failure responses');
            $failures->each(function (array $result): void {
                $message = $result['message'] !== '' ? $result['message'] : 'No response body';
                $this->line("#{$result['request']} HTTP {$result['status']}: {$message}");
            });
        }

        $trade->recipient->tokens()
            ->where('name', "trade-concurrency-{$trade->id}")
            ->delete();

        return $exactlyOneSucceeded
            && $exactlyNineFailed
            && $tradeCompleted
            && $escrowReleased
            && $quantitiesPreserved
            && $splitRowsExpected
                ? self::SUCCESS
                : self::FAILURE;
    }

    private function curlIsAvailable(): bool
    {
        return (new Process(['curl', '--version']))->run() === 0;
    }

    private function acceptProcess(string $url, string $token): Process
    {
        $process = new Process([
            'curl',
            '--silent',
            '--show-error',
            '--request',
            'POST',
            $url,
            '--header',
            'Accept: application/json',
            '--header',
            "Authorization: Bearer {$token}",
            '--write-out',
            "\n%{http_code}",
        ]);
        $process->setTimeout(60);
        $process->setIdleTimeout(60);

        return $process;
    }

    /**
     * @return array{request: int, status: int, body: string, message: string}
     */
    private function resultFor(Process $process, int $requestNumber): array
    {
        $output = trim($process->getOutput());
        $status = 0;
        $body = $output;

        if (preg_match('/^(.*)\R(\d{3})$/s', $output, $matches) === 1) {
            $body = trim($matches[1]);
            $status = (int) $matches[2];
        }

        if (! $process->isSuccessful() && $status === 0) {
            $body = trim($process->getErrorOutput());
        }

        return [
            'request' => $requestNumber,
            'status' => $status,
            'body' => $body,
            'message' => $this->messageFromBody($body),
        ];
    }

    private function messageFromBody(string $body): string
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            return $body;
        }

        if (isset($decoded['message']) && is_string($decoded['message'])) {
            return $decoded['message'];
        }

        return $body;
    }

    /**
     * @return Collection<int, array<string, int>>
     */
    private function snapshotsFor(Trade $trade): Collection
    {
        return $trade->tradeItems
            ->map(function (TradeItem $tradeItem) use ($trade): array {
                $inventoryItem = $tradeItem->inventoryItem;

                return [
                    'inventory_item_id' => (int) $inventoryItem->id,
                    'item_id' => (int) $inventoryItem->item_id,
                    'from_user_id' => (int) $tradeItem->from_user_id,
                    'to_user_id' => (int) $tradeItem->from_user_id === (int) $trade->initiator_id
                        ? (int) $trade->recipient_id
                        : (int) $trade->initiator_id,
                    'quantity' => (int) $tradeItem->quantity,
                    'original_quantity' => (int) $inventoryItem->quantity,
                ];
            });
    }

    /**
     * @param  Collection<int, int>  $itemIds
     * @return Collection<int, int>
     */
    private function totalQuantitiesByItem(Collection $itemIds): Collection
    {
        return InventoryItem::query()
            ->whereIn('item_id', $itemIds->all())
            ->selectRaw('item_id, sum(quantity) as total_quantity')
            ->groupBy('item_id')
            ->pluck('total_quantity', 'item_id')
            ->map(fn (mixed $quantity): int => (int) $quantity)
            ->sortKeys();
    }

    /**
     * @param  Collection<int, array<string, int>>  $snapshots
     * @param  array<int, int>  $inventoryIdsBefore
     */
    private function splitRowsAreExpected(Collection $snapshots, array $inventoryIdsBefore): bool
    {
        $expectedSplitRows = $snapshots
            ->filter(fn (array $snapshot): bool => $snapshot['original_quantity'] > $snapshot['quantity'])
            ->map(fn (array $snapshot): array => [
                'item_id' => $snapshot['item_id'],
                'user_id' => $snapshot['to_user_id'],
                'quantity' => $snapshot['quantity'],
            ])
            ->values();

        $newRows = InventoryItem::query()
            ->whereNotIn('id', $inventoryIdsBefore)
            ->get(['item_id', 'user_id', 'quantity']);

        if ($newRows->count() !== $expectedSplitRows->count()) {
            return false;
        }

        return $expectedSplitRows->every(function (array $expected) use ($newRows): bool {
            return $newRows->contains(function (InventoryItem $inventoryItem) use ($expected): bool {
                return (int) $inventoryItem->item_id === $expected['item_id']
                    && (int) $inventoryItem->user_id === $expected['user_id']
                    && (int) $inventoryItem->quantity === $expected['quantity'];
            });
        });
    }

    private function checkLine(string $label, bool $passed): string
    {
        return sprintf('[%s] %s', $passed ? 'PASS' : 'FAIL', $label);
    }

    /**
     * @param  Collection<int, int>  $itemIds
     */
    private function printInventoryRows(Collection $itemIds): void
    {
        InventoryItem::query()
            ->with(['item', 'user'])
            ->whereIn('item_id', $itemIds->all())
            ->orderBy('item_id')
            ->orderBy('id')
            ->get()
            ->each(function (InventoryItem $inventoryItem): void {
                $this->line(sprintf(
                    'inventory_item #%d | item #%d %s | owner #%d %s | quantity %d | escrow %s',
                    $inventoryItem->id,
                    $inventoryItem->item_id,
                    $inventoryItem->item?->name ?? 'unknown',
                    $inventoryItem->user_id,
                    $inventoryItem->user?->email ?? 'unknown',
                    $inventoryItem->quantity,
                    $inventoryItem->is_in_escrow ? 'yes' : 'no'
                ));
            });
    }
}
