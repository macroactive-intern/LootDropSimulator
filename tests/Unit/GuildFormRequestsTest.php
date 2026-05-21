<?php

use App\Http\Requests\StoreGuildRequest;
use App\Http\Requests\TreasuryDepositRequest;
use App\Http\Requests\TreasuryWithdrawRequest;
use App\Http\Requests\UpdateGuildRequest;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\Guild;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

test('store guild request validates guild creation input', function (): void {
    $creator = User::factory()->create();

    Guild::query()->create([
        'name' => 'Existing Guild',
        'created_by' => $creator->id,
    ]);

    $rules = (new StoreGuildRequest())->rules();

    expect(Validator::make([
        'name' => 'New Guild',
        'description' => null,
        'is_open' => true,
    ], $rules)->passes())->toBeTrue()
        ->and(Validator::make([
            'name' => 'Existing Guild',
            'is_open' => 'not-a-boolean',
        ], $rules)->fails())->toBeTrue();
});

test('guild request descriptions have a maximum length', function (): void {
    $storeRules = (new StoreGuildRequest())->rules();
    $updateRules = (new UpdateGuildRequest())->rules();

    expect(Validator::make([
        'name' => 'New Guild',
        'description' => str_repeat('a', 1000),
    ], $storeRules)->passes())->toBeTrue()
        ->and(Validator::make([
            'name' => 'New Guild',
            'description' => str_repeat('a', 1001),
        ], $storeRules)->fails())->toBeTrue()
        ->and(Validator::make([
            'description' => str_repeat('a', 1000),
        ], $updateRules)->passes())->toBeTrue()
        ->and(Validator::make([
            'description' => str_repeat('a', 1001),
        ], $updateRules)->fails())->toBeTrue();
});

test('member role request only accepts guild roles', function (): void {
    $rules = (new UpdateMemberRoleRequest())->rules();

    expect(Validator::make(['role' => 'leader'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['role' => 'officer'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['role' => 'member'], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['role' => 'admin'], $rules)->fails())->toBeTrue();
});

test('treasury deposit request requires a positive integer amount', function (): void {
    $rules = (new TreasuryDepositRequest())->rules();

    expect(Validator::make(['amount' => 1], $rules)->passes())->toBeTrue()
        ->and(Validator::make(['amount' => 0], $rules)->fails())->toBeTrue()
        ->and(Validator::make(['amount' => 'gold'], $rules)->fails())->toBeTrue();
});

test('treasury withdraw request requires positive amount and reason', function (): void {
    $rules = (new TreasuryWithdrawRequest())->rules();

    expect(Validator::make([
        'amount' => 1,
        'reason' => 'Raid repair costs',
    ], $rules)->passes())->toBeTrue()
        ->and(Validator::make([
            'amount' => 0,
            'reason' => 'Raid repair costs',
        ], $rules)->fails())->toBeTrue()
        ->and(Validator::make([
            'amount' => 1,
            'reason' => '',
        ], $rules)->fails())->toBeTrue();
});
