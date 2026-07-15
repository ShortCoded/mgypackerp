<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Auth\Services\AuthLogService;
use Modules\Auth\Services\MailConfigurationService;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('modules.auth.forgot-password');
    }

    public function store(
        Request $request,
        AuthLogService $authLogService,
        MailConfigurationService $mailConfigurationService
    ): JsonResponse|RedirectResponse {
        $validator = Validator::make($request->all(), [
            'email' => ['required', 'email'],
        ], attributes: [
            'email' => __('auth.email'),
        ]);

        if ($validator->fails()) {
            $authLogService->log($request, 'forgot_password_requested_failed', 'failed', [
                'email' => $request->input('email'),
                'failure_reason' => 'validation_failed',
                'validation_fields' => $this->safeValidationFields(array_keys($validator->errors()->messages())),
            ]);

            throw ValidationException::withMessages($validator->errors()->messages());
        }

        $validated = $validator->validated();

        if (! $mailConfigurationService->hasActiveConfiguration()) {
            $authLogService->log($request, 'forgot_password_requested_failed', 'failed', [
                'email' => $validated['email'],
                'failure_reason' => 'mail_not_configured',
            ]);

            throw ValidationException::withMessages([
                'email' => __('auth.mail_not_configured'),
            ]);
        }

        $mailConfigurationService->apply();

        $status = Password::sendResetLink($validated);
        $user = User::query()->where('email', $validated['email'])->first();

        if ($status === Password::RESET_THROTTLED) {
            $authLogService->log($request, 'forgot_password_requested_failed', 'failed', [
                'user' => $user,
                'email' => $validated['email'],
                'failure_reason' => 'password_reset_throttled',
            ]);

            throw ValidationException::withMessages([
                'email' => __('auth.password_reset_throttled'),
            ]);
        }

        if ($status !== Password::RESET_LINK_SENT) {
            $authLogService->log($request, 'forgot_password_requested_unknown_account', 'failed', [
                'email' => $validated['email'],
                'failure_reason' => 'unknown_account',
            ]);

            if ($request->expectsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => __('auth.password_reset_link_sent'),
                ]);
            }

            return back()->with('status', __('auth.password_reset_link_sent'));
        }

        $authLogService->log($request, 'forgot_password_requested_success', 'success', [
            'user' => $user,
            'email' => $validated['email'],
        ]);

        Artisan::call('schedule:run');

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => __('auth.password_reset_link_sent'),
            ]);
        }

        return back()->with('status', __('auth.password_reset_link_sent'));
    }

    /**
     * @param  list<string>  $fields
     * @return list<string>
     */
    private function safeValidationFields(array $fields): array
    {
        return collect($fields)
            ->reject(fn (string $field): bool => in_array($field, ['password', 'password_confirmation', 'token', '_token', 'remember_token'], true))
            ->values()
            ->all();
    }
}
