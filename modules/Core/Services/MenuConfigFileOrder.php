<?php

namespace Modules\Core\Services;

class MenuConfigFileOrder
{
    /**
     * @return list<string>
     */
    public function files(): array
    {
        $files = array_merge(
            $this->globFiles(config_path('menu/*.php')),
            $this->globFiles(base_path('modules/*/config/menu/*.php')),
            $this->globFiles(base_path('modules/*/config/menu.php')),
            $this->globFiles(base_path('modules/*/Config/menu/*.php')),
            $this->globFiles(base_path('modules/*/Config/menu.php')),
            $this->globFiles(base_path('modules/*/Menu/*.php')),
        );

        $files = array_values(array_unique($files));

        usort($files, function (string $first, string $second): int {
            $firstName = pathinfo($first, PATHINFO_FILENAME);
            $secondName = pathinfo($second, PATHINFO_FILENAME);

            return [$this->scopeRank($first), $this->rank($firstName), $firstName, $first]
                <=> [$this->scopeRank($second), $this->rank($secondName), $secondName, $second];
        });

        return array_values($files);
    }

    /**
     * @return list<string>
     */
    private function globFiles(string $pattern): array
    {
        $files = glob($pattern) ?: [];

        return array_values(array_filter($files, fn (string $file): bool => is_file($file)));
    }

    private function scopeRank(string $file): int
    {
        return str_starts_with($file, config_path('menu').DIRECTORY_SEPARATOR) ? 0 : 50;
    }

    private function rank(string $name): int
    {
        return [
            'core' => 0,
            'accounting' => 10,
            'finance' => 20,
            'fixed_assets' => 25,
            'sales' => 30,
            'purchases' => 40,
            'inventory' => 45,
            'production' => 47,
            'hr' => 50,
            'tools' => 60,
            'auth' => 70,
        ][$name] ?? 100;
    }
}
