<?php

namespace App\Providers;

use App\DataTables\BoundedDataTableRequest;
use App\Observers\OperationalNotificationObserver;
use App\Services\EffectivePermissionResolver;
use App\View\Composers\AppLayoutComposer;
use App\View\Composers\AuthLayoutComposer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Modules\Accounting\Services\AccountCodeAllocator;
use Modules\Auth\Models\Role;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Modules\Core\Services\RequestMemo;
use Modules\Core\Services\ScreenDataVisibilityScopeRegistrar;
use Modules\Finance\Models\FundTransfer;
use Modules\FixedAssets\Models\FixedAsset;
use Modules\HR\Models\HrEmployeeServiceRequest;
use Modules\Inventory\Models\InventoryDocument;
use Modules\Maintenance\Models\MaintenanceMaterialRequest;
use Modules\Maintenance\Models\MaintenancePlanDue;
use Modules\Maintenance\Models\MaintenanceRequest;
use Modules\Maintenance\Models\MaintenanceWorkOrder;
use Modules\Production\Models\ProductionExpenseRequest;
use Modules\Production\Models\ProductionMaterialRequest;
use Modules\Production\Models\ProductionOrder;
use Modules\Production\Models\ProductionQualityInspection;
use Modules\Production\Models\ProductionRun;
use Modules\Purchases\Models\GoodsReceiptInspection;
use Modules\Purchases\Models\PurchaseOrder;
use Modules\Purchases\Models\PurchaseRequisition;
use Modules\Purchases\Models\SupplyOrder;
use Modules\Sales\Models\SalesOrder;
use Modules\Sales\Models\SalesReturn;
use Spatie\Permission\Events\PermissionAttached;
use Spatie\Permission\Events\PermissionDetached;
use Spatie\Permission\Events\RoleAttached;
use Spatie\Permission\Events\RoleDetached;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ErpUiScreenRegistry::class);
        $this->app->scoped(AccountCodeAllocator::class);
        $this->app->scoped(EffectivePermissionResolver::class);
        $this->app->scoped(RequestMemo::class);
        $this->app->singleton('datatables.request', BoundedDataTableRequest::class);

        if ($this->app->environment('local')
            && (bool) config('telescope.enabled', false)
            && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(ScreenDataVisibilityScopeRegistrar $screenDataVisibilityScopes): void
    {
        $flushEffectivePermissions = function (): void {
            app(EffectivePermissionResolver::class)->flush();
        };

        foreach ([PermissionAttached::class, PermissionDetached::class, RoleAttached::class, RoleDetached::class] as $event) {
            Event::listen($event, $flushEffectivePermissions);
        }

        Role::deleted($flushEffectivePermissions);
        Role::restored($flushEffectivePermissions);

        $permissionClass = config('permission.models.permission');

        if (is_string($permissionClass) && is_subclass_of($permissionClass, Model::class)) {
            $permissionClass::deleted($flushEffectivePermissions);
        }

        View::addNamespace('modules', app_path('Modules'));
        View::composer('layouts.app', AppLayoutComposer::class);
        View::composer('layouts.auth', AuthLayoutComposer::class);
        $screenDataVisibilityScopes->register();

        foreach ([
            PurchaseRequisition::class,
            PurchaseOrder::class,
            GoodsReceiptInspection::class,
            SupplyOrder::class,
            ProductionMaterialRequest::class,
            ProductionOrder::class,
            ProductionRun::class,
            ProductionQualityInspection::class,
            ProductionExpenseRequest::class,
            MaintenanceRequest::class,
            MaintenanceWorkOrder::class,
            MaintenancePlanDue::class,
            MaintenanceMaterialRequest::class,
            InventoryDocument::class,
            FundTransfer::class,
            SalesOrder::class,
            SalesReturn::class,
            HrEmployeeServiceRequest::class,
            FixedAsset::class,
        ] as $model) {
            $model::observe(OperationalNotificationObserver::class);
        }
    }
}
