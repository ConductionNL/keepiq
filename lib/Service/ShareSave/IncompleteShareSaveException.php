<?php

/**
 * Keepiq IncompleteShareSaveException
 *
 * @category Service
 * @package  OCA\Keepiq\Service\ShareSave
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

namespace OCA\Keepiq\Service\ShareSave;

use OCP\AppFramework\Http;

/**
 * The lists of a share-save are not exactly the copies and partner shares
 * the caller must write. Carries the current lists, so the client encrypts
 * for what is missing and sends again.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/user-sharing/spec.md#requirement-a-share-save-names-every-copy-or-is-refused
 */
class IncompleteShareSaveException extends ShareSaveRefusal {
	/**
	 * Constructor.
	 *
	 * @param string $path `owner` or `copy`
	 * @param string|null $sourceUpdatedAt The source's current version
	 * @param array{required:list<array<string,mixed>>,missing:list<string>,unexpected:list<string>,skipRefused:list<string>} $copies The local copies
	 * @param array{required:list<array<string,mixed>>,missing:list<string>,unexpected:list<string>} $federated The partner shares
	 *
	 * @return void
	 */
	public function __construct(string $path, ?string $sourceUpdatedAt, array $copies, array $federated) {
		parent::__construct(
			error: 'incomplete',
			message: 'The save does not name exactly the copies and partner shares to write',
			status: Http::STATUS_CONFLICT,
			details: [
				'path' => $path,
				'sourceUpdatedAt' => $sourceUpdatedAt,
				'copies' => $copies,
				'federated' => $federated,
			],
		);
	}//end __construct()
}//end class
