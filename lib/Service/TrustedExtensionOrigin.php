<?php

/**
 * Keepiq Trusted Extension Origin ceremony step
 *
 * Takes the place of the WebAuthn library's origin step in extension
 * ceremonies. An extension page reports its own origin
 * (`chrome-extension://…` or `moz-extension://…`), whose host is the
 * extension id, so the library's check that the origin host is the relying
 * party cannot apply. This step accepts the client data origin only when it
 * equals, as an exact string, one of the trusted extension origins; every
 * other step stays the library's.
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

use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CeremonyStep\CeremonyStep;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialSource;

/**
 * The origin step of an extension ceremony.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
 */
final class TrustedExtensionOrigin implements CeremonyStep {
	/**
	 * Constructor for TrustedExtensionOrigin.
	 *
	 * @param TrustedExtensionOrigins $origins The trusted origins
	 *
	 * @return void
	 */
	public function __construct(
		private TrustedExtensionOrigins $origins,
	) {
	}//end __construct()

	/**
	 * Refuse a client data origin that is not trusted.
	 *
	 * @param PublicKeyCredentialSource $publicKeyCredentialSource The credential
	 * @param AuthenticatorAssertionResponse|AuthenticatorAttestationResponse $authenticatorResponse The response
	 * @param PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $publicKeyCredentialOptions The options
	 * @param string|null $userHandle The user handle
	 * @param string $host The relying party host
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) Signature fixed by the library's CeremonyStep.
	 * @SuppressWarnings(PHPMD.LongVariable) Parameter names fixed by the library's CeremonyStep.
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
	 */
	public function process(
		PublicKeyCredentialSource $publicKeyCredentialSource,
		AuthenticatorAssertionResponse|AuthenticatorAttestationResponse $authenticatorResponse,
		PublicKeyCredentialRequestOptions|PublicKeyCredentialCreationOptions $publicKeyCredentialOptions,
		?string $userHandle,
		string $host,
	): void {
		$this->origins->assertTrusted(origin: $authenticatorResponse->clientDataJSON->origin);
	}//end process()
}//end class
