<?php

namespace App\Http\Requests;

use App\Models\InventoryItem;
use App\Models\Trade;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProposeTradeRequest extends FormRequest
{
    private const MAX_PENDING_TRADES_PER_USER = 10;

    /**
     * @var Collection<int, InventoryItem>|null
     */
    private ?Collection $inventoryItems = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'recipient_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::notIn([(int) $this->user()?->id]),
            ],
            'guild_id' => ['required', 'integer', 'exists:guilds,id'],
            'offered_items' => ['required', 'array', 'min:1'],
            'requested_items' => ['required', 'array', 'min:1'],
            'offered_items.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'offered_items.*.quantity' => ['required', 'integer', 'min:1'],
            'requested_items.*.inventory_item_id' => ['required', 'integer', 'exists:inventory_items,id'],
            'requested_items.*.quantity' => ['required', 'integer', 'min:1'],
        ];
    }

    /**
     * Get the after validation callbacks for the request.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validatePendingTradeLimit($validator);
                $this->validateSharedGuild($validator);
                $this->validateInventorySide($validator, 'offered_items', (int) $this->user()->id);
                $this->validateInventorySide($validator, 'requested_items', (int) $this->input('recipient_id'));

                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $this->validateFairTradeValue($validator);
            },
        ];
    }

    /**
     * @return array<int, array<string, int>>
     */
    public function offeredItems(): array
    {
        return $this->validated('offered_items', []);
    }

    /**
     * @return array<int, array<string, int>>
     */
    public function requestedItems(): array
    {
        return $this->validated('requested_items', []);
    }

    public function offeredTotalValue(): int
    {
        return $this->totalValueFor($this->offeredItems());
    }

    public function requestedTotalValue(): int
    {
        return $this->totalValueFor($this->requestedItems());
    }

    private function validateSharedGuild(Validator $validator): void
    {
        $memberCount = DB::table('guild_user')
            ->where('guild_id', (int) $this->input('guild_id'))
            ->whereIn('user_id', [
                (int) $this->user()->id,
                (int) $this->input('recipient_id'),
            ])
            ->distinct()
            ->count('user_id');

        if ($memberCount !== 2) {
            $validator->errors()->add(
                'guild_id',
                'Both users must be members of the supplied guild.'
            );
        }
    }

    private function validatePendingTradeLimit(Validator $validator): void
    {
        $userIds = [
            (int) $this->user()->id,
            (int) $this->input('recipient_id'),
        ];

        foreach ($userIds as $userId) {
            $pendingTradeCount = Trade::query()
                ->where('status', Trade::STATUS_PENDING)
                ->where(function ($query) use ($userId): void {
                    $query->where('initiator_id', $userId)
                        ->orWhere('recipient_id', $userId);
                })
                ->count();

            if ($pendingTradeCount >= self::MAX_PENDING_TRADES_PER_USER) {
                $validator->errors()->add(
                    'pending_trades',
                    'A user cannot have more than '.self::MAX_PENDING_TRADES_PER_USER.' pending trades.'
                );

                return;
            }
        }
    }

    private function validateInventorySide(Validator $validator, string $field, int $ownerId): void
    {
        $requestedQuantities = [];

        foreach ($this->input($field, []) as $index => $itemData) {
            $inventoryItemId = (int) ($itemData['inventory_item_id'] ?? 0);
            $quantity = (int) ($itemData['quantity'] ?? 0);
            $requestedQuantities[$inventoryItemId] = ($requestedQuantities[$inventoryItemId] ?? 0) + $quantity;

            $inventoryItem = $this->inventoryItem($inventoryItemId);
            $attribute = "{$field}.{$index}.inventory_item_id";

            if ($inventoryItem === null) {
                continue;
            }

            if ((int) $inventoryItem->user_id !== $ownerId) {
                $validator->errors()->add($attribute, 'The selected inventory item does not belong to the expected user.');
            }

            if (! $inventoryItem->is_tradable) {
                $validator->errors()->add($attribute, 'The selected inventory item is not tradable.');
            }

            if ($inventoryItem->is_in_escrow) {
                $validator->errors()->add($attribute, 'The selected inventory item is already in escrow.');
            }

            if ($inventoryItem->item?->is_unique && $quantity !== 1) {
                $validator->errors()->add("{$field}.{$index}.quantity", 'Unique items must have a quantity of 1.');
            }
        }

        foreach ($requestedQuantities as $inventoryItemId => $quantity) {
            $inventoryItem = $this->inventoryItem((int) $inventoryItemId);

            if ($inventoryItem === null) {
                continue;
            }

            if ($inventoryItem->quantity < $quantity) {
                $validator->errors()->add($field, 'One or more selected inventory items does not have enough quantity.');
            }

            if ($inventoryItem->item?->is_unique && $quantity !== 1) {
                $validator->errors()->add($field, 'Unique items cannot be stacked.');
            }
        }
    }

    private function validateFairTradeValue(Validator $validator): void
    {
        $offeredTotal = $this->offeredTotalValue();
        $requestedTotal = $this->requestedTotalValue();
        $higherTotal = max($offeredTotal, $requestedTotal);
        $lowerTotal = min($offeredTotal, $requestedTotal);

        if ($higherTotal > 0 && ($lowerTotal / $higherTotal) < 0.75) {
            $validator->errors()->add(
                'trade_value',
                "Trade value is too imbalanced. Offered total: {$offeredTotal}. Requested total: {$requestedTotal}. Values must be within 25%."
            );
        }
    }

    /**
     * @param  array<int, array<string, int>>  $items
     */
    private function totalValueFor(array $items): int
    {
        return collect($items)->sum(function (array $itemData): int {
            $inventoryItem = $this->inventoryItem((int) $itemData['inventory_item_id']);

            if ($inventoryItem === null) {
                return 0;
            }

            return (int) $itemData['quantity'] * (int) $inventoryItem->item?->base_value;
        });
    }

    private function inventoryItem(int $inventoryItemId): ?InventoryItem
    {
        return $this->inventoryItems()->get($inventoryItemId);
    }

    /**
     * @return Collection<int, InventoryItem>
     */
    private function inventoryItems(): Collection
    {
        if ($this->inventoryItems !== null) {
            return $this->inventoryItems;
        }

        $inventoryItemIds = collect($this->input('offered_items', []))
            ->merge($this->input('requested_items', []))
            ->pluck('inventory_item_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $this->inventoryItems = InventoryItem::query()
            ->with('item')
            ->whereIn('id', $inventoryItemIds)
            ->get()
            ->keyBy('id');
    }
}
