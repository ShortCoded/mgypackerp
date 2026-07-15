<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Modules\Core\Services\Generators\CrudGenerator;
use Modules\Core\Services\Generators\GeneratorCommandOutput;
use Modules\Core\Services\Generators\GeneratorFileWriter;

class ErpMakeCrudCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'erp:make-crud
        {module : Module directory/name, for example Core}
        {resource : Singular resource name, for example Branch}
        {--table= : Database table name}
        {--route= : Route name prefix, for example admin.branches}
        {--url= : URL prefix, for example admin/branches}
        {--permission= : Permission prefix, for example branches}
        {--doc-key= : Document number config key}
        {--prefix= : Document number prefix}
        {--menu=core : Menu config file name without .php}
        {--menu-parent=basic_data : Parent menu key/path for the manual menu block}
        {--translation= : Translation key/file, for example branches}
        {--fields= : Field definitions, for example name:string:required:unique,notes:text:nullable}
        {--force : Overwrite generated files that already exist}
        {--dry-run : Show what would be generated without writing files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate a full ERP CRUD scaffold following the project CRUD standard.';

    /**
     * Execute the console command.
     */
    public function handle(CrudGenerator $generator, GeneratorCommandOutput $output): int
    {
        $options = $this->normalizedOptions();
        $writer = new GeneratorFileWriter(
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
        );

        try {
            $results = $generator->generate($options, $writer);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

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
        $pluralSnake = Str::snake(Str::pluralStudly($resourceStudly));
        $pluralKebab = Str::kebab(Str::pluralStudly($resourceStudly));

        return [
            'module' => $module,
            'resource' => $resource,
            'table' => (string) ($this->option('table') ?: $pluralSnake),
            'route' => (string) ($this->option('route') ?: "admin.{$pluralKebab}"),
            'url' => (string) ($this->option('url') ?: "admin/{$pluralKebab}"),
            'permission' => (string) ($this->option('permission') ?: $pluralSnake),
            'doc_key' => (string) ($this->option('doc-key') ?: $pluralSnake),
            'prefix' => (string) ($this->option('prefix') ?: Str::studly(Str::singular($resourceStudly)).'-'),
            'menu' => (string) ($this->option('menu') ?: 'core'),
            'menu_parent' => (string) ($this->option('menu-parent') ?: 'basic_data'),
            'translation' => (string) ($this->option('translation') ?: $pluralSnake),
            'fields' => (string) ($this->option('fields') ?: ''),
        ];
    }
}
