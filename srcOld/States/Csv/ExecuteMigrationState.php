<?php

namespace Crumbls\Importer\States\Csv;

use Crumbls\Importer\States\AbstractState;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

use Nette\PhpGenerator\PhpFile;
use Nette\PhpGenerator\PsrPrinter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;

use Illuminate\Support\Facades\File;
use SplFileObject;
class ExecuteMigrationState extends \Crumbls\Importer\States\ExecuteMigrationState {

	public function canTransition(): bool
	{
		$val = $this->getDriver()->getParameter('delimiter');
		return $val !== null;
	}

	public function getNextState(): ?string
	{
		return ReadHeadersState::class;
	}
}