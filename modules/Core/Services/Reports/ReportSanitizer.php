<?php

namespace Modules\Core\Services\Reports;

class ReportSanitizer
{
    private const MaxDepth = 6;

    private const MaxArrayItems = 50;

    private const MaxStringLength = 2000;

    private const RedactedValue = '[redacted]';

    /**
     * @var list<string>
     */
    private array $sensitiveFragments = [
        'password',
        'token',
        'secret',
        'cookie',
        'authorization',
        'session_id',
        'session_fingerprint',
        'remember',
        'private_key',
        'csrf',
        '_token',
    ];

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function sanitizeForReport(array $data): array
    {
        $sanitized = $this->sanitize($data);

        return is_array($sanitized) ? $sanitized : [];
    }

    public function displayText(mixed $value): string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return '';
        }

        $value = trim((string) $value);

        return $this->isEmptyPlaceholder($value) ? '' : $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function redactOriginalPropertiesForReport(array $data): array
    {
        $redacted = $this->redactOriginalValue($data);

        return is_array($redacted) ? $redacted : [];
    }

    public function sanitize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MaxDepth) {
            return null;
        }

        if (is_array($value)) {
            $clean = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if ($count >= self::MaxArrayItems) {
                    break;
                }

                if (is_string($key) && $this->isSensitiveKey($key)) {
                    continue;
                }

                $sanitized = $this->sanitize($item, $depth + 1);

                if ($sanitized !== null && $sanitized !== '') {
                    if (is_int($key)) {
                        $clean[] = $sanitized;
                    } else {
                        $clean[$key] = $sanitized;
                    }

                    $count++;
                }
            }

            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = mb_substr($value, 0, self::MaxStringLength + 1);

            if ($this->displayText($value) === '') {
                return '';
            }

            return mb_strlen($value) > self::MaxStringLength
                ? mb_substr($value, 0, self::MaxStringLength - 1).'…'
                : $value;
        }

        return null;
    }

    public function summary(mixed $value, int $limit = 160): string
    {
        $sanitized = $this->sanitize($value);

        if ($sanitized === null || $sanitized === []) {
            return '';
        }

        if (is_string($sanitized) && $this->displayText($sanitized) === '') {
            return '';
        }

        $json = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($json) || $json === '') {
            return '';
        }

        return mb_strlen($json) > $limit ? mb_substr($json, 0, $limit - 1).'…' : $json;
    }

    public function isSensitiveKey(string $key): bool
    {
        $key = mb_strtolower($key);

        if ($this->isInternalIdentifierKey($key) || in_array($key, ['model', 'models', 'eloquent_model', 'relations', 'relation', 'pivot', 'original'], true)) {
            return true;
        }

        foreach ($this->sensitiveFragments as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }

    private function redactOriginalValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth > self::MaxDepth) {
            return '[truncated]';
        }

        if (is_array($value)) {
            $clean = [];
            $count = 0;

            foreach ($value as $key => $item) {
                if ($count >= self::MaxArrayItems) {
                    $clean['__truncated'] = true;
                    break;
                }

                if (is_string($key) && ($this->isInternalIdentifierKey($key) || $this->isModelDumpKey($key))) {
                    continue;
                }

                $redacted = is_string($key) && $this->shouldRedactOriginalKey($key)
                    ? self::RedactedValue
                    : $this->redactOriginalValue($item, $depth + 1);

                if ($redacted !== null && $redacted !== '') {
                    if (is_int($key)) {
                        $clean[] = $redacted;
                    } else {
                        $clean[$key] = $redacted;
                    }

                    $count++;
                }
            }

            return $clean;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_string($value)) {
            $value = mb_substr($value, 0, self::MaxStringLength + 1);

            if ($this->displayText($value) === '') {
                return '';
            }

            return mb_strlen($value) > self::MaxStringLength
                ? mb_substr($value, 0, self::MaxStringLength - 1).'…'
                : $value;
        }

        return null;
    }

    private function shouldRedactOriginalKey(string $key): bool
    {
        $key = mb_strtolower($key);

        return $key === 'session'
            || $key === 'raw_session'
            || $key === 'authorization'
            || $key === 'cookie'
            || $key === 'cookies'
            || $key === 'private_key'
            || $key === 'secret'
            || str_contains($key, 'password')
            || str_contains($key, 'token')
            || str_contains($key, 'csrf')
            || str_contains($key, 'session_id')
            || str_contains($key, 'session_fingerprint');
    }

    private function isInternalIdentifierKey(string $key): bool
    {
        $key = mb_strtolower($key);

        return $key === 'id' || (str_ends_with($key, '_id') && $key !== 'public_id');
    }

    private function isModelDumpKey(string $key): bool
    {
        return in_array(mb_strtolower($key), ['model', 'models', 'eloquent_model', 'relations', 'relation', 'pivot', 'original'], true);
    }

    private function isEmptyPlaceholder(string $value): bool
    {
        return in_array(mb_strtolower(trim($value)), [
            '',
            '-',
            '—',
            'not available',
            'n/a',
            'غير متاح',
            'لا يوجد',
        ], true);
    }
}
