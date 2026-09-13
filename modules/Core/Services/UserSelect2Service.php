<?php

namespace Modules\Core\Services;

use App\Models\User;
use Illuminate\Http\Request;

class UserSelect2Service
{
    public function __construct(
        private readonly DataTableSearchService $searchService,
        private readonly Select2ResponseService $select2,
    ) {}

    /**
     * @return array{results: list<array{id: string, text: string}>, pagination: array{more: bool}}
     */
    public function paginated(Request $request): array
    {
        $search = $request->input('q', $request->input('term'));

        $query = User::query()
            ->select([
                'users.doc_num',
                'users.name',
                'users.username',
                'users.email',
                'users.phone',
                'users.doc_number',
            ])
            ->where('users.status', 'active')
            ->whereNull('users.deleted_at')
            ->orderBy('users.name')
            ->orderBy('users.doc_number');

        if ($request->boolean('exclude_self') && $request->user() instanceof User) {
            $query->whereKeyNot($request->user()->getKey());
        }

        if ($request->boolean('available_for_employee')) {
            $employeeDocNum = trim((string) $request->input('employee_doc_num'));
            $query->where(function ($query) use ($employeeDocNum): void {
                $query->whereDoesntHave('hrEmployee', fn ($query) => $query->withTrashed())
                    ->when($employeeDocNum !== '', fn ($query) => $query->orWhereHas('hrEmployee', fn ($query) => $query->withTrashed()->where('hr_employees.doc_num', $employeeDocNum)));
            });
        }

        $excludedDocNums = collect(explode(',', (string) $request->input('exclude_doc_nums')))
            ->map(fn (string $docNum): string => trim($docNum))
            ->filter()
            ->values()
            ->all();

        if ($excludedDocNums !== []) {
            $query->whereNotIn('users.doc_num', $excludedDocNums);
        }

        $terms = $this->searchService->terms(is_string($search) ? $search : null);

        if ($terms !== []) {
            $this->searchService->applyMultiTermSearch($query, $terms, [
                'text' => [
                    'users.doc_num',
                    'users.name',
                    'users.username',
                    'users.email',
                    'users.phone',
                ],
            ]);
        }

        return $this->select2->paginated($query, $request, fn (User $user): array => $this->item($user));
    }

    /**
     * @return array{id: string, text: string}
     */
    public function item(User $user): array
    {
        return [
            'id' => (string) $user->doc_num,
            'text' => $this->label($user),
        ];
    }

    public function label(User $user): string
    {
        return trim(implode(' / ', array_filter([
            $user->name,
            $user->doc_num,
        ])));
    }
}
