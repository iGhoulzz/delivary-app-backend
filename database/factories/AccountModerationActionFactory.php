<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AccountStatus;
use App\Enums\ModerationAction;
use App\Enums\ModerationReason;
use App\Models\AccountModerationAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AccountModerationAction> */
final class AccountModerationActionFactory extends Factory
{
    protected $model = AccountModerationAction::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'actor_id' => User::factory(),
            'action' => ModerationAction::Suspend->value,
            'reason_code' => ModerationReason::Other->value,
            'detail' => $this->faker->sentence(),
            'from_status' => AccountStatus::Active->value,
            'to_status' => AccountStatus::Suspended->value,
        ];
    }
}
