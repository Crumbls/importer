<?php

namespace Crumbls\Importer\Traits;

trait HasWordPressColumns {
	protected function getDefaultColumnDefinitions(): array {
		return [
			'ID' => new ColumnDefinition(
				originalName: 'ID',
				newName: 'id',
				type: 'unsignedBigInteger',
				transform: 'integer',
				nullable: false,
				unsigned: true
			),
			'post_title' => new ColumnDefinition(
				originalName: 'post_title',
				newName: 'title',
				type: 'string',
				length: 255,
				nullable: true
			),
			'post_content' => new ColumnDefinition(
				originalName: 'post_content',
				newName: 'content',
				type: 'longText',
				nullable: true
			),
			'post_date' => new ColumnDefinition(
				originalName: 'post_date',
				newName: 'created_at',
				type: 'timestamp',
				transform: 'datetime',
				nullable: true
			)
			// ... other definitions
		];
	}
}