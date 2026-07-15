<?php

namespace Modules\Core\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Modules\Core\Models\Company;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperatingCompanyContextService
{
    public function __construct(
        private readonly OperatingContextService $operatingContext,
    ) {}

    public function currentCompany(?Request $request = null): ?Company
    {
        $request ??= request();

        return $this->operatingContext->currentCompanyModel($request);
    }

    public function currentCompanyId(?Request $request = null): ?int
    {
        $company = $this->currentCompany($request);

        return $company instanceof Company ? (int) $company->getKey() : null;
    }

    public function requireCompanyId(?Request $request = null): int
    {
        $companyId = $this->currentCompanyId($request);

        if ($companyId === null) {
            throw new HttpException(409, __('operating_context.messages.required'));
        }

        return $companyId;
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    public function applyCompanyScope(Builder $query, ?string $table = null, ?Request $request = null): Builder
    {
        $companyId = $this->currentCompanyId($request);
        $table ??= $query->getModel()->getTable();

        if ($companyId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where("{$table}.company_id", $companyId);
    }

    public function companyPublicContext(?Request $request = null): array
    {
        $company = $this->currentCompany($request);

        return [
            'company_doc_num' => $company?->doc_num,
            'company_name' => $company?->name,
        ];
    }
}
