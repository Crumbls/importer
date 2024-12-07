<?php

// src/Drivers/AbstractDriver.php
namespace Crumbls\Importer\Drivers;

use Crumbls\Importer\Contracts\DriverInterface;
use Crumbls\Importer\Exceptions\DriverException;
use Crumbls\Importer\Support\BaseImporter;
use Crumbls\Importer\Concerns\HasConfiguration;
use Crumbls\Importer\Traits\HasId;
use Crumbls\Importer\Traits\HasState;
use Illuminate\Support\Arr;
use Crumbls\Importer\Concerns\HasStateMachine;

abstract class AbstractDriver
{
	use HasConfiguration,
		HasStateMachine;



}