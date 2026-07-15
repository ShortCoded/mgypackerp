<?php

namespace Modules\Core\DataTables\Concerns;

use Stringable;

trait FormatsNullableColumns
{
    protected function ellipsisText(mixed $value): string
    {
        $value = $this->nullableText($value);

        if ($value === '') {
            return '';
        }

        return sprintf(
            '<span class="dt-ellipsis-content" title="%s">%s</span>',
            e($value),
            e($value),
        );
    }

    protected function plainText(mixed $value): string
    {
        return e($this->nullableText($value));
    }

    private function nullableText(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof Stringable) {
            $value = (string) $value;
        }

        return trim((string) $value);
    }
}
