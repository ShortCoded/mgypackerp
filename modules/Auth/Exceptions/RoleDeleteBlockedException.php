<?php

namespace Modules\Auth\Exceptions;

use DomainException;

class RoleDeleteBlockedException extends DomainException
{
    /**
     * @param  list<array{doc_num: string|null, role_name: string, reason: string, related_users_count: int}>  $blockedRecords
     */
    public function __construct(
        string $message,
        public readonly array $blockedRecords = [],
    ) {
        parent::__construct($message);
    }
}
