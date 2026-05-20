<?php

namespace App\Services;

class LootTable
{
    /**
     * @param  array<int, array<string, mixed>>|null  $items
     */
    public function __construct(
        private readonly ?array $items = null,
    ) {
    }

    /**
     * Roll against the configured loot table and return the selected item.
     *
     * @return array<string, mixed>
     */
    public function roll(float $legendaryMultiplier = 1.0): array
    {
        $items = $this->items ?? config('loot.items', []);

        $weightedItems = array_map(function (array $item) use ($legendaryMultiplier): array {
            $weight = (float) $item['weight'];

            if (($item['rarity'] ?? null) === 'legendary') {
                $weight *= $legendaryMultiplier;
            }

            return [
                'item' => $item,
                'weight' => max(0.0, $weight),
            ];
        }, $items);

        $totalWeight = array_sum(array_column($weightedItems, 'weight'));

        if ($totalWeight <= 0) {
            return [];
        }

        // Weighted selection works by choosing a random point on a line whose
        // length is the sum of all weights, then walking each item's weight
        // range until that point is reached.
        $selectedWeight = $this->randomFloat(0.0, $totalWeight);
        $runningWeight = 0.0;

        foreach ($weightedItems as $weightedItem) {
            $runningWeight += $weightedItem['weight'];

            if ($selectedWeight <= $runningWeight) {
                return $weightedItem['item'];
            }
        }

        return $weightedItems[array_key_last($weightedItems)]['item'];
    }

    private function randomFloat(float $min, float $max): float
    {
        return $min + (mt_rand() / mt_getrandmax()) * ($max - $min);
    }
}
