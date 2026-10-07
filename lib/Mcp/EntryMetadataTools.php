<?php

/**
 * Keepiq MCP: metadata-only entry listing
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

use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Service\SecretService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\AppFramework\Db\DoesNotExistException;

/**
 * keepiq.listEntries: the caller's entries as metadata only.
 *
 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-metadata-only-entry-listing-tool
 */
class EntryMetadataTools {

	/**
	 * Most entries one call returns.
	 *
	 * @var int
	 */
	private const LIMIT = 200;

	/**
	 * Constructor.
	 *
	 * @param SecretService $secretService The vault read path
	 * @param EncryptionSuiteMapper $suiteMapper To tell a vault without an active suite
	 * @param McpToolContext $context The principal and the audit
	 * @param MetadataAllowList $allowList The keys a result may carry
	 *
	 * @return void
	 */
	public function __construct(
		private SecretService $secretService,
		private EncryptionSuiteMapper $suiteMapper,
		private McpToolContext $context,
		private MetadataAllowList $allowList = new MetadataAllowList(),
	) {
	}//end __construct()

	#[McpTool(
		name: 'listEntries',
		description: 'List the entries in your Keepiq vault as metadata: name, URL, type, folder and dates. '
			. 'Never returns a password, login or other secret value.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read',
		subject: 'entry',
		action: 'list'
	)]
	/**
	 * List the entries in your Keepiq vault: names, URLs, types, folders and
	 * dates. Never a password, login or any other secret value. Filter by
	 * folder, type or a name or URL search.
	 *
	 * @param string|null $folderId Only entries in this folder
	 * @param string|null $typeId Only entries of this type
	 * @param string|null $query Only entries whose name or URL matches
	 *
	 * @return array{entries: list<array<string,scalar|null>>, total: int}
	 *
	 * @spec openspec/specs/mcp-metadata-surface/spec.md#requirement-metadata-only-entry-listing-tool
	 */
	public function listEntries(?string $folderId = null, ?string $typeId = null, ?string $query = null): array {
		$userId = $this->context->userId();
		$entries = [];
		if ($this->hasActiveSuite(userId: $userId) === true) {
			$entries = $this->rows(userId: $userId, folderId: $folderId, typeId: $typeId, query: $query);
		}

		$this->context->audit(userId: $userId, tool: 'listEntries', resultCount: count($entries));
		return ['entries' => $entries, 'total' => count($entries)];
	}//end listEntries()

	/**
	 * The projected rows.
	 *
	 * @param string $userId The principal
	 * @param string|null $folderId Folder filter
	 * @param string|null $typeId Type filter
	 * @param string|null $query Name or URL search
	 *
	 * @return list<array<string,scalar|null>>
	 */
	private function rows(string $userId, ?string $folderId, ?string $typeId, ?string $query): array {
		if ($query !== null && trim($query) !== '') {
			$found = array_filter(
				$this->secretService->search(userId: $userId, term: $query, page: 1, limit: self::LIMIT)['items'],
				static fn (array $row): bool => ($folderId === null || ($row['folderId'] ?? null) === $folderId)
					&& ($typeId === null || ($row['typeId'] ?? null) === $typeId)
			);
			return $this->project(items: $found);
		}

		$items = $this->secretService->list(
			userId: $userId,
			folderId: $folderId,
			sort: null,
			direction: 'asc',
			page: 1,
			limit: self::LIMIT,
			typeId: $typeId
		)['items'];
		return $this->project(items: $items);
	}//end rows()

	/**
	 * Project each row onto the entry allow-list.
	 *
	 * @param array<array-key,array<string,mixed>> $items The rows
	 *
	 * @return list<array<string,scalar|null>>
	 */
	private function project(array $items): array {
		return array_values(array_map(fn (array $row): array => $this->allowList->project(row: $row, type: 'entry'), $items));
	}//end project()

	/**
	 * Whether the user has an active encryption suite; without one the tool
	 * answers an empty list, not an error.
	 *
	 * @param string $userId The principal
	 *
	 * @return bool
	 */
	private function hasActiveSuite(string $userId): bool {
		try {
			$this->suiteMapper->findActiveByOwner('user', $userId);
			return true;
		} catch (DoesNotExistException) {
			return false;
		}
	}//end hasActiveSuite()
}//end class
