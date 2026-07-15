<?php

namespace Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Auth\Services\AuthLogService;

class NewPasswordController extends Controller
{
    public function create(Request $request): View
    {
        return view('modules.auth.reset-password', ['request' => $request]);
    }

    public function store(Request $request, AuthLogService $authLogService): JsonResponse|RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ], attributes: [
            'email' => __('auth.email'),
            'password' => __('auth.password'),
            'password_confirmation' => __('auth.password_confirmation'),
        ]);

        if ($validator->fails()) {
            $authLogService->log($request, 'password_reset_validation_failed', 'failed', [
                'email' => $request->input('email'),
                'failure_reason' => 'validation_failed',
                'validation_fields' => $this->safeValidationFields(array_keys($validator->errors()->messages())),
            ]);

            throw ValidationException::withMessages($validator->errors()->messages());
        }

        $validated = $validator->validated();

        $resetUser = null;

        $status = Password::reset(
            $validated,
            function (User $user, string $password) use (&$resetUser): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                $resetUser = $user;

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            $event = $status === Password::INVALID_TOKEN
                ? 'password_reset_token_invalid'
                : 'password_reset_failed';

            $authLogService->log($request, $event, 'failed', [
                'email' => $validated['email'],
                'failure_reason' => $status === Password::INVALID_TOKEN ? 'invalid_token' : 'password_reset_failed',
            ]);

            throw ValidationException::withMessages([
                'email' => __('auth.password_reset_failed'),
            ]);
        }

        $authLogService->log($request, 'password_reset_success', 'success', [
            'user' => $resetUser,
            'email' => $validated['email'],
        ]);

        if ($request->expectsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => __('auth.password_reset_success'),
                'redirect' => route('login'),
            ]);
        }

        return redirect()->route('login')->with('status', __('auth.password_reset_success'));
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
