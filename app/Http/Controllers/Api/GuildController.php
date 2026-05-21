<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreGuildRequest;
use App\Http\Requests\UpdateGuildRequest;
use App\Http\Resources\GuildEventResource;
use App\Http\Resources\GuildResource;
use App\Models\Guild;
use App\Services\GuildService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class GuildController extends Controller
{
    public function __construct(
        private readonly GuildService $guildService,
    ) {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Guild::class);

        return GuildResource::collection($this->guildService->listGuilds($request->user()));
    }

    public function store(StoreGuildRequest $request): JsonResponse
    {
        Gate::authorize('create', Guild::class);

        $guild = $this->guildService->createGuild(
            $request->user(),
            $request->validated(),
        );

        return (new GuildResource($this->guildService->getGuild($guild, $request->user())))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Request $request, Guild $guild): GuildResource
    {
        Gate::authorize('view', $guild);

        return new GuildResource($this->guildService->getGuild($guild, $request->user()));
    }

    public function update(UpdateGuildRequest $request, Guild $guild): GuildResource
    {
        Gate::authorize('update', $guild);

        $updatedGuild = $this->guildService->updateGuild(
            $guild,
            $request->validated(),
        );

        return new GuildResource($this->guildService->getGuild($updatedGuild, $request->user()));
    }

    public function destroy(Guild $guild): Response
    {
        Gate::authorize('delete', $guild);

        $this->guildService->deleteGuild($guild);

        return response()->noContent();
    }

    public function join(Request $request, Guild $guild): GuildResource
    {
        Gate::authorize('join', $guild);

        $this->guildService->joinGuild($guild, $request->user());

        return new GuildResource($this->guildService->getGuild($guild->refresh(), $request->user()));
    }

    public function leave(Request $request, Guild $guild): Response
    {
        Gate::authorize('leave', $guild);

        $this->guildService->leaveGuild($guild, $request->user());

        return response()->noContent();
    }

    public function events(Guild $guild): AnonymousResourceCollection
    {
        Gate::authorize('viewEvents', $guild);

        return GuildEventResource::collection($this->guildService->guildEvents($guild));
    }
}
