<?php

use Crumbls\Importer\Resolvers\ModelResolver;

beforeEach(function () {
    ModelResolver::clearCache();
    
    // Reset to clean config state
    config(['importer.models' => [
        'import' => \Crumbls\Importer\Models\Import::class,
    ]]);
});

it('resolves import model from config', function () {
    $modelClass = ModelResolver::import();
    
    expect($modelClass)->toBe(\Crumbls\Importer\Models\Import::class);
});

it('resolves user model from auth config', function () {
    // Use a model class that definitely exists in the test environment
    config(['auth.providers.users.model' => \App\Models\User::class]);
    
    $modelClass = ModelResolver::user();
    
    expect($modelClass)->toBe(\App\Models\User::class);
});

it('falls back to default user model when auth config is not set', function () {
    config(['auth.providers.users.model' => null]);
    
    // Since our test User class exists, this should work
    $modelClass = ModelResolver::user();
    
    expect($modelClass)->toBe(\App\Models\User::class);
});

it('throws exception when user model class does not exist', function () {
    config(['auth.providers.users.model' => 'NonExistentUserModel']);
    
    expect(fn() => ModelResolver::user())->toThrow(InvalidArgumentException::class, "Model class 'NonExistentUserModel' does not exist.");
});

it('throws exception for non-existent model key', function () {
    ModelResolver::nonExistentModel();
})->throws(InvalidArgumentException::class, "Model 'nonexistentmodel' not found in importer configuration.");

it('throws exception with available models suggestion', function () {
    config([
        'importer.models' => [
            'import' => \Crumbls\Importer\Models\Import::class,
            'export' => 'App\Models\Export',
        ]
    ]);
    
    ModelResolver::missingModel();
})->throws(InvalidArgumentException::class, "Available models: import, export");

it('throws exception for non-existent class', function () {
    config(['importer.models.test' => 'App\\Models\\NonExistentClass']);
    
    expect(fn() => ModelResolver::test())->toThrow(InvalidArgumentException::class, "Model class 'App\\Models\\NonExistentClass' does not exist.");
});

it('caches resolved models', function () {
    $first = ModelResolver::import();
    $second = ModelResolver::import();
    
    expect($first)->toBe($second);
    expect(ModelResolver::getCache())->toHaveKey('import');
});

it('can clear cache', function () {
    ModelResolver::import();
    expect(ModelResolver::getCache())->toHaveKey('import');
    
    ModelResolver::clearCache();
    expect(ModelResolver::getCache())->toBeEmpty();
});

it('can get model instance', function () {
    $instance = ModelResolver::instance('import');
    
    expect($instance)->toBeInstanceOf(\Crumbls\Importer\Models\Import::class);
});

it('converts method name to lowercase for key resolution', function () {
    $modelClass = ModelResolver::Import();
    
    expect($modelClass)->toBe(\Crumbls\Importer\Models\Import::class);
});