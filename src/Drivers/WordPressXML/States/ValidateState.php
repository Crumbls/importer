<?php

namespace Crumbls\Importer\Drivers\WordPressXML\States;

use Crumbls\Importer\States\AbstractState;

class ValidateState extends AbstractState {
	private const REQUIRED_NAMESPACES = [
		'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
		'content' => 'http://purl.org/rss/1.0/modules/content/',
		'wfw' => 'http://wellformedweb.org/CommentAPI/',
		'dc' => 'http://purl.org/dc/elements/1.1/',
		'wp' => 'http://wordpress.org/export/1.2/'
	];

	public function getName(): string {
		return 'validate';
	}

	public function handle(): void {

		$record = $this->getRecord();

		if (!file_exists($record->source)) {
			throw new \Exception("File not found: {$record->source}");
		}

		libxml_use_internal_errors(true);
		$xml = new \DOMDocument();

		if (!$xml->load($record->source)) {
			throw new \Exception("Invalid XML file");
		}

		// Verify root element is RSS with WordPress namespaces
		$root = $xml->documentElement;
		if ($root->tagName !== 'rss') {
			throw new \Exception("Not a WordPress export file: Missing RSS root element");
		}

		// Verify channel element exists
		$channel = $xml->getElementsByTagName('channel');
		if ($channel->length === 0) {
			throw new \Exception("Invalid WordPress export: Missing channel element");
		}

		// Verify WordPress version info exists
		$wxrVersion = $channel->item(0)->getElementsByTagNameNS('http://wordpress.org/export/1.2/', 'wxr_version');
		if ($wxrVersion->length === 0) {
			throw new \Exception("Invalid WordPress export: Missing WXR version");
		}
	}
}