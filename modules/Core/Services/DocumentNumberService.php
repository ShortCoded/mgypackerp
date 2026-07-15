<?php

namespace Modules\Core\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class DocumentNumberService
{
    public function __construct(
        private readonly SettingService $settings,
    ) {}

    public function format(string $key, int $docNumber): string
    {
        $config = $this->config($key);
        $prefix = (string) ($config['prefix'] ?? '');
        $padding = max(0, (int) ($config['padding'] ?? 0));

        $formattedNumber = $padding > 0
            ? str_pad((string) $docNumber, $padding, '0', STR_PAD_LEFT)
            : (string) $docNumber;

        return $prefix.$formattedNumber;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    public function nextNumber(string $key, string $modelClass): int
    {
        $config = $this->config($key);
        $numberColumn = (string) ($config['number_column'] ?? 'doc_number');
        $model = new $modelClass;

        return ((int) DB::table($model->getTable())->max($numberColumn)) + 1;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  (Closure(QueryBuilder): void)|null  $scope
     */
    public function nextNumberForCompany(string $key, string $modelClass, int $companyId, ?Closure $scope = null): int
    {
        $config = $this->config($key);
        $numberColumn = (string) ($config['number_column'] ?? 'doc_number');
        $model = new $modelClass;
        $query = DB::table($model->getTable())
            ->where('company_id', $companyId);

        $this->applyScope($query, $scope);

        return ((int) $query->max($numberColumn)) + 1;
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @return array<string, int|string>
     */
    public function next(string $key, string $modelClass): array
    {
        $config = $this->config($key);
        $numberColumn = (string) ($config['number_column'] ?? 'doc_number');
        $displayColumn = (string) ($config['column'] ?? 'doc_num');

        $model = new $modelClass;
        $table = $model->getTable();

        $this->lockTable($table);

        $nextNumber = ((int) DB::table($table)->max($numberColumn)) + 1;
        $docNum = $this->format($key, $nextNumber);

        return [
            $numberColumn => $nextNumber,
            $displayColumn => $docNum,
            'doc_number' => $nextNumber,
            'doc_num' => $docNum,
        ];
    }

    /**
     * @param  class-string<Model>  $modelClass
     * @param  (Closure(QueryBuilder): void)|null  $scope
     * @return array<string, int|string>
     */
    public function nextForCompany(string $key, string $modelClass, int $companyId, ?Closure $scope = null): array
    {
        $config = $this->config($key);
        $numberColumn = (string) ($config['number_column'] ?? 'doc_number');
        $displayColumn = (string) ($config['column'] ?? 'doc_num');

        $model = new $modelClass;
        $table = $model->getTable();

        $this->lockTable($table);

        $query = DB::table($table)
            ->where('company_id', $companyId);

        $this->applyScope($query, $scope);

        $nextNumber = ((int) $query->max($numberColumn)) + 1;
        $docNum = $this->format($key, $nextNumber);

        return [
            $numberColumn => $nextNumber,
            $displayColumn => $docNum,
            'doc_number' => $nextNumber,
            'doc_num' => $docNum,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function config(string $key): array
    {
        $config = config("document_numbers.{$key}");

        if (! is_array($config)) {
            throw new InvalidArgumentException("Document number config [{$key}] is missing.");
        }

        $prefixKey = "document_numbers.{$key}.prefix";
        $paddingKey = "document_numbers.{$key}.padding";
        $values = $this->settings->many([
            $prefixKey,
            $paddingKey,
        ], [
            $prefixKey => $config['prefix'] ?? '',
            $paddingKey => $config['padding'] ?? 0,
        ]);

        return [
            ...$config,
            'prefix' => $values[$prefixKey],
            'padding' => $values[$paddingKey],
        ];
    }

    private function lockTable(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(sprintf('LOCK TABLE %s IN SHARE ROW EXCLUSIVE MODE', DB::getQueryGrammar()->wrapTable($table)));
    }

    private function applyScope(QueryBuilder $query, ?Closure $scope): void
    {
        if ($scope === null) {
            return;
        }

        $scope($query);
    }
}
