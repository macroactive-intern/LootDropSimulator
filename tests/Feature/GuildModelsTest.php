<?php

use App\Models\Guild;
use App\Models\GuildEvent;
use App\Models\GuildInvite;
use App\Models\GuildMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('guild models expose their relationships', function (): void {
    $creator = User::factory()->create();
    $target = User::factory()->create();

    $guild = Guild::query()->create([
        'name' => 'Raiders',
        'description' => 'Nightly boss runs.',
        'created_by' => $creator->id,
        'treasury_balance' => 500,
        'is_open' => true,
    ]);
    $guild->members()->attach($target->id, [
        'role' => 'officer',
        'joined_at' => now(),
        'contributed_gold' => 250,
    ]);

    $invite = GuildInvite::query()->create([
        'guild_id' => $guild->id,
        'invited_by' => $creator->id,
        'email' => 'new-member@example.com',
        'token' => (string) Str::uuid(),
        'expires_at' => now()->addDay(),
    ]);

    $event = GuildEvent::query()->create([
        'guild_id' => $guild->id,
        'actor_id' => $creator->id,
        'target_id' => $target->id,
        'event_type' => 'invite_sent',
        'metadata' => ['email' => $invite->email],
    ]);

    expect($guild->creator->is($creator))->toBeTrue()
        ->and($guild->invites()->first()?->is($invite))->toBeTrue()
        ->and($guild->events()->first()?->is($event))->toBeTrue()
        ->and($guild->members()->first()?->is($target))->toBeTrue()
        ->and($creator->createdGuilds()->first()?->is($guild))->toBeTrue()
        ->and($creator->sentGuildInvites()->first()?->is($invite))->toBeTrue()
        ->and($creator->guildEventsActed()->first()?->is($event))->toBeTrue()
        ->and($target->guildEventsTargeted()->first()?->is($event))->toBeTrue()
        ->and($target->guilds()->first()?->is($guild))->toBeTrue()
        ->and($target->guilds()->first()?->pivot)->toBeInstanceOf(GuildMember::class)
        ->and($target->guilds()->first()?->pivot->role)->toBe('officer')
        ->and($target->guilds()->first()?->pivot->contributed_gold)->toBe(250)
        ->and($guild->treasury_balance)->toBe(500)
        ->and($guild->is_open)->toBeTrue()
        ->and($event->metadata)->toBe(['email' => 'new-member@example.com']);
});
