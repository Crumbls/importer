<?php

use Crumbls\Importer\ImporterManager;
use Crumbls\Importer\Drivers\CsvDriver;

beforeEach(function () {
    $this->manager = app(ImporterManager::class);
});

it('returns csv as default driver', function () {
    expect($this->manager->getDefaultDriver())->toBe('csv');
});

it('can create csv driver', function () {
    $driver = $this->manager->driver('csv');
    
    expect($driver)->toBeInstanceOf(CsvDriver::class);
});

it('throws exception for sql driver', function () {
    expect(fn() => $this->manager->driver('sql'))
        ->toThrow(Exception::class, 'SQL driver not implemented yet');
});

it('can create multiple csv drivers with different configs', function () {
    $driver1 = $this->manager->driver('csv');
    $driver2 = $this->manager->driver('csv');
    
    expect($driver1)->toBeInstanceOf(CsvDriver::class);
    expect($driver2)->toBeInstanceOf(CsvDriver::class);
    expect($driver1)->not->toBe($driver2); // Different instances
});
