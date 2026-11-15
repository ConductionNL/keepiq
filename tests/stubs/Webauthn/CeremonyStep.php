<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Static-analysis stub of the WebAuthn library's ceremony step interface
 * (web-auth/webauthn-lib 4.9), which the Nextcloud server ships in 3rdparty.
 * PHPStan cannot ignore an unknown implemented interface, so
 * TrustedExtensionOrigin needs this declaration. Never autoloaded.
 */

declare(strict_types=1);

namespace Webauthn\CeremonyStep;

interface CeremonyStep {
	/**
	 * Run the step; throw to refuse the ceremony.
	 *
	 * @return void
	 */
	public function process(
		\Webauthn\PublicKeyCredentialSource $publicKeyCredentialSource,
		\Webauthn\AuthenticatorAssertionResponse|\Webauthn\AuthenticatorAttestationResponse $authenticatorResponse,
		\Webauthn\PublicKeyCredentialRequestOptions|\Webauthn\PublicKeyCredentialCreationOptions $publicKeyCredentialOptions,
		?string $userHandle,
		string $host,
	): void;
}
