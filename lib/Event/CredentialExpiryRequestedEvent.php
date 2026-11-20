<?php

/**
 * Keepiq CredentialExpiryRequestedEvent
 *
 * @category Event
 * @package  OCA\Keepiq\Event
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

namespace OCA\Keepiq\Event;

use OCP\EventDispatcher\Event;

/**
 * integriq's in-process question: when does the certificate of my connection
 * expire? It names the keepiq secret and the organisation integriq acts for,
 * and collects exactly two fields, `notAfter` and `renewUrl`. An event that
 * nobody answered reads as "expiry unknown". Nothing is stored anywhere.
 *
 * @spec openspec/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
 */
class CredentialExpiryRequestedEvent extends Event {
	/**
	 * The certificate's expiry (ISO 8601), once answered.
	 *
	 * @var string|null
	 */
	private ?string $notAfter = null;

	/**
	 * The secret's page in keepiq, once answered.
	 *
	 * @var string|null
	 */
	private ?string $renewUrl = null;

	/**
	 * Constructor.
	 *
	 * @param string $credentialId         The keepiq secret the connection references
	 * @param string $actingOrganisationId The organisation integriq acts for
	 */
	public function __construct(
		private string $credentialId,
		private string $actingOrganisationId,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The keepiq secret asked about.
	 *
	 * @return string
	 */
	public function getCredentialId(): string {
		return $this->credentialId;
	}//end getCredentialId()

	/**
	 * The organisation integriq acts for.
	 *
	 * @return string
	 */
	public function getActingOrganisationId(): string {
		return $this->actingOrganisationId;
	}//end getActingOrganisationId()

	/**
	 * Answer the request: the certificate's expiry and the secret's page.
	 *
	 * @param string $notAfter The expiry, ISO 8601
	 * @param string $renewUrl The secret's page in keepiq
	 *
	 * @return void
	 *
	 * @spec openspec/specs/integration-boundary/spec.md#requirement-integriq-may-learn-when-its-own-connection-certificate-expires
	 */
	public function answer(string $notAfter, string $renewUrl): void {
		$this->notAfter = $notAfter;
		$this->renewUrl = $renewUrl;
	}//end answer()

	/**
	 * Whether keepiq answered.
	 *
	 * @return bool
	 */
	public function isAnswered(): bool {
		return $this->notAfter !== null;
	}//end isAnswered()

	/**
	 * The certificate's expiry, or null when unanswered.
	 *
	 * @return string|null
	 */
	public function getNotAfter(): ?string {
		return $this->notAfter;
	}//end getNotAfter()

	/**
	 * The secret's page in keepiq, or null when unanswered.
	 *
	 * @return string|null
	 */
	public function getRenewUrl(): ?string {
		return $this->renewUrl;
	}//end getRenewUrl()
}//end class
