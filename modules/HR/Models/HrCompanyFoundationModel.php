<?php

namespace Modules\HR\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Models\Company;
use Modules\Core\Services\OperatingCompanyContextService;

abstract class HrCompanyFoundationModel extends HrFoundationModel
{
    public function resolveRouteBinding(mixed $value, mixed $field = null): ?self
    {
        return $this->resolveCompanyRouteBinding($value, $field, false);
    }

    public function resolveSoftDeletableRouteBinding(mixed $value, mixed $field = null): ?self
    {
        return $this->resolveCompanyRouteBinding($value, $field, true);
    }

    private function resolveCompanyRouteBinding(mixed $value, mixed $field, bool $withTrashed): ?self
    {
        $companyId = app(OperatingCompanyContextService::class)->currentCompanyId();
        $query = $this->newQuery()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->where($field ?? $this->getRouteKeyName(), $value);

        return $companyId === null
            ? $query->whereRaw('1 = 0')->first()
            : $query->where($this->getTable().'.company_id', $companyId)->first();
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class, 'company_id');
    }
}
