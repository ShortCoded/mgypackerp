<?php

namespace Modules\Core\Exceptions;

use DomainException;

class CompanyDeleteBlockedException extends DomainException
{
    /**
     * @param  list<array{doc_num: string|null, company_name: string, reason: string, related_records_count: int}>  $blockedRecords
     */
    public function __construct(string $message, public readonly array $blockedRecords = [])
    {
        parent::__construct($message);
    }
}
