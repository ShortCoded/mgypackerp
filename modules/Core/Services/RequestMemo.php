<?php

namespace Modules\Core\Services;

use Closure;
use Illuminate\Http\Request;
use Throwable;

class RequestMemo
{
    /**
     * @var array<string, mixed>
     */
    private array $fallback = [];

    public function remember(string $key, Closure $callback): mixed
    {
        if ($this->has($key)) {
            return $this->get($key);
        }

        $value = $callback();
        $this->put($key, $value);

        return $value;
    }

    public function has(string $key): bool
    {
        $storeKey = $this->storeKey($key);
        $request = $this->request();

        if ($request instanceof Request) {
            return $request->attributes->has($storeKey);
        }

        return array_key_exists($storeKey, $this->fallback);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $storeKey = $this->storeKey($key);
        $request = $this->request();

        if ($request instanceof Request) {
            return $request->attributes->get($storeKey, $default);
        }

        return $this->fallback[$storeKey] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $storeKey = $this->storeKey($key);
        $request = $this->request();

        if ($request instanceof Request) {
            $request->attributes->set($storeKey, $value);

            return;
        }

        $this->fallback[$storeKey] = $value;
    }

    public function forget(string $key): void
    {
        $storeKey = $this->storeKey($key);
        $request = $this->request();

        if ($request instanceof Request) {
            $request->attributes->remove($storeKey);

            return;
        }

        unset($this->fallback[$storeKey]);
    }

    private function request(): ?Request
    {
        try {
            if (! app()->bound('request')) {
                return null;
            }

            $request = app('request');

            if (! $request instanceof Request) {
                return null;
            }

            if (app()->runningUnitTests() && $request->route() === null) {
                return null;
            }

            return $request;
        } catch (Throwable) {
            return null;
        }
    }

    private function storeKey(string $key): string
    {
        return 'erp.request_memo.'.$key;
    }
}
