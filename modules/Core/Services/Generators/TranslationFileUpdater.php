<?php

namespace Modules\Core\Services\Generators;

class TranslationFileUpdater
{
    public function __construct(
        private readonly PhpArrayExporter $exporter = new PhpArrayExporter,
    ) {}

    /**
     * @param  array<string, mixed>  $lines
     */
    public function merge(GeneratorFileWriter $writer, string $path, array $lines): void
    {
        $absolutePath = base_path($path);
        $existing = is_file($absolutePath) ? require $absolutePath : [];

        if (! is_array($existing)) {
            $writer->manual(
                $this->manualPath($path),
                $this->exporter->exportFile($lines),
                "translation file [{$path}] does not return an array; merge manually",
            );

            return;
        }

        $merged = $this->mergeMissing($existing, $lines);

        if ($merged === $existing) {
            $writer->skip($path, 'translation keys already exist');

            return;
        }

        $writer->update($path, $this->exporter->exportFile($merged));
    }

    /**
     * @param  array<mixed>  $existing
     * @param  array<mixed>  $incoming
     * @return array<mixed>
     */
    private function mergeMissing(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if (! array_key_exists($key, $existing)) {
                $existing[$key] = $value;

                continue;
            }

            if (is_array($existing[$key]) && is_array($value)) {
                $existing[$key] = $this->mergeMissing($existing[$key], $value);
            }
        }

        return $existing;
    }

    private function manualPath(string $path): string
    {
        return 'storage/app/generated/erp/'.str_replace(['/', '.php'], ['-', ''], $path).'.generated.php';
    }
}
