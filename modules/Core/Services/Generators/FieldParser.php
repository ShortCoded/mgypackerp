<?php

namespace Modules\Core\Services\Generators;

use Illuminate\Support\Str;
use InvalidArgumentException;

class FieldParser
{
    /**
     * @var list<string>
     */
    private array $blockedFieldNames = [
        'id',
        'doc_number',
        'doc_num',
        'created_by',
        'updated_by',
        'deleted_by',
        'restored_by',
        'created_at',
        'updated_at',
        'deleted_at',
        'restored_at',
        'remember_token',
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'api_token',
        'access_token',
        'refresh_token',
        'secret',
        'private_key',
        'csrf_token',
        '_token',
        'guard_name',
    ];

    /**
     * @var list<string>
     */
    private array $blockedFieldSuffixes = [
        '_token',
        '_secret',
        '_password',
    ];

    /**
     * @return list<ParsedField>
     */
    public function parse(?string $fields): array
    {
        $fields = trim((string) $fields);

        if ($fields === '') {
            return [];
        }

        return collect(explode(',', $fields))
            ->map(fn (string $definition): string => trim($definition))
            ->filter()
            ->map(fn (string $definition): ParsedField => $this->parseField($definition))
            ->values()
            ->all();
    }

    private function parseField(string $definition): ParsedField
    {
        $parts = array_map('trim', explode(':', $definition));
        $name = array_shift($parts);

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException("Invalid field definition [{$definition}].");
        }

        $name = Str::snake($name);

        if ($this->isBlockedFieldName($name)) {
            throw new InvalidArgumentException("Field [{$name}] is reserved or sensitive and cannot be generated.");
        }

        $type = array_shift($parts) ?: 'string';
        $type = $this->normalizeType($type);
        $required = false;
        $nullable = false;
        $unique = false;
        $displayable = null;
        $loggable = null;
        $fillable = true;
        $length = null;
        $precision = null;
        $scale = null;
        $default = null;
        $enum = [];

        foreach ($parts as $token) {
            if ($token === '') {
                continue;
            }

            if ($token === 'required') {
                $required = true;
                $nullable = false;

                continue;
            }

            if ($token === 'nullable') {
                $nullable = true;
                $required = false;

                continue;
            }

            if ($token === 'unique') {
                $unique = true;

                continue;
            }

            if (in_array($token, ['displayable', 'show'], true)) {
                $displayable = true;

                continue;
            }

            if (in_array($token, ['hidden', 'not_displayable'], true)) {
                $displayable = false;

                continue;
            }

            if ($token === 'loggable') {
                $loggable = true;

                continue;
            }

            if (in_array($token, ['not_loggable', 'private'], true)) {
                $loggable = false;

                continue;
            }

            if (in_array($token, ['readonly', 'not_fillable'], true)) {
                $fillable = false;

                continue;
            }

            if (str_starts_with($token, 'max=')) {
                $length = max(1, (int) substr($token, 4));

                continue;
            }

            if (str_starts_with($token, 'default=')) {
                $default = substr($token, 8);

                continue;
            }

            if (str_starts_with($token, 'enum=')) {
                $enum = collect(explode('|', substr($token, 5)))
                    ->map(fn (string $value): string => trim($value))
                    ->filter()
                    ->values()
                    ->all();

                continue;
            }

            if (ctype_digit($token)) {
                if ($type === 'decimal') {
                    $precision ??= (int) $token;
                } else {
                    $length = (int) $token;
                }

                continue;
            }

            if ($type === 'decimal' && str_contains($token, '.')) {
                [$rawPrecision, $rawScale] = array_pad(explode('.', $token, 2), 2, null);
                $precision = max(1, (int) $rawPrecision);
                $scale = max(0, (int) $rawScale);
            }
        }

        if (! $required && ! $nullable) {
            $nullable = true;
        }

        if ($type === 'decimal') {
            $precision ??= 12;
            $scale ??= 2;
        }

        $isForeignKey = $type === 'foreignId';
        $defaultDisplayable = ! $isForeignKey;
        $defaultLoggable = ! $isForeignKey;

        return new ParsedField(
            name: $name,
            type: $type,
            required: $required,
            nullable: $nullable,
            unique: $unique,
            length: $length,
            precision: $precision,
            scale: $scale,
            default: $default,
            enum: $enum,
            rawTokens: $parts,
            isReserved: false,
            isSensitive: false,
            isForeignKey: $isForeignKey,
            isDisplayable: $displayable ?? $defaultDisplayable,
            isLoggable: $loggable ?? $defaultLoggable,
            isFillable: $fillable,
            requiresManualReview: $isForeignKey,
        );
    }

    private function normalizeType(string $type): string
    {
        return match (Str::camel($type)) {
            'string' => 'string',
            'text' => 'text',
            'int', 'integer' => 'integer',
            'decimal' => 'decimal',
            'bool', 'boolean' => 'boolean',
            'date' => 'date',
            'datetime', 'dateTime', 'timestamp' => 'datetime',
            'foreignId', 'foreignid', 'foreign' => 'foreignId',
            default => throw new InvalidArgumentException("Unsupported field type [{$type}]."),
        };
    }

    private function isBlockedFieldName(string $name): bool
    {
        if (in_array($name, $this->blockedFieldNames, true)) {
            return true;
        }

        if (str_contains($name, 'password') || str_contains($name, 'token')) {
            return true;
        }

        if (str_starts_with($name, 'secret_') || str_contains($name, '_secret_')) {
            return true;
        }

        foreach ($this->blockedFieldSuffixes as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
