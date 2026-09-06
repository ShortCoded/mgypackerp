<?php

use App\Providers\ModuleServiceProvider;
use Illuminate\Contracts\Foundation\Application;

test('web boots skip migration path discovery', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('runningInConsole')->once()->andReturnFalse();

    $provider = new class($application) extends ModuleServiceProvider
    {
        public bool $loadedRoutes = false;

        public bool $loadedMigrations = false;

        protected function loadModuleRoutes(): void
        {
            $this->loadedRoutes = true;
        }

        protected function loadModuleMigrations(): void
        {
            $this->loadedMigrations = true;
        }
    };

    $provider->boot();

    expect($provider->loadedRoutes)->toBeTrue()
        ->and($provider->loadedMigrations)->toBeFalse();
});

test('console boots keep module migrations discoverable', function () {
    $application = Mockery::mock(Application::class);
    $application->shouldReceive('runningInConsole')->once()->andReturnTrue();

    $provider = new class($application) extends ModuleServiceProvider
    {
        public bool $loadedRoutes = false;

        public bool $loadedMigrations = false;

        protected function loadModuleRoutes(): void
        {
            $this->loadedRoutes = true;
        }

        protected function loadModuleMigrations(): void
        {
            $this->loadedMigrations = true;
        }
    };

    $provider->boot();

    expect($provider->loadedRoutes)->toBeTrue()
        ->and($provider->loadedMigrations)->toBeTrue();
});
