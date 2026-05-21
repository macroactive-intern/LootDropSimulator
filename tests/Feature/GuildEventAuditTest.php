<?php

use App\Models\GuildEvent;
use App\Models\User;
use App\Services\GuildService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function createAuditTestGuild(User $leader)
{
    $guild = createTestGuild($leader, ['name' => 'Audit Guild '.str()->uuid()]);
    GuildEvent::query()->delete();

    return $guild;
}

test('guild member observer logs join event metadata', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createAuditTestGuild($leader);
    $joinedAt = now()->startOfSecond();

    $this->travelTo($joinedAt);

    app(GuildService::class)->joinGuild($guild, $member);

    $event = GuildEvent::query()->firstOrFail();

    expect(GuildEvent::query()->count())->toBe(1)
        ->and($event->guild_id)->toBe($guild->id)
        ->and($event->target_id)->toBe($member->id)
        ->and($event->actor_id)->toBe($member->id)
        ->and($event->event_type)->toBe('join')
        ->and($event->metadata)->toBe([
            'role' => 'member',
            'joined_at' => $joinedAt->toISOString(),
        ]);
});

test('guild member observer logs leave event metadata', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createAuditTestGuild($leader);
    $joinedAt = now()->subHour()->startOfSecond();
    $guild->users()->attach($member->id, [
        'role' => 'member',
        'joined_at' => $joinedAt,
        'contributed_gold' => 250,
    ]);
    GuildEvent::query()->delete();

    app(GuildService::class)->leaveGuild($guild, $member);

    $event = GuildEvent::query()->firstOrFail();

    expect(GuildEvent::query()->count())->toBe(1)
        ->and($event->guild_id)->toBe($guild->id)
        ->and($event->target_id)->toBe($member->id)
        ->and($event->actor_id)->toBe($member->id)
        ->and($event->event_type)->toBe('leave')
        ->and($event->metadata)->toBe([
            'role' => 'member',
            'joined_at' => $joinedAt->toISOString(),
            'contributed_gold' => 250,
        ]);
});

test('guild member observer logs kick event metadata separately from leave', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createAuditTestGuild($leader);
    $joinedAt = now()->subHour()->startOfSecond();
    $guild->users()->attach($member->id, [
        'role' => 'member',
        'joined_at' => $joinedAt,
        'contributed_gold' => 75,
    ]);
    GuildEvent::query()->delete();

    app(GuildService::class)->kickMember($guild, $leader, $member);

    $event = GuildEvent::query()->firstOrFail();

    expect(GuildEvent::query()->count())->toBe(1)
        ->and($event->guild_id)->toBe($guild->id)
        ->and($event->actor_id)->toBe($leader->id)
        ->and($event->target_id)->toBe($member->id)
        ->and($event->event_type)->toBe('kick')
        ->and($event->metadata)->toBe([
            'role' => 'member',
            'joined_at' => $joinedAt->toISOString(),
            'contributed_gold' => 75,
        ])
        ->and(GuildEvent::query()
            ->where('guild_id', $guild->id)
            ->where('target_id', $member->id)
            ->where('event_type', 'leave')
            ->exists())->toBeFalse();
});

test('guild member observer logs promote event metadata', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createAuditTestGuild($leader);
    app(GuildService::class)->joinGuild($guild, $member);
    GuildEvent::query()->delete();

    app(GuildService::class)->changeRole($guild, $leader, $member, 'officer');

    $event = GuildEvent::query()->firstOrFail();

    expect(GuildEvent::query()->count())->toBe(1)
        ->and($event->guild_id)->toBe($guild->id)
        ->and($event->target_id)->toBe($member->id)
        ->and($event->actor_id)->toBe($leader->id)
        ->and($event->event_type)->toBe('promote')
        ->and($event->metadata)->toBe([
            'from_role' => 'member',
            'to_role' => 'officer',
        ]);
});

test('guild member observer logs demote event metadata', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createAuditTestGuild($leader);
    $guild->users()->attach($member->id, [
        'role' => 'officer',
        'joined_at' => now(),
    ]);
    GuildEvent::query()->delete();

    app(GuildService::class)->changeRole($guild, $leader, $member, 'member');

    $event = GuildEvent::query()->firstOrFail();

    expect(GuildEvent::query()->count())->toBe(1)
        ->and($event->guild_id)->toBe($guild->id)
        ->and($event->target_id)->toBe($member->id)
        ->and($event->actor_id)->toBe($leader->id)
        ->and($event->event_type)->toBe('demote')
        ->and($event->metadata)->toBe([
            'from_role' => 'officer',
            'to_role' => 'member',
        ]);
});

test('guild member observer logs the full membership audit sequence', function (): void {
    $leader = User::factory()->create();
    $member = User::factory()->create();
    $guild = createAuditTestGuild($leader);
    $service = app(GuildService::class);

    $service->joinGuild($guild, $member);
    $service->changeRole($guild, $leader, $member, 'officer');
    $service->changeRole($guild, $leader, $member, 'member');
    $service->leaveGuild($guild, $member);

    $events = GuildEvent::query()
        ->where('guild_id', $guild->id)
        ->where('target_id', $member->id)
        ->orderBy('id')
        ->get();

    expect($events)->toHaveCount(4)
        ->and($events->pluck('event_type')->all())->toBe([
            'join',
            'promote',
            'demote',
            'leave',
        ])
        ->and($events[0]->metadata['role'])->toBe('member')
        ->and($events[0]->actor_id)->toBe($member->id)
        ->and($events[1]->metadata)->toBe([
            'from_role' => 'member',
            'to_role' => 'officer',
        ])
        ->and($events[1]->actor_id)->toBe($leader->id)
        ->and($events[2]->metadata)->toBe([
            'from_role' => 'officer',
            'to_role' => 'member',
        ])
        ->and($events[2]->actor_id)->toBe($leader->id)
        ->and($events[3]->metadata['role'])->toBe('member')
        ->and($events[3]->metadata['contributed_gold'])->toBe(0)
        ->and($events[3]->actor_id)->toBe($member->id);
});
