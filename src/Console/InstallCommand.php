<?php
namespace Crumbls\Importer\Console;


use Crumbls\Importer\Models\Ticket;
use Crumbls\Importer\Resolvers\ModelResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class InstallCommand extends Command
{
    protected $signature = 'importer:install 
                           {--force : Force installation even if already installed}
                           {--seed : Seed default data}
                           {--no-seed : Skip seeding default data}';
    
    protected $description = 'Install the Importer package';
    
    public function handle(): int
    {
        $this->info('Installing Crumbls Importer...');
        
        // Check existing installation
        if ($this->isInstalled() && !$this->option('force')) {
            $this->warn('Importer appears to be already installed.');
            if (!$this->confirm('Do you want to continue anyway?', false)) {
                $this->info('Installation cancelled. Use --force to skip this check.');
                return Command::SUCCESS;
            }
        }
        
        // Run migrations
        $this->info('Running migrations...');
        $this->call('migrate', [
            '--force' => true,
        ]);
        
        // Publish configuration
        $this->info('⚙️  Publishing configuration...');
        $this->call('vendor:publish', [
            '--tag' => 'importer-config',
            '--force' => $this->option('force'),
        ]);
        
        // Publish translations
        $this->info('Publishing translations...');
        $this->call('vendor:publish', [
            '--tag' => 'importer-translations',
            '--force' => $this->option('force'),
        ]);

        return Command::SUCCESS;
    }
    
    private function isInstalled(): bool
    {
		return once(function() {
$models = ModelResolver::all();
	if (!$models) {
		return false;
	}

			foreach($models as $modelClass) {

				$model = new $modelClass();
				$connectionNane = $model->getConnectionName();
				$tableName = $model->getTable();
				if (!Schema::connection($connectionNane)->hasTable($tableName)) {
					return false;
				}
				return true;
				dd(__LINE__);
				DB::connection($connectionNane)->table($tableName);
dd($modelClass);

			}
		});
        $modelName = config('Importer.models.ticket', Ticket::class);
        $tableName = with(new $modelName())->getTable();
        return Schema::hasTable($tableName);
    }

}