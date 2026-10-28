<?php

/**
 * Keepiq Partner Upgrade Required Exception
 *
 * A federation partner runs a Keepiq that cannot store the `totp` or `passkeys` value fields,
 * so the share or save that would carry them is refused instead of sending a copy without them (keepiq#1325).
 * It carries the code `partner_upgrade_required` and the version the partner needs.
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

use OCA\Keepiq\AppInfo\Application;
use RuntimeException;

/**
 * Thrown when a partner instance is too old to store a secret's value fields.
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */
class PartnerUpgradeRequiredException extends RuntimeException {
	/**
	 * The machine-readable code the refusal carries.
	 *
	 * @var string
	 */
	public const CODE = 'partner_upgrade_required';

	/**
	 * Constructor.
	 *
	 * @param string $requiredVersion The Keepiq version the partner needs
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only.
	 */
	public function __construct(
		private readonly string $requiredVersion = Application::TOTP_PASSKEYS_FIELDS_SINCE,
	) {
		parent::__construct(
			message: sprintf(
				'The receiving organisation must upgrade Keepiq to %s or later to receive one-time codes and passkeys',
				$requiredVersion
			)
		);
	}//end __construct()

	/**
	 * The Keepiq version the partner needs.
	 *
	 * @return string
	 *
	 * @spec exclude Accessor only.
	 */
	public function getRequiredVersion(): string {
		return $this->requiredVersion;
	}//end getRequiredVersion()
}//end class
