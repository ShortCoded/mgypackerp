<?php

namespace Modules\Auth\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Modules\Auth\Http\Requests\UpdateDefaultLoginContextRequest;
use Modules\Auth\Http\Requests\UpdateProfilePasswordRequest;
use Modules\Auth\Http\Requests\UpdateProfileRequest;
use Modules\Auth\Models\AuthLog;
use Modules\Auth\Models\UserPresenceSession;
use Modules\Auth\Services\DefaultLoginContextService;
use Modules\Auth\Services\ProfileService;
use Modules\Auth\Services\Reports\AuthLogReport;
use Modules\Auth\Services\Reports\AuthSessionReport;
use Modules\Core\Services\ActivityLogger;
use Modules\Core\Services\BreadcrumbService;
use Modules\Core\Services\SettingService;

class ProfileController extends Controller
{
    public function __construct(
        private readonly ProfileService $profiles,
        private readonly DefaultLoginContextService $defaultLoginContexts,
        private readonly ActivityLogger $activityLogger,
        private readonly BreadcrumbService $breadcrumbs,
        private readonly SettingService $settings,
        private readonly AuthSessionReport $authSessionReport,
        private readonly AuthLogReport $authLogReport,
    ) {}

    public function show(Request $request): View
    {
        return $this->formView($request, 'view');
    }

    public function edit(Request $request): View
    {
        return $this->formView($request, 'edit');
    }

    public function update(UpdateProfileRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $result = $this->profiles->update($user, $request->validated());

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
            ]);
        }

        $updatedUser = $result['user'];

        $this->activityLogger->log($request, 'auth', 'profile.update', 'success', [
            'properties_only' => true,
            'properties' => [
                'user_doc_num' => $updatedUser->doc_num,
                'username' => $updatedUser->username,
                'changed_fields' => $result['changed_fields'],
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('profile.messages.updated_successfully'),
            'redirect' => $request->input('submit_action') === 'save_view' ? route('profile.show') : null,
            'data' => [
                'name' => $updatedUser->name,
                'username' => $updatedUser->username,
                'email' => $updatedUser->email,
                'phone' => $updatedUser->phone,
                'notes' => $updatedUser->notes,
            ],
        ]);
    }

    public function updateDefaultContext(UpdateDefaultLoginContextRequest $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $result = $this->defaultLoginContexts->update($user, $request->validated());

        if (! $result['changed']) {
            return response()->json([
                'success' => false,
                'type' => 'no_changes',
                'message' => __('common.messages.no_changes'),
                'data' => [
                    'default_context' => $result['context'],
                ],
            ]);
        }

        $updatedUser = $result['user'];

        $this->activityLogger->log($request, 'auth', 'profile.default_context.update', 'success', [
            'properties_only' => true,
            'properties' => [
                'user_doc_num' => $updatedUser->doc_num,
                'username' => $updatedUser->username,
                'changed_fields' => $result['changed_fields'],
                'default_context' => $result['context'],
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => __('profile.messages.default_context_saved_successfully'),
            'data' => [
                'default_context' => $result['context'],
            ],
        ]);
    }

    public function destroyDefaultContext(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->can('profile.edit'), 403);

        $result = $this->defaultLoginContexts->clear($user);
        $updatedUser = $result['user'];

        if ($result['changed']) {
            $this->activityLogger->log($request, 'auth', 'profile.default_context.clear', 'success', [
                'properties_only' => true,
                'properties' => [
                    'user_doc_num' => $updatedUser->doc_num,
                    'username' => $updatedUser->username,
                    'changed_fields' => $result['changed_fields'],
                ],
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => __('profile.messages.default_context_cleared_successfully'),
            'data' => [
                'default_context' => [
                    'company' => null,
                    'branch' => null,
                    'financial_period' => null,
                ],
            ],
        ]);
    }

    public function updatePassword(UpdateProfilePasswordRequest $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $data = $request->validated();
        $values = [
            'password' => Hash::make((string) $data['password']),
        ];

        if (Schema::hasColumn($user->getTable(), 'updated_by')) {
            $values['updated_by'] = $user->getKey();
        }

        $user->forceFill($values)->save();

        $this->activityLogger->log($request, 'auth', 'profile.password.update', 'success', [
            'properties_only' => true,
            'properties' => [
                'user_doc_num' => $user->doc_num,
                'username' => $user->username,
                'profile_section' => 'password',
            ],
        ]);

        return back()->with('success', __('profile.messages.password_updated_successfully'));
    }

    private function formView(Request $request, string $mode): View
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $profileUser = $user->refresh()->load('roles:id,name,doc_num');

        return view('modules.auth.profile.form', [
            'mode' => $mode,
            'user' => $profileUser,
            'createdAt' => $this->settings->formatDateTime($profileUser->created_at, ''),
            'updatedAt' => $this->settings->formatDateTime($profileUser->updated_at, ''),
            'lastLoginAt' => $this->settings->formatDateTime($profileUser->last_login_at, ''),
            'defaultLoginContext' => $this->defaultLoginContexts->profileContext($profileUser),
            'activeSessions' => $profileUser->can('profile.sessions.view') ? $this->activeSessionsFor($profileUser) : collect(),
            'authLogs' => $profileUser->can('profile.auth_logs.view') ? $this->authLogsFor($profileUser) : collect(),
            'breadcrumbs' => $this->breadcrumbs->forMenuRoute('profile.show', $mode === 'edit' ? [
                ['label' => __('profile.edit_title'), 'route' => null],
            ] : []),
        ]);
    }

    /**
     * @return Collection<int, array{device: string, ip_address: string, login_at: string, last_seen_at: string, presence: string, presence_class: string}>
     */
    private function activeSessionsFor(User $user): Collection
    {
        if (! Schema::hasTable('user_presence_sessions')) {
            return collect();
        }

        return $this->authSessionReport
            ->listingQuery()
            ->where('user_presence_sessions.user_id', $user->getKey())
            ->whereIn('user_presence_sessions.status', ['online', 'idle', 'locked'])
            ->latest('user_presence_sessions.last_seen_at')
            ->limit(5)
            ->get()
            ->map(function (UserPresenceSession $session): array {
                $row = $this->authSessionReport->row($session);

                return [
                    'device' => $row['device'],
                    'ip_address' => $row['ip_address'],
                    'login_at' => $row['login_at'],
                    'last_seen_at' => $row['last_seen_at'],
                    'presence' => $row['presence'],
                    'presence_class' => $row['presence_class'],
                ];
            });
    }

    /**
     * @return Collection<int, array{date_time: string, activity: string, result: string, result_class: string, device: string, ip_address: string, location: string, location_url: string, location_map_label: string}>
     */
    private function authLogsFor(User $user): Collection
    {
        if (! Schema::hasTable('auth_logs')) {
            return collect();
        }

        return $this->authLogReport
            ->listingQuery()
            ->where('auth_logs.user_id', $user->getKey())
            ->latest('auth_logs.created_at')
            ->limit(5)
            ->get()
            ->map(function (AuthLog $authLog): array {
                $row = $this->authLogReport->row($authLog);

                return [
                    'date_time' => $row['date_time'],
                    'activity' => $row['activity'],
                    'result' => $row['result'],
                    'result_class' => $row['result_class'],
                    'device' => $row['device'],
                    'ip_address' => $row['ip_address'],
                    'location' => $row['location'],
                    'location_url' => $row['location_url'],
                    'location_map_label' => $row['location_map_label'],
                ];
            });
    }
}
