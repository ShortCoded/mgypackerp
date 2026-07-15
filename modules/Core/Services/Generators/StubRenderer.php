<?php

namespace Modules\Core\Services\Generators;

use RuntimeException;

class StubRenderer
{
    /**
     * @param  array<string, scalar|null>  $variables
     */
    public function render(string $stubPath, array $variables): string
    {
        $absolutePath = base_path($stubPath);

        if (! is_file($absolutePath)) {
            throw new RuntimeException("Stub [{$stubPath}] was not found.");
        }

        $contents = (string) file_get_contents($absolutePath);

        foreach ($variables as $key => $value) {
            $contents = str_replace('{{ '.$key.' }}', (string) $value, $contents);
            $contents = str_replace('{{'.$key.'}}', (string) $value, $contents);
        }

        return $contents;
    }
}
