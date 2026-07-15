<?php

namespace Modules\Core\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Services\NavigationSearchService;

class NavigationSearchController extends Controller
{
    public function __construct(
        private readonly NavigationSearchService $search,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->search->search($user, $request->query('q'), 10),
        ]);
    }

    public function storeRecent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'route_name' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
        ]);

        $recent = $this->search->storeRecent($user, $data);

        if ($recent === null) {
            return response()->json([
                'message' => __('navigation_search.messages.not_permitted'),
                'errors' => [
                    'url' => [__('navigation_search.messages.not_permitted')],
                ],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => __('navigation_search.messages.saved'),
        ]);
    }

    public function clearRecent(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->search->clearRecent($user);

        return response()->json([
            'success' => true,
            'message' => __('navigation_search.messages.cleared'),
        ]);
    }
}
