<?php

namespace Modules\HR\Exceptions;

use DomainException;

class HrLookupRestoreBlockedException extends DomainException
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
