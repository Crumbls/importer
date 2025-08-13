<?php

namespace Crumbls\Importer\Console\Widgets\Concerns;

use Illuminate\Support\Facades\Log;

trait HasConstraints
{

	public function getConstraints() : array {
		Log::info(get_object_vars($this));
	}
}