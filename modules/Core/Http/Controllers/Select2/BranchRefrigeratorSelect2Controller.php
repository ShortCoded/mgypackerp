<?php

namespace Modules\Core\Http\Controllers\Select2;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Core\Models\BranchRefrigerator;
use Modules\Core\Services\DataTableSearchService;
use Modules\Core\Services\Select2ResponseService;

class BranchRefrigeratorSelect2Controller extends Controller
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        abort_unless($this->canUseRoomSelect($request), 403);

        $search = $request->input('q', $request->input('term'));
        $query = BranchRefrigerator::query()
            ->with('branch:id,name,doc_num')
            ->select(['id', 'branch_id', 'name', 'position'])
            ->orderBy('name')
            ->orderBy('id');

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'branch_refrigerators.name',
                ],
            ]);
        }

        return response()->json($this->select2->paginated($query, $request, function (BranchRefrigerator $room): array {
            return [
                'id' => (string) $room->getKey(),
                'text' => trim(implode(' / ', array_filter([
                    $room->name,
                    $room->branch?->name,
                    $room->branch?->doc_num,
                ]))),
            ];
        }));
    }

    private function canUseRoomSelect(Request $request): bool
    {
        foreach (['branches.view'] as $permission) {
            if ($request->user()?->can($permission)) {
                return true;
            }
        }

        return false;
    }
}
