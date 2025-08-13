<?php

namespace Crumbls\Importer\Console\Prompts\Contracts;

use Crumbls\Importer\Models\Contracts\ImportContract;
use Illuminate\Console\Command;

interface MigrationPrompt
{
	public static function build(Command $command, ?ImportContract $record = null) : MigrationPrompt;

	public static function breadcrumbs() : array;

	public function tui() : array;
}