<?php

namespace Crumbls\Importer\Contracts;

use Illuminate\Database\Eloquent\Model;

interface DriverInterface
{

	public function getRecord() : Model;
	/**
	 * Get the driver name
	 */
	public static function getName(): string;

	public static function getStateDefault() : string;
	public static function getRegisteredStates(): array;
	public static function getRegisteredTransitions(): array;
}