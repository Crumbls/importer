<?php

namespace Crumbls\Importer\Models;

use Crumbls\Importer\Drivers\Contracts\DriverContract;
use Crumbls\Importer\Drivers\AutoDriver;
use Crumbls\Importer\Models\Contracts\ImportContract;
use Crumbls\StateMachine\Traits\HasStateMachine;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Import extends Model implements ImportContract
{
    use HasFactory, HasStateMachine;

	protected ?DriverContract $_driver;

	protected $fillable = [
        'driver',
        'source_type',
        'source_detail',
        'state',
        'state_machine_data',
        'progress',
        'metadata',
        'result',
        'error_message',
        'user_id',
        'batch_id',
        'started_at',
        'completed_at',
        'failed_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'result' => 'array',
        'state_machine_data' => 'array',
        'progress' => 'integer',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

	public static function booted() {
		static::creating(function(ImportContract $record) {
			if (!$record->driver) {
				$record->driver = config('importer.default_driver', AutoDriver::class);
			}
			
			// Set initial state if not set
			if (!$record->state) {
				$driverClass = $record->driver;
				if (class_exists($driverClass) && method_exists($driverClass, 'config')) {
					$config = $driverClass::config();
					if (method_exists($config, 'getDefaultState')) {
						$record->state = $config->getDefaultState();
					}
				}
			}
		});
	}

    public function scopeByDriver($query, string $driver)
    {
        return $query->where('driver', $driver);
    }

    public function scopeByState($query, string $state)
    {
        return $query->where('state', $state);
    }

	public function setImporter(DriverContract $importer) : static {
		$this->importer = $importer;
		return $this;
	}

	public function getDriver() : DriverContract {
		if (isset($this->_driver) && $this->_driver) {
			return $this->_driver;
		}
		$driver = $this->driver;
		if (!class_exists($driver)) {
			$driver = AutoDriver::class;
			$this->update([
				'driver' => $driver
			]);
		}
		$this->_driver = $driver::fromModel($this);
		return $this->_driver;
	}

	public function clearDriver(): void
	{
		$this->_driver = null;
	}

	public function clearStateMachine(): void
	{
		$this->stateMachineInstance = null;
	}

	/**
	 * Get the current state machine instance
	 */
	public function getStateMachine(): \Crumbls\StateMachine\StateMachine
	{
		return $this->stateMachine();
	}

	/**
	 * Override to properly load state machine from database state
	 */
	protected function loadStateMachine(): void
	{
		$data = $this->getAttribute($this->getStateMachineColumn());
		
		if (empty($data)) {
			// If no serialized data but we have a database state, create from that state
			if (!empty($this->state) && class_exists($this->state)) {
				$machineData = [
					'state_class' => $this->getStateMachineClass(),
					'current_state' => $this->state,
					'context' => $this->getStateMachineContext(),
				];
				$this->stateMachineInstance = \Crumbls\StateMachine\StateMachine::restore($machineData);
			} else {
				// Create new state machine with default state
				$this->stateMachineInstance = \Crumbls\StateMachine\StateMachine::make(
					$this->getStateMachineClass(),
					$this->getStateMachineContext()
				);
			}
		} else {
			// Restore from saved data
			$parsedData = is_string($data) ? json_decode($data, true) : $data;
			
			if (json_last_error() !== JSON_ERROR_NONE) {
				throw \Crumbls\StateMachine\Exceptions\StateSerializationException::invalidJson($data);
			}
			
			$this->stateMachineInstance = \Crumbls\StateMachine\StateMachine::restore($parsedData);
		}
	}

	/**
	 * Sync the database state with the state machine current state
	 */
	public function syncState(): void
	{
		$currentStateClass = get_class($this->getStateMachine()->getCurrentState());
		if ($this->state !== $currentStateClass) {
			$this->update(['state' => $currentStateClass]);
		}
	}

	/**
	 * Interact with the user's first name.
	 */
	protected function driver(): Attribute
	{
		return Attribute::make(
			get: function () {
				if (!array_key_exists('driver', $this->attributes) || !$this->attributes['driver']) {
					$this->attributes['driver'] = config('importer.default_driver', AutoDriver::class);
				}
				return $this->attributes['driver'];
			},
		);
	}

	/**
	 * Get the state machine class for this import
	 */
	public function getStateMachineClass(): string
	{
		return $this->driver;
	}

	/**
	 * Override to include the model instance in context
	 */
	protected function getStateMachineContext(): array
	{
		return [
			'model' => $this,
		];
	}

	/**
	 * Override to use the current state from database instead of default
	 */
	protected function getInitialStateMachineState(): ?string
	{
		// If we have a state stored in the database, use that
		if (!empty($this->state) && class_exists($this->state)) {
			return $this->state;
		}
		
		// Otherwise, use the default from driver config
		return null; // Let the state machine use its default
	}
}