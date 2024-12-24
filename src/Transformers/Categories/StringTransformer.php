<?php

namespace Crumbls\Importer\Transformers\Categories;

use Crumbls\Importer\Transformers\AbstractTransformer;
use Illuminate\Support\Str;

class StringTransformer extends AbstractTransformer
{
	public function getName(): string
	{
		return 'string';
	}

	public function getOperationNames(): array
	{
		return [
			'string',
			'trim',
			'ltrim',
			'rtrim',
			'uppercase',
			'lowercase',
			'capitalize',
			'strip_tags',
			'normalize_whitespace',
			'remove_non_ascii',
			'remove_special_chars',
			'slugify',
			'limit',
			'replace',
			'regex_replace',
			'substring',
			'prefix',
			'suffix',
			'pad',
			'mask',
			'encode_html',
			'decode_html',
			'ucfirst',
			'lcfirst',
			'title_case',
			'camel_case',
			'snake_case',
			'studly_case',
			'kebab_case',
			'md5',
			'sha1',
			'base64_encode',
			'base64_decode',
			'word_wrap'
		];
	}

	public function transform(mixed $value, array $parameters = []): mixed
	{
		if ($value === null) return null;

		$operation = $parameters['type'] ?? 'string';

		return match ($operation) {
			'trim' => trim($value),
			'ltrim' => ltrim($value, $parameters['characters'] ?? " \t\n\r\0\x0B"),
			'rtrim' => rtrim($value, $parameters['characters'] ?? " \t\n\r\0\x0B"),
			'uppercase' => strtoupper($value),
			'lowercase' => strtolower($value),
			'capitalize' => ucwords($value),
			'strip_tags' => strip_tags($value, $parameters['allowed_tags'] ?? ''),
			'normalize_whitespace' => preg_replace('/\s+/', ' ', trim($value)),
			'remove_non_ascii' => preg_replace('/[^A-Za-z0-9]/', '', $value),
			'remove_special_chars' => preg_replace('/[^A-Za-z0-9\-]/', '', $value),
			'slugify' => Str::slug($value, $parameters['separator'] ?? '-'),
			'limit' => Str::limit($value, $parameters['limit'] ?? 100, $parameters['end'] ?? '...'),
			'replace' => str_replace($parameters['search'], $parameters['replace'], $value),
			'regex_replace' => preg_replace($parameters['pattern'], $parameters['replacement'], $value),
			'substring' => substr($value, $parameters['start'] ?? 0, $parameters['length'] ?? null),
			'prefix' => ($parameters['prefix'] ?? '') . $value,
			'suffix' => $value . ($parameters['suffix'] ?? ''),
			'pad' => str_pad($value, $parameters['length'] ?? 0, $parameters['pad_string'] ?? ' ', $parameters['pad_type'] ?? STR_PAD_RIGHT),
			'mask' => $this->mask($value, $parameters),
			'encode_html' => htmlspecialchars($value, ENT_QUOTES, 'UTF-8'),
			'decode_html' => htmlspecialchars_decode($value, ENT_QUOTES),
			'ucfirst' => ucfirst($value),
			'lcfirst' => lcfirst($value),
			'title_case' => Str::title($value),
			'camel_case' => Str::camel($value),
			'snake_case' => Str::snake($value),
			'studly_case' => Str::studly($value),
			'kebab_case' => Str::kebab($value),
			'md5' => md5($value),
			'sha1' => sha1($value),
			'base64_encode' => base64_encode($value),
			'base64_decode' => base64_decode($value),
			'word_wrap' => wordwrap($value, $parameters['width'] ?? 75, $parameters['break'] ?? "\n", $parameters['cut'] ?? false),
			default => (string)$value
		};
	}

	protected function mask(string $value, array $parameters): string
	{
		$maskChar = $parameters['char'] ?? '*';
		$start = $parameters['start'] ?? 0;
		$length = $parameters['length'] ?? null;
		$maskLength = $length ?? strlen($value) - $start;

		return substr($value, 0, $start)
			. str_repeat($maskChar, $maskLength)
			. substr($value, $start + $maskLength);
	}
}
