<?php

/**
 * Keepiq Migration Incomplete Exception
 *
 * Thrown when a compromise-recovery migration is asked to terminate while rows
 * in one or more suite-bound stores are still encrypted under the old suite.
 * Terminating would mark the old suite compromised and make exactly those rows
 * permanently unreadable, so the migration stays `in_progress` and resumable
 * instead. Controllers map this to an HTTP 409 response.
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Exception;

use RuntimeException;

/**
 * Thrown when a migration cannot yet be terminated.
 */
class MigrationIncompleteException extends RuntimeException {
	/**
	 * How many unrecoverable records the caller must acknowledge, when that is
	 * the reason for the refusal.
	 *
	 * The client MUST echo this number back rather than counting its own
	 * failure list. Those are different quantities: the client accumulates one
	 * entry per failed RECORD across every pass of the loop, while the
	 * authoritative number is the distinct records currently recorded as
	 * failed. They diverged whenever a secret failed alongside its own version
	 * or grant, or the same record failed on two passes — and because the
	 * server compared with a strict `===`, every acknowledgement was refused
	 * and the vault stayed write-locked with no way out.
	 *
	 * Null when the refusal is not about acknowledgement (rows nobody has
	 * attempted yet, which resuming fixes).
	 *
	 * @var integer|null
	 */
	private ?int $requiredAck = null;

	/**
	 * Set the acknowledgement the caller must send back.
	 *
	 * @param integer $required The number of unrecoverable records
	 *
	 * @return self
	 */
	public function withRequiredAcknowledgement(int $required): self {
		$this->requiredAck = $required;
		return $this;
	}//end withRequiredAcknowledgement()

	/**
	 * The acknowledgement the caller must send back, if any.
	 *
	 * @return integer|null
	 */
	public function getRequiredAcknowledgement(): ?int {
		return $this->requiredAck;
	}//end getRequiredAcknowledgement()
}//end class
