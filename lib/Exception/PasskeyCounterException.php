<?php

/**
 * Keepiq Passkey Counter Exception
 *
 * Thrown when a passkey assertion's signature counter did not increase
 * although the authenticator keeps one.
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

/**
 * A signature counter that did not increase.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-biometric-unlock/spec.md#requirement-unlock-the-extension-with-the-enrolled-passkey
 */
class PasskeyCounterException extends PasskeyVerificationException {
}//end class
