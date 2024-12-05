<?php

namespace Crumbls\Importer\Drivers;

	use Crumbls\Importer\States\Csv\DefineModelState;
	use Crumbls\Importer\States\Csv\DetectDelimiterState;
	use Crumbls\Importer\States\Csv\ExecuteMigration;
	use Crumbls\Importer\States\Csv\ExecuteMigrationState;
	use Crumbls\Importer\States\Csv\GenerateMigrationState;
	use Crumbls\Importer\States\Csv\ParseState;
	use Crumbls\Importer\States\Csv\ReadHeadersState;
	use Crumbls\Importer\States\Csv\ValidateState;
	use Crumbls\Importer\States\Csv\ReadState;
	use Crumbls\Importer\States\Csv\ProcessState;


	class CsvDriver extends AbstractDriver
{

	public function __construct()
	{
		$this->states = [
			ValidateState::class,
			DetectDelimiterState::class,
			ReadHeadersState::class,
			DefineModelState::class,
			GenerateMigrationState::class,
			ExecuteMigrationState::class,
			ParseState::class
		];

		$this->setCurrentState(ValidateState::class);

		$this->setParameter('lines_per_iteration', 10);
	}

	/**
	 * Allow manual definition of the headers.
	 * @param array $headers
	 * @return $this
	 */
	public function setHeaders(array $headers) : self {
		$this->setParameter('headers', $headers);
		return $this;
	}

}