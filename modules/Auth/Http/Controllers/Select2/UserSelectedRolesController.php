<?php

namespace Modules\Auth\Http\Controllers\Select2;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class UserSelectedRolesController extends Controller
{
    public function __invoke(User $user): JsonResponse
    {
        $roles = $user->roles()
            ->select([
                'roles.doc_num',
                'roles.name',
            ])
            ->orderBy('roles.name')
            ->get()
            ->map(fn ($role): array => [
                'id' => (string) $role->doc_num,
                'text' => (string) $role->name,
            ])
            ->values()
            ->all();

        return response()->json([
            'results' => $roles,
        ]);
    }
}
