<?php

namespace Modules\Core\Services\Generators;

class DocumentNumberConfigUpdater
{
    public function update(GeneratorFileWriter $writer, string $docKey, string $prefix): void
    {
        $path = 'config/document_numbers.php';
        $absolutePath = base_path($path);
        $contents = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : "<?php\n\nreturn [\n];\n";

        if (str_contains($contents, "'{$docKey}' =>") || str_contains($contents, "\"{$docKey}\" =>")) {
            $writer->skip($path, "document number key [{$docKey}] already exists");

            return;
        }

        $block = <<<PHP
    '{$docKey}' => [
        'prefix' => {$this->exportString($prefix)},
        'padding' => 5,
        'column' => 'doc_num',
        'number_column' => 'doc_number',
    ],

PHP;

        $updated = preg_replace('/\\n\\];\\s*$/', "\n{$block}];\n", $contents, 1);

        if (! is_string($updated)) {
            $writer->manual(
                "storage/app/generated/erp/{$docKey}-document-number.generated.php",
                "<?php\n\n".$block,
                'document number config could not be updated safely; paste this block into config/document_numbers.php',
            );

            return;
        }

        $writer->update($path, $updated);
    }

    private function exportString(string $value): string
    {
        return var_export($value, true);
    }
}
