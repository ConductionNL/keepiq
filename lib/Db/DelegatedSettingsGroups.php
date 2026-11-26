<?php

/**
 * Keepiq DelegatedSettingsGroups
 *
 * @category Db
 * @package  OCA\Keepiq\Db
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

namespace OCA\Keepiq\Db;

use OCP\IDBConnection;

/**
 * Reads which groups Nextcloud's administration privileges delegate a settings class to. Nextcloud keeps these in
 * its `authorized_groups` table and offers no public API to list them.
 *
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */
class DelegatedSettingsGroups {
	/**
	 * Constructor for DelegatedSettingsGroups.
	 *
	 * @param IDBConnection $db The database
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; no behaviour.
	 */
	public function __construct(
		private IDBConnection $db,
	) {
	}//end __construct()

	/**
	 * The ids of the groups the settings class is delegated to.
	 *
	 * @param string $settingsClass The settings class, as `#[AuthorizedAdminSetting]` names it
	 *
	 * @return string[]
	 *
	 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
	 */
	public function groupIdsFor(string $settingsClass): array {
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('group_id')
			->from('authorized_groups')
			->where($qb->expr()->eq('class', $qb->createNamedParameter($settingsClass)));

		$result = $qb->executeQuery();
		$groupIds = [];
		while (($row = $result->fetch()) !== false) {
			$groupIds[] = (string)$row['group_id'];
		}

		$result->closeCursor();

		return $groupIds;
	}//end groupIdsFor()
}//end class
