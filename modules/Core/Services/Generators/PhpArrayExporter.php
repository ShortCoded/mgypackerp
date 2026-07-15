<?php

namespace Modules\Core\Services\Generators;

class PhpArrayExporter
{
    /**
     * @param  array<mixed>  $array
     */
    public function exportFile(array $array): string
    {
        return "<?php\n\nreturn ".$this->exportArray($array, 0).";\n";
    }

    /**
     * @param  array<mixed>  $array
     */
    private function exportArray(array $array, int $level): string
    {
        if ($array === []) {
            return '[]';
        }

        $indent = str_repeat('    ', $level);
        $nextIndent = str_repeat('    ', $level + 1);
        $lines = ['['];

        foreach ($array as $key => $value) {
            $keyPart = is_int($key) ? '' : $this->exportValue($key).' => ';
            $lines[] = $nextIndent.$keyPart.$this->exportValue($value, $level + 1).',';
        }

        $lines[] = $indent.']';

        return implode("\n", $lines);
    }

    private function exportValue(mixed $value, int $level = 0): string
    {
        if (is_array($value)) {
            return $this->exportArray($value, $level);
        }

        return match (true) {
            is_string($value) => var_export($value, true),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => (string) $value,
        };
    }
}
