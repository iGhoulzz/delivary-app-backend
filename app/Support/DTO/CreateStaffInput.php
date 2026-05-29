<?php

declare(strict_types=1);

namespace App\Support\DTO;

final readonly class CreateStaffInput
{
    /** @param  array<int, array{office_id: int, is_manager: bool}>  $officeAssignments */
    public function __construct(
        public string $phoneNumber,
        public string $firstName,
        public string $lastName,
        public ?string $email,
        public string $role,
        public array $officeAssignments = [],
    ) {}
}
