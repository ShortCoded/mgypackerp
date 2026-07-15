<?php

namespace Modules\Core\Services\Generators;

final readonly class ParsedField
{
    /**
     * @param  list<string>  $enum
     * @param  list<string>  $rawTokens
     */
    public function __construct(
        public string $name,
        public string $type,
        public bool $required,
        public bool $nullable,
        public bool $unique,
        public ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public mixed $default = null,
        public array $enum = [],
        public array $rawTokens = [],
        public bool $isReserved = false,
        public bool $isSensitive = false,
        public bool $isForeignKey = false,
        public bool $isDisplayable = true,
        public bool $isLoggable = true,
        public bool $isFillable = true,
        public bool $requiresManualReview = false,
    ) {}

    public function isStringLike(): bool
    {
        return in_array($this->type, ['string', 'text'], true);
    }

    public function isDateLike(): bool
    {
        return in_array($this->type, ['date', 'datetime'], true);
    }

    public function isBoolean(): bool
    {
        return $this->type === 'boolean';
    }

    public function isForeignId(): bool
    {
        return $this->isForeignKey;
    }
}
