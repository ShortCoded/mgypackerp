<?php

namespace Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Services\ErpUi\ErpUiScreenDefinition;
use Modules\Core\Services\ErpUi\ErpUiScreenRegistry;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeErpUiShellScreen
{
    public function __construct(
        private readonly ErpUiScreenRegistry $screens,
    ) {}

    public function handle(Request $request, Closure $next, string $action): Response
    {
        $screenKey = (string) $request->route('erp_ui_screen', '');
        $screen = $this->screens->find($screenKey);

        abort_unless($screen instanceof ErpUiScreenDefinition, 404);

        $permissions = [$screen->permission($action)];

        if ($action === 'view') {
            foreach ($this->screens->legacyPlaceholderAliases() as $alias) {
                if ($alias['target']->key() === $screen->key()) {
                    $permissions[] = $alias['permission'];
                }
            }
        }

        abort_unless($request->user()?->canAny(array_values(array_unique($permissions))), 403);

        return $next($request);
    }
}
