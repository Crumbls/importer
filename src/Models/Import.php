<?php

namespace Crumbls\Importer\Models;

use Crumbls\Importer\Contracts\DriverInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Import extends Model
{
	protected DriverInterface $_driver;

	protected $guarded = [];

	protected $casts = [
		'config' => 'array',
		'metadata' => 'array',
		'cursor' => 'array',
		'started_at' => 'datetime',
		'completed_at' => 'datetime'
	];

	/**
	 * For now, this lives here.
	 * @return void
	 */
	public static function booted(): void
	{
		static::creating(function (Model $record) {
			$record->state;
		});
	}

	/**
	 * Get the import logs for this import.
	 */
	public function logs(): HasMany
	{
		return $this->hasMany(ImportLog::class);
	}

	/**
	 * Update the import progress
	 */
	public function updateProgress(string $stage, array $cursor = [], array $metadata = []): void
	{
		$this->update([
			'cursor' => array_merge($this->cursor ?? [], $cursor, ['current_stage' => $stage]),
			'metadata' => array_merge($this->metadata ?? [], $metadata)
		]);
	}

	/**
	 * Mark the import as failed
	 */
	public function markAsFailed(string $reason): void
	{
		$this->update([
			'status' => 'failed',
			'metadata' => array_merge($this->metadata ?? [], [
				'failure_reason' => $reason
			])
		]);
	}

	/**
	 * @return DriverInterface
	 * @throws \Exception
	 */
	public function getDriver(): DriverInterface
	{
		if (isset($this->_driver)) {
			return $this->_driver;
		}
		if (!array_key_exists('driver', $this->attributes) || !$this->attributes['driver']) {
			/**
			 * Convert to a better exception.
			 */
			throw new \Exception('Driver not defined.');
		}

		$this->_driver = app('importer')
			->driver($this->attributes['driver']);

		$this->_driver
			->setRecord($this);

		/**
		 * TODO: Add configuration as necessary.
		 */

		return $this->_driver;
	}

	/**
	 * Get our current state.
	 * @return string
	 * @throws \Exception
	 */
	public function getStateAttribute(): string
	{
		if (array_key_exists('state', $this->attributes) && $this->attributes['state']) {
			return $this->attributes['state'];
		}
		$driver = $this->getDriver();
		$this->attributes['state'] = $driver->getStateDefault();
		return $this->attributes['state'];
	}
}