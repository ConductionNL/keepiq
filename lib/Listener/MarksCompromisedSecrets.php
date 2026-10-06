<?php

/**
 * Keepiq MarksCompromisedSecrets
 *
 * The part of the suite-compromise cascade that both compromise listeners
 * share: resolving a shared copy to its SOURCE Secret, and stamping and
 * flagging a Secret as possibly compromised. SuiteCompromiseListener (a
 * completed compromise migration) and CompromiseContainmentService (an
 * administrator force-revoke) must treat a shared source the same way, so the
 * logic lives here once (keepiq#802).
 *
 * @category Listener
 * @package  OCA\Keepiq\Listener
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

namespace OCA\Keepiq\Listener;

use DateTime;
use OCA\Keepiq\Db\Secret;
use OCP\AppFramework\Db\DoesNotExistException;
use Throwable;

/**
 * Stamp, flag and resolve Secrets in a suite-compromise blast radius.
 *
 * The using class provides $secretMapper, $shareTargetMapper, $logger and
 * $rotationService.
 */
trait MarksCompromisedSecrets {
	/**
	 * Stamp a Secret possibly-compromised (once) and raise its rotation flag.
	 *
	 * The flag is idempotent (rotation-expiry-policies §3.2). A failure is
	 * logged and does not stop the cascade for the other Secrets; the return
	 * value lets a caller count it (keepiq#863).
	 *
	 * @param Secret $secret The Secret to mark
	 *
	 * @return bool True when the Secret was stamped and flagged
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	private function stampAndFlag(Secret $secret): bool {
		try {
			if ($secret->getPossiblyCompromisedAt() === null) {
				$secret->setPossiblyCompromisedAt(new DateTime());
				$this->secretMapper->update($secret);
			}

			$this->rotationService?->flag(
				secretId: $secret->getId(),
				reason: 'suite_compromise'
			);
			return true;
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: could not mark secret ' . $secret->getId() . ' possibly compromised: ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
			return false;
		}
	}//end stampAndFlag()

	/**
	 * The Secret a warning about $secret should point at: for a shared copy,
	 * the SOURCE Secret, which its owner can open and has to rotate; otherwise
	 * $secret itself.
	 *
	 * Not being a shared copy, or a source that is gone, is expected and falls
	 * back to $secret quietly. Any other lookup failure falls back too, but is
	 * logged and sets $failed: it means the source owner is not warned, which a
	 * caller counting the containment's failures has to count (keepiq#1189).
	 *
	 * @param Secret    $secret The Secret sealed under the affected suite
	 * @param bool|null $failed Set to true when the lookup failed
	 *
	 * @return Secret
	 *
	 * @spec openspec/specs/encryption-suites/spec.md#requirement-administrator-force-revocation
	 */
	private function resolveTarget(Secret $secret, ?bool &$failed = null): Secret {
		try {
			$row = $this->shareTargetMapper->findByRecipientSecret(
				recipientSecretId: $secret->getId()
			);
			return $this->secretMapper->findById($row->getSourceSecretId());
		} catch (DoesNotExistException) {
			return $secret;
		} catch (Throwable $exception) {
			$this->logger->warning(
				'Keepiq: could not resolve the source of secret ' . $secret->getId() . ': ' . $exception->getMessage(),
				['app' => 'keepiq']
			);
			$failed = true;
			return $secret;
		}
	}//end resolveTarget()
}//end trait
