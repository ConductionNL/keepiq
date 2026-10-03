<?php

/**
 * Keepiq MCP: rotation status
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

use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\RotationFlagService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * keepiq.rotationStatus: the caller's open rotation flags and counts. It
 * changes no flag: rotating stays a client-side re-encryption plus a human
 * "mark rotated" in the app.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-rotation-status-tool
 */
class RotationStatusTools {

	/**
	 * Constructor.
	 *
	 * @param RotationFlagService $flags The rotation flags
	 * @param SecretMapper $secretMapper For the entry names
	 * @param McpToolContext $context The principal and the audit
	 * @param MetadataAllowList $allowList The keys a result may carry
	 *
	 * @return void
	 */
	public function __construct(
		private RotationFlagService $flags,
		private SecretMapper $secretMapper,
		private McpToolContext $context,
		private MetadataAllowList $allowList = new MetadataAllowList(),
	) {
	}//end __construct()

	#[McpTool(
		name: 'rotationStatus',
		description: 'List the open rotation flags in your Keepiq vault with entry name, reason and dates, and '
			. 'count how many are open, overdue by policy and from a compromised key. Changes nothing.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read',
		subject: 'rotation_flag',
		action: 'list'
	)]
	/**
	 * The caller's open flags and their counts.
	 *
	 * @return array{flags: list<array<string,scalar|null>>, counts: array{open: int, overdue: int, compromised: int}}
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-rotation-status-tool
	 */
	public function rotationStatus(): array {
		$userId = $this->context->userId();
		$rows = [];
		$counts = ['open' => 0, 'overdue' => 0, 'compromised' => 0];
		foreach ($this->flags->openFlags(userId: $userId) as $flag) {
			if ($flag instanceof RotationFlag === false) {
				continue;
			}

			$counts['open']++;
			if ($flag->getReason() === 'policy_expiry') {
				$counts['overdue']++;
			}

			if ($flag->getReason() === 'suite_compromise') {
				$counts['compromised']++;
			}

			$rows[] = $this->allowList->project(
				row: [
					'id' => $flag->getSecretId(),
					'name' => $this->nameOf(secretId: $flag->getSecretId()),
					'reason' => $flag->getReason(),
					'status' => $flag->getStatus(),
					'flaggedAt' => $flag->getFlaggedAt()?->format('c'),
					'keyUpdatedAtAtFlag' => $flag->getKeyUpdatedAtAtFlag()?->format('c'),
				],
				type: 'rotationFlag'
			);
		}//end foreach

		$this->context->audit(userId: $userId, tool: 'rotationStatus', resultCount: count($rows));
		return ['flags' => $rows, 'counts' => $counts];
	}//end rotationStatus()

	/**
	 * The entry name of a flagged secret, null when it is gone.
	 *
	 * @param string $secretId The secret
	 *
	 * @return string|null
	 */
	private function nameOf(string $secretId): ?string {
		try {
			return $this->secretMapper->findById($secretId)->getName();
		} catch (DoesNotExistException) {
			return null;
		}
	}//end nameOf()
}//end class
