<?php

/**
 * Keepiq Extension Ceremony Steps
 *
 * The WebAuthn step lists of a browser-extension ceremony: the library's
 * own steps, in the library's order, with the origin step replaced by
 * TrustedExtensionOrigin. Web ceremonies keep the library factory's lists.
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

use Cose\Algorithm\Manager as AlgorithmManager;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticationExtensions\ExtensionOutputCheckerHandler;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CheckAlgorithm;
use Webauthn\CeremonyStep\CheckAllowedCredentialList;
use Webauthn\CeremonyStep\CheckAttestationFormatIsKnownAndValid;
use Webauthn\CeremonyStep\CheckBackupBitsAreConsistent;
use Webauthn\CeremonyStep\CheckChallenge;
use Webauthn\CeremonyStep\CheckClientDataCollectorType;
use Webauthn\CeremonyStep\CheckCounter;
use Webauthn\CeremonyStep\CheckCredentialId;
use Webauthn\CeremonyStep\CheckExtensions;
use Webauthn\CeremonyStep\CheckHasAttestedCredentialData;
use Webauthn\CeremonyStep\CheckMetadataStatement;
use Webauthn\CeremonyStep\CheckRelyingPartyIdIdHash;
use Webauthn\CeremonyStep\CheckSignature;
use Webauthn\CeremonyStep\CheckTopOrigin;
use Webauthn\CeremonyStep\CheckUserHandle;
use Webauthn\CeremonyStep\CheckUserVerification;
use Webauthn\CeremonyStep\CheckUserWasPresent;
use Webauthn\Counter\ThrowExceptionIfInvalid;

/**
 * Builds the step lists of extension ceremonies.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) One library class per ceremony step.
 *
 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
 */
class ExtensionCeremonySteps {
	/**
	 * Constructor for ExtensionCeremonySteps.
	 *
	 * @param TrustedExtensionOrigins $origins The trusted extension origins
	 *
	 * @return void
	 */
	public function __construct(
		private TrustedExtensionOrigins $origins,
	) {
	}//end __construct()

	/**
	 * The create() steps of an extension enrolment.
	 *
	 * @param AttestationStatementSupportManager $attestation The accepted attestation formats
	 *
	 * @return CeremonyStepManager
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
	 */
	public function creation(AttestationStatementSupportManager $attestation): CeremonyStepManager {
		return new CeremonyStepManager([
			new CheckClientDataCollectorType(),
			new CheckChallenge(),
			new TrustedExtensionOrigin(origins: $this->origins),
			new CheckTopOrigin(null),
			new CheckRelyingPartyIdIdHash(),
			new CheckUserWasPresent(),
			new CheckUserVerification(),
			new CheckBackupBitsAreConsistent(),
			new CheckAlgorithm(),
			new CheckExtensions(new ExtensionOutputCheckerHandler()),
			new CheckAttestationFormatIsKnownAndValid($attestation),
			new CheckHasAttestedCredentialData(),
			new CheckMetadataStatement(),
			new CheckCredentialId(),
		]);
	}//end creation()

	/**
	 * The get() steps of an extension proof or unlock assertion.
	 *
	 * @param AlgorithmManager $algorithms The accepted signature algorithms
	 *
	 * @return CeremonyStepManager
	 *
	 * @spec openspec/changes/extension-passkey-enrolment/specs/extension-passkey-trust/spec.md#requirement-extension-ceremonies-pass-only-for-trusted-extension-origins
	 */
	public function request(AlgorithmManager $algorithms): CeremonyStepManager {
		return new CeremonyStepManager([
			new CheckAllowedCredentialList(),
			new CheckUserHandle(),
			new CheckClientDataCollectorType(),
			new CheckChallenge(),
			new TrustedExtensionOrigin(origins: $this->origins),
			new CheckTopOrigin(null),
			new CheckRelyingPartyIdIdHash(),
			new CheckUserWasPresent(),
			new CheckUserVerification(),
			new CheckBackupBitsAreConsistent(),
			new CheckExtensions(new ExtensionOutputCheckerHandler()),
			new CheckSignature($algorithms),
			new CheckCounter(new ThrowExceptionIfInvalid()),
		]);
	}//end request()
}//end class
