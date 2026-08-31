<?php

namespace App\Http\Middleware;

use App\Support\Http\JsonErrorResponse;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NormalizeJsonErrorResponse
{
    public function __construct(
        private readonly JsonErrorResponse $errors,
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        return $response instanceof JsonResponse
            ? $this->errors->normalize($request, $response)
            : $response;
    }
}
