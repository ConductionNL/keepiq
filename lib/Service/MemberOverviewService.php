<?php

/**
 * Keepiq Member Overview Service
 *
 * The administrator's list of Nextcloud users with their vault status
 * (admin-member-overview-and-offboarding D4). Metadata only: user ids,
 * display names, statuses, suite ids, counts and dates. It never reads or
 * returns a certificate, a wrapped private key, a secret name or any
 * ciphertext.
 *
 * Users are paged through Nextcloud's user manager. Every page is resolved
 * with one query per data source (active suites, newest inactive suite
 * status, secret counts, direct team folder memberships, emergency
 * contacts), never one query per user.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Lists every Nextcloud user with their Keepiq vault status.
 */
class MemberOverviewService {
	/**
	 * The vault statuses a row can carry, and the status filter accepts.
	 *
	 * @var string[]
	 */
	public const STATUSES = ['none', 'active', 'revoked', 'compromised'];

	/**
	 * Default page size.
	 */
	public const DEFAULT_LIMIT = 50;

	/**
	 * Largest page size a caller may ask for (design risk: large instances).
	 */
	public const MAX_LIMIT = 200;

	/**
	 * How many users one scan step reads while a status filter is applied.
	 */
	private const SCAN_BATCH = 200;

	/**
	 * Constructor.
	 *
	 * @param IUserManager $userManager The Nextcloud user manager
	 * @param EncryptionSuiteMapper $suiteMapper Suite lookups (status and id only)
	 * @param SecretMapper $secretMapper Secret counts
	 * @param TeamFolderMemberMapper $memberMapper Direct team folder membership counts
	 * @param EmergencyContactMapper $contactMapper Emergency contacts in force
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private IUserManager $userManager,
		private EncryptionSuiteMapper $suiteMapper,
		private SecretMapper $secretMapper,
		private TeamFolderMemberMapper $memberMapper,
		private EmergencyContactMapper $contactMapper,
	) {
	}//end __construct()

	/**
	 * One page of the member overview.
	 *
	 * Without a status filter, the page is one user manager page. With a
	 * filter, users are read in batches until the page is full or the users
	 * run out, so `offset` counts matching rows, not users.
	 *
	 * @param string $status Vault status filter (`none`, `active`, `revoked`, `compromised`) or ''
	 * @param string $search Search on user id or display name, '' for everyone
	 * @param int $limit Page size, 1 to 200
	 * @param int $offset Rows to skip, 0 or more
	 *
	 * @return array{results:array<int,array<string,mixed>>,limit:int,offset:int,hasMore:bool}
	 *
	 * @throws InvalidArgumentException On an unknown status or an out-of-range page
	 *
	 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#2.1
	 */
	public function list(string $status, string $search, int $limit, int $offset): array {
		if ($status !== '' && in_array($status, self::STATUSES, true) === false) {
			throw new InvalidArgumentException(
				message: 'status must be one of: ' . implode(', ', self::STATUSES)
			);
		}

		if ($limit < 1 || $limit > self::MAX_LIMIT) {
			throw new InvalidArgumentException(message: 'limit must be between 1 and ' . self::MAX_LIMIT);
		}

		if ($offset < 0) {
			throw new InvalidArgumentException(message: 'offset must be 0 or more');
		}

		if ($status === '') {
			// One extra user tells whether another page exists.
			$rows = $this->resolve(users: $this->userManager->searchDisplayName($search, $limit + 1, $offset));
		} else {
			$rows = $this->scanForStatus(status: $status, search: $search, wanted: $offset + $limit + 1);
			$rows = array_slice($rows, $offset);
		}

		return [
			'results' => array_slice($rows, 0, $limit),
			'limit' => $limit,
			'offset' => $offset,
			'hasMore' => count($rows) > $limit,
		];
	}//end list()

	/**
	 * Read users in batches and keep the rows with the wanted status.
	 *
	 * @param string $status The vault status to keep
	 * @param string $search The user search
	 * @param int $wanted Stop once this many matching rows are found
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#2.1
	 */
	private function scanForStatus(string $status, string $search, int $wanted): array {
		$matched = [];
		$cursor = 0;
		do {
			$batch = $this->userManager->searchDisplayName($search, self::SCAN_BATCH, $cursor);
			foreach ($this->resolve(users: $batch) as $row) {
				if ($row['vaultStatus'] === $status) {
					$matched[] = $row;
				}
			}

			$cursor += count($batch);
		} while (count($batch) === self::SCAN_BATCH && count($matched) < $wanted);

		return $matched;
	}//end scanForStatus()

	/**
	 * Resolve one page of users to overview rows, one query per data source.
	 *
	 * @param array<array-key,IUser> $users The users of this page
	 *
	 * @return array<int,array<string,mixed>>
	 *
	 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#2.1
	 */
	private function resolve(array $users): array {
		$userIds = [];
		foreach ($users as $user) {
			$userIds[] = $user->getUID();
		}

		if ($userIds === []) {
			return [];
		}

		$active = $this->suiteMapper->findActiveByOwners(ownerType: 'user', ownerIds: $userIds);
		$inactive = $this->suiteMapper->latestInactiveStatusByOwners(
			ownerType: 'user',
			ownerIds: array_values(array_diff($userIds, array_keys($active)))
		);
		$secretCounts = $this->secretMapper->countByUserOwners(ownerIds: $userIds);
		$memberships = $this->memberMapper->countUserMemberships(userIds: $userIds);
		$withContact = array_flip($this->contactMapper->grantorsWithContact(userIds: $userIds));

		$rows = [];
		foreach ($users as $user) {
			$uid = $user->getUID();
			$suite = ($active[$uid] ?? null);
			$rows[] = [
				'userId' => $uid,
				'displayName' => $user->getDisplayName(),
				'enabled' => $user->isEnabled(),
				'vaultStatus' => $this->vaultStatus(hasActive: $suite !== null, inactiveStatus: ($inactive[$uid] ?? null)),
				'activeSuiteId' => $suite?->getId(),
				'suiteCreatedAt' => $suite?->getCreatedAt()?->format('c'),
				'secretCount' => ($secretCounts[$uid] ?? 0),
				'teamFolderMemberships' => ($memberships[$uid] ?? 0),
				'hasEmergencyContact' => isset($withContact[$uid]),
			];
		}

		return $rows;
	}//end resolve()

	/**
	 * Map the suite lookups to one vault status.
	 *
	 * @param bool $hasActive Whether the user has an active suite
	 * @param string|null $inactiveStatus The newest non-active suite status, if any
	 *
	 * @return string One of STATUSES
	 *
	 * @spec openspec/changes/admin-member-overview-and-offboarding/tasks.md#2.1
	 */
	private function vaultStatus(bool $hasActive, ?string $inactiveStatus): string {
		if ($hasActive === true) {
			return 'active';
		}

		if ($inactiveStatus === null) {
			return 'none';
		}

		if ($inactiveStatus === 'compromised') {
			return 'compromised';
		}

		return 'revoked';
	}//end vaultStatus()
}//end class
