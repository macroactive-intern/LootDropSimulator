<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuildInviteRequest;
use App\Http\Resources\GuildInviteResource;
use App\Http\Resources\GuildResource;
use App\Models\Guild;
use App\Services\GuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class GuildInviteController extends Controller
{
    public function __construct(
        private readonly GuildService $guildService,
    ) {
    }

    public function store(StoreGuildInviteRequest $request, Guild $guild): JsonResponse
    {
        Gate::authorize('invite', $guild);

        $invite = $this->guildService->createInvite(
            $guild,
            $request->user(),
            $request->validated(),
        );

        return (new GuildInviteResource($invite))
            ->response()
            ->setStatusCode(201);
    }

    public function accept(string $token): GuildResource
    {
        $guild = $this->guildService->acceptInviteToken($token);

        return new GuildResource($guild);
    }
}
