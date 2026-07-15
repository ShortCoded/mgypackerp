<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Modules\Core\Services\Generators\GeneratorCommandOutput;
use Modules\Core\Services\Generators\GeneratorFileWriter;
use Modules\Core\Services\Generators\LookupCrudGenerator;

class ErpMakeLookupCrudCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:make-lookup-crud
        {module : Module directory/name, for example HR}
        {resource : Singular resource name, for example Department}
        {--table= : Database table name}
        {--route= : Route name prefix, for example admin.hr.departments}
        {--url= : URL prefix, for example admin/hr/departments}
        {--permission= : Permission prefix, for example hr.departments}
        {--doc-key= : Document number config key}
        {--prefix= : Document number prefix}
        {--menu=core : Menu config file name without .php}
        {--menu-parent=basic_data.hr : Parent menu key/path}
        {--translation= : Translation key, for example hr.departments}
        {--fields= : Accepted for signature parity; lookup CRUDs always use name/notes}
        {--force : Overwrite generated files that already exist}
        {--dry-run : Show what would be generated without writing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a simple ERP lookup CRUD that plugs into the shared HR lookup pattern.';

    /**
     * Execute the console command.
     */
    public function handle(LookupCrudGenerator $generator, GeneratorCommandOutput $output): int
    {
        $options = $this->normalizedOptions();
        $writer = new GeneratorFileWriter(
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
        );

        $results = $generator->generate($options, $writer);

        $output->write($this, $results, $writer->isDryRun(), [
            'composer dump-autoload',
            'php artisan optimize:clear',
            'php artisan migrate --no-interaction',
            'php artisan db:seed --class=Modules\\\\Auth\\\\Database\\\\Seeders\\\\PermissionSeeder --no-interaction',
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function normalizedOptions(): array
    {
        $module = (string) $this->argument('module');
        $resource = (string) $this->argument('resource');
        $resourceStudly = Str::studly($resource);
        $pluralStudly = Str::pluralStudly($resourceStudly);
        $pluralSnake = Str::snake($pluralStudly);
        $pluralKebab = Str::kebab($pluralStudly);
        $modulePrefix = Str::lower($module) === 'hr' ? 'hr_' : Str::snake($module).'_';
        $permissionPrefix = Str::lower($module) === 'hr' ? "hr.{$pluralSnake}" : $pluralSnake;

        return [
            'module' => $module,
            'resource' => $resource,
            'table' => (string) ($this->option('table') ?: $modulePrefix.$pluralSnake),
            'route' => (string) ($this->option('route') ?: 'admin.'.Str::lower($module).".{$pluralKebab}"),
            'url' => (string) ($this->option('url') ?: 'admin/'.Str::lower($module)."/{$pluralKebab}"),
            'permission' => (string) ($this->option('permission') ?: $permissionPrefix),
            'doc_key' => (string) ($this->option('doc-key') ?: $modulePrefix.$pluralSnake),
            'prefix' => (string) ($this->option('prefix') ?: Str::studly(Str::singular($resourceStudly)).'-'),
            'menu' => (string) ($this->option('menu') ?: 'core'),
            'menu_parent' => (string) ($this->option('menu-parent') ?: 'basic_data.hr'),
            'translation' => (string) ($this->option('translation') ?: 'hr.'.$pluralSnake),
            'fields' => (string) ($this->option('fields') ?: ''),
        ];
    }
}
