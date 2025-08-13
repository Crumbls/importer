<?php

declare(strict_types=1);
namespace Crumbls\Importer\Console;

final class NavItem
{
	public function __construct(public string $className, public string $label)
	{
	}

	public function getTabTitle() : string{
		$className = $this->className;
		return $className::getTabTitle();
	}
}