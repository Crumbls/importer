<?php

namespace Crumbls\Importer\Traits;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;

trait IsComposerAware {
	private static array $composerJson;

	public static function getComposerJson() : array {
		if (isset(static::$composerJson)) {
			return static::$composerJson;
		}
		static::$composerJson = json_decode(file_get_contents(base_path('composer.json')), true);

		if (!isset(static::$composerJson['autoload']['psr-4'])) {
			throw new \RuntimeException("No PSR-4 autoload configuration found in composer.json");
		}

		return static::$composerJson;
	}

	/**
	 * TODO: Add caching.
	 * @param string $fullyQualifiedClassName
	 * @return string
	 */
	public static function getComposerPathToClass(string $fullyQualifiedClassName) : string {

		// Get namespace and class name
		$lastBackslash = strrpos($fullyQualifiedClassName, '\\');
		if ($lastBackslash === false) {
			throw new \InvalidArgumentException("Class name must include namespace");
		}

		$namespace = substr($fullyQualifiedClassName, 0, $lastBackslash);

		$json = static::getComposerJson();

		// Look through PSR-4 autoload mappings
		foreach ($json['autoload']['psr-4'] as $prefix => $path) {
			$prefix = rtrim($prefix, '\\');
			if (strpos($namespace, $prefix) === 0) {
				// Found matching namespace prefix
				$relativePath = str_replace('\\', '/', substr($namespace, strlen($prefix)));
				$basePath = rtrim($path, '/');

				return str_replace('//', '/', sprintf(
					'%s/%s/%s/%s.php',
					base_path(),
					$basePath,
					$relativePath,
					class_basename($fullyQualifiedClassName)
				));
			}
		}

		throw new \RuntimeException('No matching PSR-4 autoload configuration found for namespace');
	}


}