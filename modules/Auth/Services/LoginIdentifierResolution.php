<?php

namespace Modules\Auth\Services;

use App\Models\User;

final readonly class LoginIdentifierResolution
{
    public function __construct(
        public ?User $user,
        public bool $ambiguous = false,
    ) {}
}
