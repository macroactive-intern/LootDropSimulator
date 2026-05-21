<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\TreasuryDepositRequest;
use App\Http\Requests\TreasuryWithdrawRequest;
use App\Http\Resources\GuildResource;
use App\Models\Guild;
use App\Services\GuildService;
use Illuminate\Support\Facades\Gate;

class GuildTreasuryController extends Controller
{
    public function __construct(
        private readonly GuildService $guildService,
    ) {
    }

    public function deposit(TreasuryDepositRequest $request, Guild $guild): GuildResource
    {
        Gate::authorize('deposit', $guild);

        $this->guildService->depositTreasury(
            $guild,
            $request->user(),
            $request->integer('amount'),
        );

        return new GuildResource($this->guildService->getGuild($guild->refresh()));
    }

    public function withdraw(TreasuryWithdrawRequest $request, Guild $guild): GuildResource
    {
        Gate::authorize('withdraw', $guild);

        $data = $request->validated();

        $this->guildService->withdrawTreasury(
            $guild,
            $request->user(),
            $data['amount'],
            $data['reason'],
        );

        return new GuildResource($this->guildService->getGuild($guild->refresh()));
    }
}
