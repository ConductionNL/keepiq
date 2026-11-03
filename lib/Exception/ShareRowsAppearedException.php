<?php

/**
 * Keepiq Share Rows Appeared Exception
 *
 * Thrown inside a sync's transaction when the share rows read again under the
 * source's lock include a row the request has not judged yet. It rolls the
 * transaction back so the new row is judged outside it, where a quarantine
 * that judging makes is kept whatever the sync answers. Never reaches a
 * controller: ShareSyncService catches it and tries again.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

use OCA\Keepiq\Service\JudgedShareRows;
use RuntimeException;

/**
 * The share rows read under the source's lock, carried out of the rolled-back
 * transaction.
 */
class ShareRowsAppearedException extends RuntimeException {

	/**
	 * Constructor.
	 *
	 * @param JudgedShareRows $rows The rows read under the lock, with the verdicts already made
	 *
	 * @return void
	 *
	 * @spec exclude Exception wiring only.
	 */
	public function __construct(
		public readonly JudgedShareRows $rows,
	) {
		parent::__construct(message: 'Share rows appeared under the source lock');
	}//end __construct()
}//end class
