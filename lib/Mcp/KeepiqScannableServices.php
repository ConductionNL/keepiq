<?php

/**
 * Keepiq MCP: scannable services
 *
 * @category Mcp
 * @package  OCA\Keepiq\Mcp
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

namespace OCA\Keepiq\Mcp;

use OCA\OpenRegister\Mcp\IMcpScannableServices;

/**
 * Tells OpenRegister which Keepiq classes its scanner may reflect for
 * #[McpTool] methods: exactly the three metadata read facades. Registered
 * under the IMcpScannableServices::keepiq alias in Application::register().
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-surface-is-exposed-only-through-the-scannable-services-opt-in
 */
class KeepiqScannableServices implements IMcpScannableServices {

	/**
	 * The scannable classes.
	 *
	 * @var list<class-string>
	 */
	public const CLASSES = [
		EntryMetadataTools::class,
		ExpiryReportTools::class,
		RotationStatusTools::class,
	];

	/**
	 * The classes OpenRegister may scan.
	 *
	 * @return list<class-string>
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-surface-is-exposed-only-through-the-scannable-services-opt-in
	 */
	public function getScannableServiceClasses(): array {
		return self::CLASSES;
	}//end getScannableServiceClasses()
}//end class
