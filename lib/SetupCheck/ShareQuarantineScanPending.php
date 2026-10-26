<?php

/**
 * Keepiq Share Quarantine Scan Setup Check
 *
 * Warns on the admin overview while the share quarantine scan
 * (GHSA-m5p9-6pw7-pf3g) has not completed a full pass, as planted share rows
 * stay live until it does.
 *
 * @category SetupCheck
 * @package  OCA\Keepiq\SetupCheck
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

namespace OCA\Keepiq\SetupCheck;

use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCP\IL10N;
use OCP\SetupCheck\ISetupCheck;
use OCP\SetupCheck\SetupResult;

/**
 * Whether the share quarantine scan has completed.
 *
 * @SuppressWarnings(PHPMD.StaticAccess) OCP\SetupCheck\SetupResult's named
 *   constructors are the only way to build a result; there is no instance API.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
 */
class ShareQuarantineScanPending implements ISetupCheck {
	/**
	 * Constructor.
	 *
	 * @param ShareQuarantineScanner $scanner Knows whether a full pass completed
	 * @param IL10N $l10n The app's translations
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private ShareQuarantineScanner $scanner,
		private IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The admin overview category.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function getCategory(): string {
		return 'security';
	}//end getCategory()

	/**
	 * The check's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function getName(): string {
		return $this->l10n->t('Keepiq share quarantine scan');
	}//end getName()

	/**
	 * A warning while no full pass has completed.
	 *
	 * @return SetupResult
	 *
	 * @spec openspec/specs/user-sharing/spec.md#requirement-planted-share-rows-are-quarantined
	 */
	public function run(): SetupResult {
		if ($this->scanner->isComplete() === true) {
			return SetupResult::success($this->l10n->t('The share quarantine scan has completed.'));
		}

		return SetupResult::warning(
			$this->l10n->t(
				'Keepiq has not finished scanning for share rows planted through GHSA-m5p9-6pw7-pf3g, '
				.'so such rows are not quarantined yet. The ShareQuarantineScanJob background job retries every 15 minutes: '
				.'check that background jobs run, and look in the Nextcloud log for errors from the share quarantine scan.'
			)
		);
	}//end run()
}//end class
