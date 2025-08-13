<?php

namespace Crumbls\Importer\Traits;

use Exception;
use Error;
use Illuminate\Support\Facades\Storage;

trait IsDiskAware
{

	public function getAvailableDisks(): array
	{
		return once(function() {

			$options = array_keys(config('filesystems.disks', []));

			$options = array_filter($options, function ($disk) {
				try {
					$diskConfig = config("filesystems.disks.{$disk}", []);
					$driver = $diskConfig['driver'] ?? 'local';

					if (!$this->isDiskSupported($driver)) {
						return false;
					}

					Storage::disk($disk)->exists('');

					return true;
				} catch (Exception $e) {
					// Catch any remaining errors (network issues, config problems, etc.)
					return false;
				} catch (Error $e) {
					// Catch fatal errors like class not found that might slip through
					return false;
				}
			});

			return $options;

		});
	}

	protected function isDiskSupported(string $driver): bool
	{
		switch ($driver) {
			case 'local':
			case 'public':
				return true;

			case 's3':
				return class_exists('League\Flysystem\AwsS3V3\AwsS3V3Adapter') &&
					class_exists('League\Flysystem\AwsS3V3\PortableVisibilityConverter') &&
					class_exists('Aws\S3\S3Client');

			case 'rackspace':
				return class_exists('League\Flysystem\Rackspace\RackspaceAdapter');

			case 'ftp':
				return class_exists('League\Flysystem\Ftp\FtpAdapter') &&
					extension_loaded('ftp');

			case 'sftp':
				return class_exists('League\Flysystem\Sftp\SftpAdapter') &&
					extension_loaded('ssh2');

			case 'dropbox':
				return class_exists('League\Flysystem\Dropbox\DropboxAdapter');

			case 'azure':
				return class_exists('League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter');

			case 'google':
				return class_exists('League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter');

			default:
				// For unknown drivers, assume they're not supported to be safe
				return false;
		}
	}
}