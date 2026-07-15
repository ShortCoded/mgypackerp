<?php

namespace Modules\Auth\Exceptions;

use DomainException;

class UserDeleteBlockedException extends DomainException
{
    /**
     * @param  list<array{doc_num: string|null, user_name: string, reason: string, related_records_count: int}>  $blockedRecords
     */
    public function __construct(string $message, public readonly array $blockedRecords = [])
    {
        parent::__construct($message);
    }
}
