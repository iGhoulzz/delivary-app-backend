<?php

declare(strict_types=1);

namespace App\Support\DTO;

final readonly class UpdateStaffInput
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $email = null,
    ) {}
}
