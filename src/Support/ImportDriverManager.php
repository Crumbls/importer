<?php

namespace Crumbls\Importer\Support;

use Crumbls\Importer\Contracts\MediaImportDriver;
use Crumbls\Importer\Contracts\TagsImportDriver;
use Crumbls\Importer\Drivers\Media\SpatieMediaDriver;
use Crumbls\Importer\Drivers\Tags\SpatieTagsDriver;

/**
 * Manages import drivers for media and tags
 */
class ImportDriverManager
{
    protected array $config;
    protected array $mediaDrivers = [];
    protected array $tagsDrivers = [];

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'media_driver' => 'auto',
            'tags_driver' => 'auto',
            'drivers' => [
                'media' => [
                    'spatie' => SpatieMediaDriver::class,
                ],
                'tags' => [
                    'spatie' => SpatieTagsDriver::class,
                ]
            ]
        ], $config);
    }

    public function mediaDriver(string $driver = null): MediaImportDriver
    {
        $driver = $driver ?: $this->config['media_driver'];
        
        if ($driver === 'auto') {
            $driver = $this->detectBestMediaDriver();
        }

        if (!isset($this->mediaDrivers[$driver])) {
            $this->mediaDrivers[$driver] = $this->createMediaDriver($driver);
        }

        return $this->mediaDrivers[$driver];
    }

    public function tagsDriver(string $driver = null): TagsImportDriver
    {
        $driver = $driver ?: $this->config['tags_driver'];
        
        if ($driver === 'auto') {
            $driver = $this->detectBestTagsDriver();
        }

        if (!isset($this->tagsDrivers[$driver])) {
            $this->tagsDrivers[$driver] = $this->createTagsDriver($driver);
        }

        return $this->tagsDrivers[$driver];
    }

    protected function createMediaDriver(string $driver): MediaImportDriver
    {
        $driverClass = $this->config['drivers']['media'][$driver] ?? null;
        
        if (!$driverClass) {
            throw new \InvalidArgumentException("Unknown media driver: {$driver}");
        }

        if (!class_exists($driverClass)) {
            throw new \RuntimeException("Media driver class not found: {$driverClass}");
        }

        $instance = new $driverClass($this->getDriverConfig('media', $driver));

        if (!$instance instanceof MediaImportDriver) {
            throw new \RuntimeException("Driver must implement MediaImportDriver interface");
        }

        if (!$instance->isAvailable()) {
            throw new \RuntimeException("Media driver '{$driver}' is not available");
        }

        return $instance;
    }

    protected function createTagsDriver(string $driver): TagsImportDriver
    {
        $driverClass = $this->config['drivers']['tags'][$driver] ?? null;
        
        if (!$driverClass) {
            throw new \InvalidArgumentException("Unknown tags driver: {$driver}");
        }

        if (!class_exists($driverClass)) {
            throw new \RuntimeException("Tags driver class not found: {$driverClass}");
        }

        $instance = new $driverClass($this->getDriverConfig('tags', $driver));

        if (!$instance instanceof TagsImportDriver) {
            throw new \RuntimeException("Driver must implement TagsImportDriver interface");
        }

        if (!$instance->isAvailable()) {
            throw new \RuntimeException("Tags driver '{$driver}' is not available");
        }

        return $instance;
    }

    protected function detectBestMediaDriver(): string
    {
        // Try drivers in order of preference
        $drivers = ['spatie'];
        
        foreach ($drivers as $driver) {
            if ($this->isMediaDriverAvailable($driver)) {
                return $driver;
            }
        }

        throw new \RuntimeException('No media drivers available');
    }

    protected function detectBestTagsDriver(): string
    {
        // Try drivers in order of preference
        $drivers = ['spatie'];
        
        foreach ($drivers as $driver) {
            if ($this->isTagsDriverAvailable($driver)) {
                return $driver;
            }
        }

        return 'none'; // Return 'none' instead of throwing exception
    }

    protected function isMediaDriverAvailable(string $driver): bool
    {
        try {
            $instance = $this->createMediaDriver($driver);
            return $instance->isAvailable();
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function isTagsDriverAvailable(string $driver): bool
    {
        try {
            $instance = $this->createTagsDriver($driver);
            return $instance->isAvailable();
        } catch (\Exception $e) {
            return false;
        }
    }

    protected function getDriverConfig(string $type, string $driver): array
    {
        return $this->config['driver_config'][$type][$driver] ?? [];
    }

    /**
     * Get available media drivers
     */
    public function getAvailableMediaDrivers(): array
    {
        $available = [];
        
        foreach (array_keys($this->config['drivers']['media']) as $driver) {
            if ($this->isMediaDriverAvailable($driver)) {
                $available[] = $driver;
            }
        }
        
        return $available;
    }

    /**
     * Get available tags drivers
     */
    public function getAvailableTagsDrivers(): array
    {
        $available = [];
        
        foreach (array_keys($this->config['drivers']['tags']) as $driver) {
            if ($this->isTagsDriverAvailable($driver)) {
                $available[] = $driver;
            }
        }
        
        return $available;
    }
}