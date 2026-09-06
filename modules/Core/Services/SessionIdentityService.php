<?php

namespace Modules\Core\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Request;

final class SessionIdentityService
{
    public function __construct(
        private readonly ConfigRepository $config,
    ) {}

    public function for(Request $request): ?string
    {
        $user = $request->user();
        $applicationKey = $this->config->get('app.key');

        if (! $user instanceof Authenticatable
            || ! $request->hasSession()
            || ! is_string($applicationKey)
            || $applicationKey === '') {
            return null;
        }

        $sessionId = $request->session()->getId();
        $userIdentifier = $user->getAuthIdentifier();

        if ($sessionId === '' || (! is_int($userIdentifier) && ! is_string($userIdentifier))) {
            return null;
        }

        return hash_hmac('sha256', implode("\0", [
            'erp-authenticated-session:v1',
            $user::class,
            (string) $userIdentifier,
            $sessionId,
        ]), $applicationKey);
    }
}
