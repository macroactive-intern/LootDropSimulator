<?php

use App\Models\Trade;
use App\Models\User;

test('trade user helper methods compare numeric ids regardless of driver string casting', function (): void {
    $initiator = tap(new User(), fn (User $user) => $user->forceFill(['id' => 10]));
    $recipient = tap(new User(), fn (User $user) => $user->forceFill(['id' => 20]));
    $outsider = tap(new User(), fn (User $user) => $user->forceFill(['id' => 30]));

    $trade = new Trade([
        'initiator_id' => '10',
        'recipient_id' => '20',
        'status' => Trade::STATUS_PENDING,
    ]);

    expect($trade->involvesUser($initiator))->toBeTrue()
        ->and($trade->involvesUser($recipient))->toBeTrue()
        ->and($trade->involvesUser($outsider))->toBeFalse()
        ->and($trade->canBeAcceptedBy($recipient))->toBeTrue()
        ->and($trade->canBeAcceptedBy($initiator))->toBeFalse()
        ->and($trade->canBeCancelledBy($initiator))->toBeTrue()
        ->and($trade->canBeCancelledBy($recipient))->toBeFalse();
});
