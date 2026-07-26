<?php

namespace Modules\Core\Http\Requests\Concerns;

use Modules\Core\Services\NumericFormatService;

trait NormalizesNumericInput
{
    /**
     * @param  list<string>  $paths
     */
    protected function normalizeNumericInput(array $paths): void
    {
        $input = $this->getInputSource()->all();
        $formatter = app(NumericFormatService::class);

        foreach ($paths as $path) {
            $this->normalizeNumericValueAtPath($input, explode('.', $path), $formatter);
        }

        $this->replace($input);
    }

    /**
     * @param  array<string|int, mixed>  $input
     * @param  list<string>  $segments
     */
    private function normalizeNumericValueAtPath(
        array &$input,
        array $segments,
        NumericFormatService $formatter,
    ): void {
        $segment = array_shift($segments);

        if ($segment === null) {
            return;
        }

        if ($segment === '*') {
            foreach ($input as &$value) {
                if ($segments === []) {
                    $value = $formatter->normalizeForValidation($value);
                } elseif (is_array($value)) {
                    $this->normalizeNumericValueAtPath($value, $segments, $formatter);
                }
            }
            unset($value);

            return;
        }

        if (! array_key_exists($segment, $input)) {
            return;
        }

        if ($segments === []) {
            $input[$segment] = $formatter->normalizeForValidation($input[$segment]);

            return;
        }

        if (is_array($input[$segment])) {
            $this->normalizeNumericValueAtPath($input[$segment], $segments, $formatter);
        }
    }
}
