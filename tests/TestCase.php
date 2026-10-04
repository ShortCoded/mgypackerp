<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $evidencePath = getenv('MGYPACK_REPORT_ENDPOINT_EVIDENCE');
        if (! is_string($evidencePath) || ! preg_match('#^/tmp/mgypack-[a-z0-9-]+\.jsonl$#', $evidencePath)) {
            return;
        }
        $this->app['events']->listen(RequestHandled::class, function (RequestHandled $event) use ($evidencePath): void {
            $route = $event->request->route();
            if ($event->request->method() !== 'GET' || $route === null || ! str_contains($route->uri(), 'report')) {
                return;
            }
            $evidence = [
                'test' => static::class.'::'.$this->nameWithDataSet(),
                'route' => $route->getName(),
                'status' => $event->response->getStatusCode(),
                'query_keys' => array_keys($event->request->query()),
                'content_type' => $event->response->headers->get('Content-Type'),
            ];
            if (file_put_contents($evidencePath, json_encode($evidence, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException('Cannot persist opt-in report endpoint evidence.');
            }
            chmod($evidencePath, 0600);
        });
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $connection = $app['config']->get('database.default');
            if ($connection !== 'sqlite' || $app['config']->get('database.connections.sqlite.database') !== ':memory:') {
                throw new RuntimeException('RefreshDatabase tests require SQLite :memory:. Use a guarded populated-copy test for PostgreSQL.');
            }
        }

        return $app;
    }
}
