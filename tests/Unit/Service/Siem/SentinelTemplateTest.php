<?php

/**
 * The Sentinel template and the Sentinel formatter carry the same columns.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service\Siem
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

namespace OCA\Keepiq\Tests\Unit\Service\Siem;

use OCA\Keepiq\Service\Siem\SentinelRowFormatter;
use PHPUnit\Framework\TestCase;

/**
 * integrations/siem/sentinel/keepiq-dcr.json against the formatter.
 *
 * @spec openspec/changes/audit-siem-vendor-connectors/tasks.md#5.1
 */
class SentinelTemplateTest extends TestCase {

	/**
	 * Table columns, stream columns and formatter keys are one list, and the
	 * stream is the one Keepiq posts to by default.
	 *
	 * @return void
	 */
	public function testTemplateAndFormatterAgree(): void {
		$template = json_decode(
			(string)file_get_contents(__DIR__ . '/../../../../integrations/siem/sentinel/keepiq-dcr.json'),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$table = $template['resources'][0]['properties']['schema'];
		$rule = $template['resources'][1]['properties'];

		$this->assertSame('KeepiqAudit_CL', $table['name']);
		$this->assertSame(SentinelRowFormatter::COLUMNS, array_column($table['columns'], 'name'));
		$this->assertSame(SentinelRowFormatter::COLUMNS, array_column($rule['streamDeclarations']['Custom-KeepiqAudit']['columns'], 'name'));
		$this->assertSame('Custom-KeepiqAudit_CL', $rule['dataFlows'][0]['outputStream']);
		$this->assertSame('dynamic', end($table['columns'])['type']);
		$this->assertSame('datetime', $table['columns'][0]['type']);
	}//end testTemplateAndFormatterAgree()
}//end class
