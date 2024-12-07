<?php

namespace Crumbls\Importer\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportLog extends Model
{
	/**
	 * The attributes that aren't mass assignable.
	 *
	 * @var array<string>
	 */
	protected $guarded = [];

	/**
	 * The attributes that should be cast.
	 *
	 * @var array<string, string>
	 */
	protected $casts = [
		'context' => 'array'
	];

	/**
	 * Get the import that owns the log.
	 */
	public function import(): BelongsTo
	{
		return $this->belongsTo(Import::class);
	}

	/**
	 * Create a new error log entry
	 */
	public static function error(Import $import, string $message, array $context = []): self
	{
		return static::create([
			'import_id' => $import->id,
			'level' => 'error',
			'message' => $message,
			'context' => $context
		]);
	}

	/**
	 * Create a new warning log entry
	 */
	public static function warning(Import $import, string $message, array $context = []): self
	{
		return static::create([
			'import_id' => $import->id,
			'level' => 'warning',
			'message' => $message,
			'context' => $context
		]);
	}

	/**
	 * Create a new info log entry
	 */
	public static function info(Import $import, string $message, array $context = []): self
	{
		return static::create([
			'import_id' => $import->id,
			'level' => 'info',
			'message' => $message,
			'context' => $context
		]);
	}
}