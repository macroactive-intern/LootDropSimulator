<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\KickGuildMemberRequest;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Http\Resources\GuildMemberResource;
use App\Models\Guild;
use App\Models\User;
use App\Services\GuildService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class GuildMemberController extends Controller
{
    public function __construct(
        private readonly GuildService $guildService,
    ) {
    }

    public function destroy(KickGuildMemberRequest $request, Guild $guild, User $user): Response
    {
        Gate::authorize('kick', [$guild, $user]);

        $this->guildService->kickMember($guild, $request->user(), $user);

        return response()->noContent();
    }

    public function updateRole(UpdateMemberRoleRequest $request, Guild $guild, User $user): GuildMemberResource
    {
        $newRole = $request->validated('role');

        Gate::authorize('changeRole', [$guild, $user]);

        $this->guildService->changeRole($guild, $request->user(), $user, $newRole);

        return new GuildMemberResource(
            $this->guildService->guildMember($guild, $user),
        );
    }
}
