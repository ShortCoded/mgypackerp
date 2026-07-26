<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScreenDataVisibilityScopeRegistrar
{
    public function __construct(
        private readonly ScreenDataVisibilityRegistry $registry,
    ) {}

    public function register(): void
    {
        foreach ($this->registry->all() as $screenKey => $definition) {
            /** @var class-string<Model> $modelClass */
            $modelClass = $definition['model'];

            $modelClass::addGlobalScope('screen_data_visibility_'.$screenKey, function (Builder $query) use ($modelClass, $screenKey): void {
                if (! app()->bound('request')) {
                    return;
                }

                $request = request();
                $user = $request->user();
                $routeName = $request->route()?->getName();
                $resolvedScreenKey = $this->registry->screenKeyForModelAndRoute($modelClass, $routeName);

                if (! $user instanceof User || $resolvedScreenKey !== $screenKey) {
                    return;
                }

                app(ScreenDataVisibilityService::class)->applyToEloquent($query, $user, $screenKey, false);
            });
        }
    }
}
