<?php

namespace Modules\Core\Exceptions;

use DomainException;

class CompanyRestoreBlockedException extends DomainException
{
    /**
     * @param  list<string>  $conflictFields
     */
    public function __construct(
        string $message,
        public readonly string $conflictType,
        public readonly array $conflictFields = [],
    ) {
        parent::__construct($message);
    }

    public function isConflict(): bool
    {
        return $this->conflictType !== 'already_active';
    }
}
