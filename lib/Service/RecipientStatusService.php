<?php

/**
 * Keepiq Recipient Status Service
 *
 * Answers, for the users a sharee search returned, which of them can receive
 * a share yet (keepiq#37). It reruns Nextcloud's own sharee search as the
 * caller and answers only about ids that search returns, so it never says
 * anything about a user the caller could not have found in the share dialog.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCP\Collaboration\Collaborators\ISearch;
use OCP\Share\IShare;

/**
 * Marks which users of one sharee search hold an active encryption suite.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
 */
class RecipientStatusService {
	/**
	 * The most users one answer covers: one page of the share dialog's search.
	 *
	 * @var int
	 */
	public const MAX_USERS = 25;

	/**
	 * Constructor for RecipientStatusService.
	 *
	 * @param ISearch $collaboratorSearch Nextcloud's sharee search, run as the session user
	 * @param ShareService $shareService The share service (active suite lookup)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private ISearch $collaboratorSearch,
		private ShareService $shareService,
	) {
	}//end __construct()

	/**
	 * For each requested user that the sharee search for `$search` returns,
	 * whether they hold an active suite. Requested ids the search does not
	 * return are left out of the answer entirely.
	 *
	 * @param string $search The term the caller searched for
	 * @param array<int,string> $userIds Distinct user ids from that search, at most MAX_USERS
	 *
	 * @return array<int,array{userId:string,hasSuite:bool}> In the order requested
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-recipient-search-marks-who-cannot-receive-a-share
	 */
	public function statusFor(string $search, array $userIds): array {
		$findable = $this->findableUserIds(search: $search);
		$visible = array_values(
			array_filter(
				$userIds,
				static fn (string $userId): bool => isset($findable[$userId]) === true
			)
		);
		if ($visible === []) {
			return [];
		}

		$certificates = $this->shareService->recipientCertificates(targetUserIds: $visible);

		return array_map(
			static fn (string $userId): array => [
				'userId' => $userId,
				'hasSuite' => isset($certificates[$userId]) === true,
			],
			$visible
		);
	}//end statusFor()

	/**
	 * The user ids the sharee search returns for a term, as a set. Same
	 * shape as the share dialog's own call: users only, no lookup server,
	 * one page.
	 *
	 * @param string $search The search term
	 *
	 * @return array<string,true>
	 *
	 * @psalm-suppress DeprecatedMethod ISearch::search() is the only form on
	 * Nextcloud 32 to 34; filteredSearch() arrives in 35.
	 */
	private function findableUserIds(string $search): array {
		[$result] = $this->collaboratorSearch->search(
			$search,
			[IShare::TYPE_USER],
			false,
			self::MAX_USERS,
			0
		);

		$rows = array_merge(
			($result['exact']['users'] ?? []),
			($result['users'] ?? [])
		);
		$found = [];
		foreach ($rows as $row) {
			$userId = ($row['value']['shareWith'] ?? null);
			if (is_string($userId) === true && $userId !== '') {
				$found[$userId] = true;
			}
		}

		return $found;
	}//end findableUserIds()
}//end class
