<?php

/**
 * Keepiq Passkey Verification Exception
 *
 * Thrown when a passkey ceremony response fails server-side WebAuthn
 * verification. The message names the failed check for the log; callers
 * show the user a generic refusal.
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

use RuntimeException;

/**
 * Thrown when a passkey ceremony response does not verify.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 */
class PasskeyVerificationException extends RuntimeException {
}//end class
