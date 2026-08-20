<?php

namespace Modules\Core\Imports\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

trait ValidatesWithFormRequest
{
    /**
     * @param  class-string<FormRequest>  $requestClass
     * @param  array<string, mixed>  $payload
     * @return array{data: array<string, mixed>|null, errors: array<string, list<string>>}
     */
    private function validateWithFormRequest(string $requestClass, array $payload, Request $request): array
    {
        $synthetic = Request::create($request->url(), 'POST', $payload);

        if ($request->hasSession()) {
            $synthetic->setLaravelSession($request->session());
        }

        $synthetic->setUserResolver($request->getUserResolver());
        $synthetic->setRouteResolver($request->getRouteResolver());

        /** @var FormRequest $formRequest */
        $formRequest = $requestClass::createFrom($synthetic);
        $formRequest->setContainer(app());
        $formRequest->setRedirector(app('redirect'));
        $formRequest->setUserResolver($request->getUserResolver());
        $formRequest->setRouteResolver($request->getRouteResolver());

        try {
            $formRequest->validateResolved();
        } catch (ValidationException $exception) {
            return ['data' => null, 'errors' => $exception->errors()];
        }

        /** @var array<string, mixed> $data */
        $data = $formRequest->validated();

        return ['data' => $data, 'errors' => []];
    }

    /**
     * @return array{column: string|null, code: string, message: string, severity: string}
     */
    private function issue(?string $column, string $code, string $message): array
    {
        return [
            'column' => $column,
            'code' => $code,
            'message' => $message,
            'severity' => 'error',
        ];
    }
}
