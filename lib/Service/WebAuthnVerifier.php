<?php

/**
 * Keepiq WebAuthn Verifier
 *
 * Server-side verification of a passkey enrolment, built on the WebAuthn
 * library Nextcloud core ships and uses for its own passkey login
 * (web-auth/webauthn-lib 4.9, lib/private/Authentication/WebAuthn). The
 * library is provided by the server at runtime; Keepiq does not bundle a
 * second copy. Attestation formats match core: `none` only.
 *
 * An enrolment carries two ceremonies: the create() attestation, which
 * proves the client data (type, challenge, origin) and the relying party
 * hash and yields the credential public key, and a get() assertion over a
 * second challenge, whose signature and counter prove the authenticator
 * holds the private key of that public key. A passkey unlock carries one
 * get() assertion, checked against the stored public key and counter.
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
use Cose\Algorithm\Signature\ECDSA\ES256;
use Cose\Algorithm\Signature\RSA\RS256;
use Cose\Algorithms;
use Symfony\Component\Uid\NilUuid;
use OCA\Keepiq\Exception\PasskeyVerificationException;
use Throwable;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * Verifies a passkey enrolment's attestation and proof assertion.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Each WebAuthn ceremony object
 * (options, entities, validators, loaders, algorithms) is its own library class.
 *
 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
 */
class WebAuthnVerifier {
	/**
	 * Relying parties allowed over plain http, as core allows.
	 *
	 * @var string[]
	 */
	private const INSECURE_RP_IDS = ['localhost'];

	/**
	 * Verify an enrolment: the create() attestation against the first
	 * challenge, then the get() assertion of the same credential against
	 * the second one.
	 *
	 * @param string $uid The enrolling user (the WebAuthn user handle)
	 * @param string $rpId The relying party id the ceremony must be bound to
	 * @param string $challenge The raw create() challenge the server issued
	 * @param string $assertionChallenge The raw get() challenge the server issued
	 * @param array<string,mixed> $attestation The create() PublicKeyCredential as JSON
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential as JSON
	 *
	 * @return VerifiedPasskey
	 *
	 * @throws PasskeyVerificationException When any check fails
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passkey-enrollment-requires-an-unlocked-vault
	 */
	public function verifyEnrolment(
		string $uid,
		string $rpId,
		string $challenge,
		string $assertionChallenge,
		array $attestation,
		array $assertion,
	): VerifiedPasskey {
		if (class_exists(AuthenticatorAttestationResponseValidator::class) === false) {
			throw new PasskeyVerificationException(message: 'The server WebAuthn library is not available');
		}

		try {
			$source = $this->verifyAttestation(uid: $uid, rpId: $rpId, challenge: $challenge, attestation: $attestation);
			$source = $this->verifyAssertion(uid: $uid, rpId: $rpId, challenge: $assertionChallenge, assertion: $assertion, source: $source);
		} catch (PasskeyVerificationException $exception) {
			throw $exception;
		} catch (Throwable $throwable) {
			throw new PasskeyVerificationException(message: $throwable->getMessage(), code: 0, previous: $throwable);
		}

		return new VerifiedPasskey(
			credentialId: $this->b64url(raw: $source->publicKeyCredentialId),
			publicKey: $this->b64url(raw: $source->credentialPublicKey),
			aaguid: $source->aaguid->toRfc4122(),
			counter: $source->counter,
		);
	}//end verifyEnrolment()

	/**
	 * Verify an unlock: the get() assertion of an enrolled credential
	 * against the issued challenge, its stored public key and counter.
	 *
	 * @param string $uid The unlocking user (the WebAuthn user handle)
	 * @param string $rpId The relying party id the ceremony must be bound to
	 * @param string $challenge The raw get() challenge the server issued
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential as JSON
	 * @param string $credentialId The enrolled base64url credential id
	 * @param string $publicKey The enrolled base64url COSE public key
	 * @param int $counter The stored signature counter
	 *
	 * @return int The signature counter the assertion carried
	 *
	 * @throws PasskeyVerificationException When any check fails
	 *
	 * @spec openspec/specs/passkey-vault-login/spec.md#requirement-passwordless-unlock-derives-the-unlock-key-client-side
	 */
	public function verifyUnlock(
		string $uid,
		string $rpId,
		string $challenge,
		array $assertion,
		string $credentialId,
		string $publicKey,
		int $counter,
	): int {
		if (class_exists(AuthenticatorAssertionResponseValidator::class) === false) {
			throw new PasskeyVerificationException(message: 'The server WebAuthn library is not available');
		}

		$rawKey = $this->fromB64url(value: $publicKey);
		if ($rawKey === '') {
			throw new PasskeyVerificationException(message: 'The passkey has no verified public key');
		}

		$source = new PublicKeyCredentialSource(
			publicKeyCredentialId: $this->fromB64url(value: $credentialId),
			type: PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
			transports: [],
			attestationType: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
			trustPath: new EmptyTrustPath(),
			aaguid: new NilUuid(),
			credentialPublicKey: $rawKey,
			userHandle: $uid,
			counter: $counter,
		);

		try {
			$source = $this->verifyAssertion(uid: $uid, rpId: $rpId, challenge: $challenge, assertion: $assertion, source: $source);
		} catch (PasskeyVerificationException $exception) {
			throw $exception;
		} catch (Throwable $throwable) {
			throw new PasskeyVerificationException(message: $throwable->getMessage(), code: 0, previous: $throwable);
		}

		return $source->counter;
	}//end verifyUnlock()

