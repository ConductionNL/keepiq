<?php

/**
 * Keepiq SIEM formatter: CEF
 *
 * @category Service
 * @package  OCA\Keepiq\Service\Siem
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service\Siem;

/**
 * ArcSight Common Event Format, for QRadar, ArcSight and Sentinel's CEF
 * connector, sent as the MSG of the RFC 5424 frame.
 *
 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-cef-formatting-over-syslog
 */
final class CefFormatter {

	/**
	 * Severity per event category (0-10). A category not listed is 3.
	 *
	 * @var array<string,int>
	 */
	public const SEVERITY = [
		'honey' => 10,
		'suite' => 8,
		'emergency' => 7,
		'share' => 5,
	];

	/**
	 * Severity for any other category.
	 *
	 * @var int
	 */
	public const DEFAULT_SEVERITY = 3;

	/**
	 * Build the CEF line.
	 *
	 * @param array<string,mixed> $payload The buildPayload() array
	 * @param string $appVersion The Keepiq version for the header
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-cef-formatting-over-syslog
	 */
	public function format(array $payload, string $appVersion): string {
		$view = new PayloadView(payload: $payload);
		$eventType = $view->get(field: 'eventType');
		$category = $view->get(field: 'category');
		$header = [
			'CEF:0',
			'Conduction',
			'Keepiq',
			self::header(value: $appVersion),
			self::header(value: $eventType),
			self::header(value: str_replace(['.', '_'], ' ', $eventType)),
			(string)(self::SEVERITY[$category] ?? self::DEFAULT_SEVERITY),
		];

		$extensions = [
			'rt' => (string)(int)($view->epoch() * 1000),
			'cat' => $category,
			'act' => $eventType,
		];
		if ($view->get(field: 'actorType') === 'user') {
			$extensions['suser'] = $view->get(field: 'actorId');
		}

		$extensions += [
			'cs1Label' => 'actorType',
			'cs1' => $view->get(field: 'actorType'),
			'cs2Label' => 'objectType',
			'cs2' => $view->get(field: 'objectType'),
			'cs3Label' => 'objectId',
			'cs3' => $view->get(field: 'objectId'),
			'msg' => (string)json_encode((object)$view->metadata()),
		];

		$pairs = [];
		foreach ($extensions as $key => $value) {
			$pairs[] = $key . '=' . self::extension(value: $value);
		}

		return implode('|', $header) . '|' . implode(' ', $pairs);
	}//end format()

	/**
	 * Escape a header field: backslash and pipe.
	 *
	 * @param string $value The raw value
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-cef-formatting-over-syslog
	 */
	public static function header(string $value): string {
		return str_replace(['\\', '|', "\r", "\n"], ['\\\\', '\\|', ' ', ' '], $value);
	}//end header()

	/**
	 * Escape an extension value: backslash, equals sign and line breaks.
	 *
	 * @param string $value The raw value
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-cef-formatting-over-syslog
	 */
	public static function extension(string $value): string {
		return str_replace(['\\', '=', "\r\n", "\n", "\r"], ['\\\\', '\\=', '\\n', '\\n', '\\r'], $value);
	}//end extension()
}//end class
