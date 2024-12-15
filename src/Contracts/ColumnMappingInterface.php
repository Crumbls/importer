<?php

namespace Crumbls\Importer\Contracts;

interface ColumnMappingInterface {
	public function getColumnDefinitions(): array;
	public function mapRow(array $data): array;
}