	/**
	 * Check the create() response: client data type, challenge and origin,
	 * relying party hash, user presence, algorithm and attestation format.
	 *
	 * @param string $uid The enrolling user
	 * @param string $rpId The relying party id
	 * @param string $challenge The raw create() challenge
	 * @param array<string,mixed> $attestation The create() PublicKeyCredential
	 *
	 * @return PublicKeyCredentialSource The attested credential
	 */
	private function verifyAttestation(string $uid, string $rpId, string $challenge, array $attestation): PublicKeyCredentialSource {
		$response = $this->load(json: $attestation)->response;
		if (($response instanceof AuthenticatorAttestationResponse) === false) {
			throw new PasskeyVerificationException(message: 'Not an attestation response');
		}

		self::assertClientDataType(actual: $response->clientDataJSON->type, expected: 'webauthn.create');

		$options = new PublicKeyCredentialCreationOptions(
			rp: new PublicKeyCredentialRpEntity('Keepiq', $rpId),
			user: new PublicKeyCredentialUserEntity($uid, $uid, $uid),
			challenge: $challenge,
			pubKeyCredParams: [
				new PublicKeyCredentialParameters(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Algorithms::COSE_ALGORITHM_ES256),
				new PublicKeyCredentialParameters(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Algorithms::COSE_ALGORITHM_RS256),
			],
			authenticatorSelection: new AuthenticatorSelectionCriteria(),
			attestation: PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE,
		);
		$validator = new AuthenticatorAttestationResponseValidator(
			ceremonyStepManager: $this->ceremonies()->creationCeremony()
		);

		return $validator->check($response, $options, $rpId);
	}//end verifyAttestation()

	/**
	 * Check the get() response of the just-attested credential: credential
	 * id, user handle, client data type, challenge and origin, relying party
	 * hash, user presence, signature and counter.
	 *
	 * @param string $uid The enrolling user
	 * @param string $rpId The relying party id
	 * @param string $challenge The raw get() challenge
	 * @param array<string,mixed> $assertion The get() PublicKeyCredential
	 * @param PublicKeyCredentialSource $source The attested credential
	 *
	 * @return PublicKeyCredentialSource The credential with its new counter
	 */
	private function verifyAssertion(
		string $uid,
		string $rpId,
		string $challenge,
		array $assertion,
		PublicKeyCredentialSource $source,
	): PublicKeyCredentialSource {
		$credential = $this->load(json: $assertion);
		$response = $credential->response;
		if (($response instanceof AuthenticatorAssertionResponse) === false) {
			throw new PasskeyVerificationException(message: 'Not an assertion response');
		}

		self::assertClientDataType(actual: $response->clientDataJSON->type, expected: 'webauthn.get');

		if (hash_equals($source->publicKeyCredentialId, $credential->rawId) === false) {
			throw new PasskeyVerificationException(message: 'The assertion is for another credential');
		}

		$options = new PublicKeyCredentialRequestOptions(
			challenge: $challenge,
			rpId: $rpId,
			allowCredentials: [
				new PublicKeyCredentialDescriptor(
					PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
					$source->publicKeyCredentialId
				),
			],
			userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_PREFERRED,
		);
		$validator = new AuthenticatorAssertionResponseValidator(
			ceremonyStepManager: $this->ceremonies()->requestCeremony()
		);

		return $validator->check($source, $response, $options, $rpId, $uid);
	}//end verifyAssertion()

	/**
	 * Refuse client data of the other ceremony: the library accepts both
	 * types for either ceremony.
	 *
	 * @param string $actual The client data type
	 * @param string $expected The type this ceremony must carry
	 *
	 * @return void
	 *
	 * @throws PasskeyVerificationException When the type differs
	 */
	private static function assertClientDataType(string $actual, string $expected): void {
		if ($actual !== $expected) {
			throw new PasskeyVerificationException(message: 'The client data type is not ' . $expected);
		}
	}//end assertClientDataType()

	/**
	 * Parse a PublicKeyCredential JSON object.
	 *
	 * @param array<string,mixed> $json The credential as the client serialised it
	 *
	 * @return PublicKeyCredential
	 */
	private function load(array $json): PublicKeyCredential {
		$loader = new PublicKeyCredentialLoader(new AttestationObjectLoader($this->attestationSupport()));

		return $loader->loadArray($json);
	}//end load()

	/**
	 * The ceremony step factory: core's attestation formats and algorithms.
	 *
	 * @return CeremonyStepManagerFactory
	 */
	private function ceremonies(): CeremonyStepManagerFactory {
		$factory = new CeremonyStepManagerFactory();
		$factory->setSecuredRelyingPartyId(self::INSECURE_RP_IDS);
		$factory->setAttestationStatementSupportManager($this->attestationSupport());
		$factory->setAlgorithmManager((new AlgorithmManager())->add(new ES256(), new RS256()));

		return $factory;
	}//end ceremonies()

	/**
	 * The accepted attestation statement formats.
	 *
	 * @return AttestationStatementSupportManager
	 */
	private function attestationSupport(): AttestationStatementSupportManager {
		return new AttestationStatementSupportManager([new NoneAttestationStatementSupport()]);
	}//end attestationSupport()

	/**
	 * Unpadded base64url, the encoding the clients use for credential ids.
	 *
	 * @param string $raw Raw bytes
	 *
	 * @return string
	 */
	private function b64url(string $raw): string {
		return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
	}//end b64url()

	/**
	 * Decode unpadded base64url; an empty string when it does not decode.
	 *
	 * @param string $value The base64url text
	 *
	 * @return string Raw bytes
	 */
	private function fromB64url(string $value): string {
		$raw = base64_decode(strtr($value, '-_', '+/'), true);
		if ($raw === false) {
			return '';
		}

		return $raw;
	}//end fromB64url()
}//end class
