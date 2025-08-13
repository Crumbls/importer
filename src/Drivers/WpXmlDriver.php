<?php

namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\Importer\Resolvers\FileSourceResolver;
use Crumbls\Importer\States\CompletedState;
use Crumbls\Importer\States\Shared\CreateStorageState;
use Crumbls\Importer\States\FailedState;
use Crumbls\Importer\States\WordPressDriver\FactoryBuilderState;
use Crumbls\Importer\States\WordPressDriver\MappingState;
use Crumbls\Importer\States\WordPressDriver\MigrationBuilderState;
use Crumbls\Importer\States\WordPressDriver\ModelCreationState;
use Crumbls\Importer\States\WordPressDriver\ModelCustomizationState;
use Crumbls\Importer\States\WordPressDriver\PostTypePartitioningState;
use Crumbls\Importer\States\WordPressDriver\TransformReviewState;
use Crumbls\Importer\States\WpXmlDriver\ExtractState;
use Crumbls\Importer\States\WpXmlDriver\PendingState;
use Crumbls\Importer\Support\DriverConfig;
use Crumbls\Importer\Support\SourceResolverManager;
use Exception;

class WpXmlDriver extends XmlDriver
{
	public static function getPriority(): int
	{
		return 90;
	}

	public static function canHandle(ImportContract $import): bool
	{
		if (!XmlDriver::canHandle($import)) {
			return false;
		}

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
			       
		} catch (Exception $e) {
			return false;
		}
	}


	public static function config(): DriverConfig
	{
		return (new DriverConfig())
			->default(PendingState::class)

			->allowTransition(PendingState::class, CreateStorageState::class)
			->allowTransition(PendingState::class, FailedState::class)

			->allowTransition(CreateStorageState::class, ExtractState::class)
			->allowTransition(CreateStorageState::class, FailedState::class)

			->allowTransition(ExtractState::class, PostTypePartitioningState::class)
			->allowTransition(ExtractState::class, FailedState::class)

			->allowTransition(PostTypePartitioningState::class, MappingState::class)
			->allowTransition(PostTypePartitioningState::class, FailedState::class)

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
			->preferredTransition(ExtractState::class, PostTypePartitioningState::class)
			->preferredTransition(PostTypePartitioningState::class, MappingState::class)
			->preferredTransition(MappingState::class, ModelCreationState::class)
			->preferredTransition(ModelCreationState::class, ModelCustomizationState::class)
			->preferredTransition(ModelCustomizationState::class, MigrationBuilderState::class)
			->preferredTransition(MigrationBuilderState::class, FactoryBuilderState::class)
			->preferredTransition(FactoryBuilderState::class, TransformReviewState::class)
			->preferredTransition(TransformReviewState::class, CompletedState::class);
	}
}