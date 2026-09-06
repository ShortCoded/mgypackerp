<?php

namespace App\DataTables;

use Yajra\DataTables\Utilities\Request;

final class BoundedDataTableRequest extends Request
{
    private const MaxPageLength = 100;

    public function isPaginationable(): bool
    {
        return true;
    }

    public function start(): int
    {
        return max(0, parent::start());
    }

    public function length(): int
    {
        $length = parent::length();

        if ($length === -1 || $length > self::MaxPageLength) {
            return self::MaxPageLength;
        }

        if ($length < 1) {
            return 10;
        }

        return $length;
    }
}
