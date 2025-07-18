<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Models\Import;
use Crumbls\Importer\States\CreateStorageState;
use Crumbls\Importer\States\WpXmlDriver\ExtractState;
use Crumbls\Importer\States\WpXmlDriver\AnalyzingState;
use Crumbls\Importer\States\WordPressDriver\MappingState;
use Crumbls\Importer\States\WordPressDriver\ModelCreationState;
use Crumbls\Importer\States\WordPressDriver\ModelCustomizationState;
use Crumbls\Importer\States\WordPressDriver\MigrationBuilderState;
use Crumbls\Importer\States\WordPressDriver\FactoryBuilderState;
use Crumbls\Importer\States\WordPressDriver\TransformReviewState;
use Crumbls\Importer\States\Shared\ConfigureModelsState;
use Crumbls\Importer\Support\SourceResolverManager;
use Crumbls\Importer\Resolvers\FileSourceResolver;

use Crumbls\Importer\States\WpXmlDriver\ProcessingState;

use Crumbls\Importer\States\CancelledState;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\InitializingState;
use Crumbls\Importer\States\WpXmlDriver\PendingState;
use Crumbls\StateMachine\Examples\PendingPayment;
use Crumbls\StateMachine\State;
use Crumbls\StateMachine\StateConfig;
use Crumbls\Importer\Support\DriverConfig;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Client\PendingRequest;

class WpXmlDriver extends XmlDriver
{
	public static function getPriority() : int
	{
		return 90;
	}

	/**
	 * @param ImportContract $import
	 * @return bool
	 */
	public static function canHandle(ImportContract $import) : bool {
		if (!XmlDriver::canHandle($import)) {
			return false;
		}

		return true;

		try {

			$manager = new SourceResolverManager();
			$manager->addResolver(new FileSourceResolver($import->source_type, $import->source_detail));
			
			$filePath = $manager->resolve($import->source_type, $import->source_detail);
			
			$handle = fopen($filePath, 'r');

			if (!$handle) {
				return false;
			}

			$chunk = fread($handle, 8192);
			fclose($handle);


			return strpos($chunk, '<rss') !== false && 
			       strpos($chunk, 'wordpress.org/export') !== false &&
			       strpos($chunk, '<wp:') !== false;
			       
		} catch (\Exception $e) {

			return false;
		}
	}


	public static function config(): DriverConfig
	{
		return (new DriverConfig())
			->default(PendingState::class)

			// Extract phase transitions
			->allowTransition(PendingState::class, CreateStorageState::class)
			->allowTransition(PendingState::class, FailedState::class)

			->allowTransition(CreateStorageState::class, ExtractState::class)
			->allowTransition(CreateStorageState::class, FailedState::class)

			->allowTransition(ExtractState::class, AnalyzingState::class)
			->allowTransition(ExtractState::class, FailedState::class)

			// Transform phase transitions (ETL flow)
			->allowTransition(AnalyzingState::class, MappingState::class)
			->allowTransition(AnalyzingState::class, FailedState::class)

			->allowTransition(MappingState::class, ModelCreationState::class)
			->allowTransition(MappingState::class, ModelCustomizationState::class)
			->allowTransition(MappingState::class, FailedState::class)

			->allowTransition(ModelCreationState::class, ModelCustomizationState::class)
			->allowTransition(ModelCreationState::class, MappingState::class)
			->allowTransition(ModelCreationState::class, FailedState::class)

			->allowTransition(ModelCustomizationState::class, MigrationBuilderState::class)
			->allowTransition(ModelCustomizationState::class, FailedState::class)

			->allowTransition(MigrationBuilderState::class, FactoryBuilderState::class)
			->allowTransition(MigrationBuilderState::class, FailedState::class)

			->allowTransition(FactoryBuilderState::class, TransformReviewState::class)
			->allowTransition(FactoryBuilderState::class, FailedState::class)

			// Load phase transitions (future implementation)
			->allowTransition(TransformReviewState::class, CompletedState::class)
			->allowTransition(TransformReviewState::class, FailedState::class)

			// Preferred transitions (ETL flow)
			->preferredTransition(PendingState::class, CreateStorageState::class)
			->preferredTransition(CreateStorageState::class, ExtractState::class)
			->preferredTransition(ExtractState::class, AnalyzingState::class)
			->preferredTransition(AnalyzingState::class, MappingState::class)
			->preferredTransition(MappingState::class, ModelCreationState::class)
			->preferredTransition(ModelCreationState::class, ModelCustomizationState::class)
			->preferredTransition(ModelCustomizationState::class, MigrationBuilderState::class)
			->preferredTransition(MigrationBuilderState::class, FactoryBuilderState::class)
			->preferredTransition(FactoryBuilderState::class, TransformReviewState::class)
			->preferredTransition(TransformReviewState::class, CompletedState::class)
;
	}

	/**
	 * Get custom pages for WordPress XML imports
	 */
	public static function getCustomPages(ImportContract $record): array
	{
		$pages = [];
		
		// Analysis page - show if analysis results exist
		$metadata = $record->metadata ?? [];
		if (isset($metadata['analysis_results']) && !empty($metadata['analysis_results'])) {
			$pages[] = [
				'key' => 'wordpress.analysis',
				'name' => 'WordPress Analysis',
				'class' => AnalysisPage::class,
				'route' => '/{record}/wordpress/analysis',
				'icon' => 'heroicon-o-chart-bar',
				'sort' => 10,
				'available' => function($record) {
					$metadata = $record->metadata ?? [];
					return isset($metadata['analysis_results']) && !empty($metadata['analysis_results']);
				}
			];
		}
		
		// Configure models page - show if analysis is complete
		if (isset($metadata['analysis_results']) && !empty($metadata['analysis_results'])) {
			$pages[] = [
				'key' => 'wordpress.configure',
				'name' => 'Configure Models',
				'class' => ConfigureModelsPage::class,
				'route' => '/{record}/wordpress/configure',
				'icon' => 'heroicon-o-cog',
				'sort' => 20,
				'available' => function($record) {
					$metadata = $record->metadata ?? [];
					return isset($metadata['analysis_results']) && !empty($metadata['analysis_results']);
				}
			];
		}
		
		return $pages;
	}
}