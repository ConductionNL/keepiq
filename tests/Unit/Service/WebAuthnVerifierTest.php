<?php

/**
 * Unit tests for WebAuthnVerifier against real ceremony data.
 *
 * The verifier runs on the WebAuthn library Nextcloud core ships, so these
 * tests need the server's 3rdparty autoloader; every CI PHPUnit leg boots a
 * Nextcloud server and has it.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Exception\PasskeyCounterException;
use OCA\Keepiq\Exception\PasskeyVerificationException;
use OCA\Keepiq\Service\VerifiedPasskey;
use OCA\Keepiq\Service\ExtensionCeremonySteps;
use OCA\Keepiq\Service\TrustedExtensionOrigins;
use OCA\Keepiq\Service\WebAuthnVerifier;
use OCA\Keepiq\Tests\Support\WebAuthnFixture;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Webauthn\AuthenticatorAttestationResponseValidator;

/**
 * Every refusal of an enrolment ceremony, and the one accepted shape.
 */
class WebAuthnVerifierTest extends TestCase {
	private const UID = 'alice';

	private const EXTENSION_ORIGIN = 'chrome-extension://abcdefghijklmnopabcdefghijklmnop';

	private WebAuthnVerifier $verifier;

	private WebAuthnFixture $passkey;

	private string $challenge;

	private string $assertionChallenge;

	/**
	 * A verifier, a software passkey and a challenge pair.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		if (class_exists(AuthenticatorAttestationResponseValidator::class) === false) {
			$this->markTestSkipped('Needs the WebAuthn library from the Nextcloud server (3rdparty)');
		}

		$this->verifier = new WebAuthnVerifier(extensionSteps: new ExtensionCeremonySteps(origins: new TrustedExtensionOrigins(appConfig: $this->createMock(originalClassName: IAppConfig::class))));
		$this->passkey = new WebAuthnFixture();
		$this->challenge = random_bytes(32);
		$this->assertionChallenge = random_bytes(32);
	}//end setUp()

	/**
	 * Verify with the given ceremony overrides.
	 *
	 * @param array<string,mixed> $attestation Attestation overrides
	 * @param array<string,mixed> $assertion Assertion overrides
	 * @param string $rpId The relying party the server expects
	 *
	 * @return VerifiedPasskey
	 */
	private function verify(array $attestation = [], array $assertion = [], string $rpId = WebAuthnFixture::RP_ID): VerifiedPasskey {
		return $this->verifier->verifyEnrolment(
			uid: self::UID,
			rpId: $rpId,
			challenge: $this->challenge,
			assertionChallenge: $this->assertionChallenge,
			attestation: $this->passkey->attestation($this->challenge, $attestation),
			assertion: $this->passkey->assertion($assertion['challenge'] ?? $this->assertionChallenge, self::UID, $assertion),
		);
	}//end verify()

	/**
	 * A genuine ceremony verifies and yields the attested credential data.
	 *
	 * @return void
	 */
	public function testAcceptsAGenuineCeremony(): void {
		$verified = $this->verify(attestation: ['counter' => 1], assertion: ['counter' => 2]);

		$this->assertSame($this->passkey->credentialIdB64(), $verified->credentialId);
		$this->assertSame(WebAuthnFixture::AAGUID, $verified->aaguid);
		$this->assertSame(2, $verified->counter);
		$this->assertNotSame('', $verified->publicKey);
	}//end testAcceptsAGenuineCeremony()

	/**
	 * Each broken field of the create() attestation is refused.
	 *
	 * @return array<string,array{0: array<string,mixed>, 1: string}>
	 */
	public static function brokenAttestations(): array {
		return [
			'client data type is get' => [['type' => 'webauthn.get'], 'The client data type is not webauthn.create'],
			'challenge is not the issued one' => [['clientChallenge' => random_bytes(32)], 'Invalid challenge.'],
			'origin is another site' => [['origin' => 'https://cloud.example.com.evil.test'], 'rpId mismatch.'],
			'origin is plain http' => [['origin' => 'http://cloud.example.com'], 'Invalid scheme. HTTPS required.'],
			'rpId hash is another relying party' => [['rpId' => 'evil.test'], 'rpId hash mismatch.'],
			'attestation format is not accepted' => [['fmt' => 'fido-u2f'], 'The attestation statement format "fido-u2f" is not supported.'],
			'user was not present' => [['flags' => 0x40], 'User was not present'],
		];
	}//end brokenAttestations()

	/**
	 * A broken attestation is refused.
	 *
	 * @param array<string,mixed> $override The broken field
	 * @param string $reason The check that must refuse it
	 *
	 * @return void
	 */
	#[DataProvider('brokenAttestations')]
	public function testRefusesABrokenAttestation(array $override, string $reason): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage($reason);
		$this->verify(attestation: $override);
	}//end testRefusesABrokenAttestation()

	/**
	 * Each broken field of the proof get() assertion is refused.
	 *
	 * @return array<string,array{0: array<string,mixed>, 1: string}>
	 */
	public static function brokenAssertions(): array {
		return [
			'client data type is create' => [['type' => 'webauthn.create'], 'The client data type is not webauthn.get'],
			'challenge is the create() one' => [['challenge' => 'create'], 'Invalid challenge.'],
			'challenge is not the issued one' => [['challenge' => random_bytes(32)], 'Invalid challenge.'],
			'origin is another site' => [['origin' => 'https://evil.test'], 'rpId mismatch.'],
			'rpId hash is another relying party' => [['rpId' => 'evil.test'], 'rpId hash mismatch.'],
			'signed by another key' => [['signer' => WebAuthnFixture::newKey()], 'Invalid signature.'],
			'counter went backwards' => [['counter' => 3], 'Invalid counter.'],
			'another credential answered' => [['rawId' => random_bytes(16)], 'The assertion is for another credential'],
		];
	}//end brokenAssertions()

	/**
	 * A broken assertion is refused.
	 *
	 * @param array<string,mixed> $override The broken field
	 * @param string $reason The check that must refuse it
	 *
	 * @return void
	 */
	#[DataProvider('brokenAssertions')]
	public function testRefusesABrokenAssertion(array $override, string $reason): void {
		if (($override['challenge'] ?? null) === 'create') {
			$override['challenge'] = $this->challenge;
		}

		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage($reason);
		$this->verify(attestation: ['counter' => 5], assertion: $override + ['counter' => 6]);
	}//end testRefusesABrokenAssertion()

	/**
	 * A ceremony for another relying party than the server's is refused,
	 * even when it is internally consistent.
	 *
	 * @return void
	 */
	public function testRefusesACeremonyForAnotherRelyingParty(): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage('rpId mismatch.');
		$this->verify(rpId: 'other.example.com');
	}//end testRefusesACeremonyForAnotherRelyingParty()

	/**
	 * Malformed input is refused as a verification failure, not a crash.
	 *
	 * @return void
	 */
	public function testRefusesMalformedInput(): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->verifier->verifyEnrolment(
			uid: self::UID,
			rpId: WebAuthnFixture::RP_ID,
			challenge: $this->challenge,
			assertionChallenge: $this->assertionChallenge,
			attestation: ['id' => 'x'],
			assertion: [],
		);
	}//end testRefusesMalformedInput()

	/**
	 * A verifier whose extension ceremonies read the given settings.
	 *
	 * @param bool $enabled The extension passkey switch
	 * @param string $origins The stored admin origins
	 *
	 * @return WebAuthnVerifier
	 */
	private function extensionVerifier(bool $enabled = true, string $origins = '["' . self::EXTENSION_ORIGIN . '"]'): WebAuthnVerifier {
		$appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$appConfig->method('getValueBool')->willReturn($enabled);
		$appConfig->method('getValueString')->willReturn($origins);

		return new WebAuthnVerifier(extensionSteps: new ExtensionCeremonySteps(origins: new TrustedExtensionOrigins(appConfig: $appConfig)));
	}//end extensionVerifier()

	/**
	 * An extension enrolment from the given origin and flags.
	 *
	 * @param WebAuthnVerifier $verifier The verifier
	 * @param array<string,mixed> $override Overrides for both ceremonies
	 *
	 * @return VerifiedPasskey
	 */
	private function verifyExtension(WebAuthnVerifier $verifier, array $override = []): VerifiedPasskey {
		$override += ['origin' => self::EXTENSION_ORIGIN];

		return $verifier->verifyEnrolment(
			uid: self::UID,
			rpId: WebAuthnFixture::RP_ID,
			challenge: $this->challenge,
			assertionChallenge: $this->assertionChallenge,
			attestation: $this->passkey->attestation($this->challenge, $override),
			assertion: $this->passkey->assertion($this->assertionChallenge, self::UID, $override),
			extension: true,
		);
	}//end verifyExtension()

	/**
	 * An extension ceremony from a trusted origin, bound to the server host,
	 * verifies.
	 *
	 * @return void
	 */
	public function testAcceptsAnExtensionCeremonyFromATrustedOrigin(): void {
		$verified = $this->verifyExtension(verifier: $this->extensionVerifier());

		$this->assertSame($this->passkey->credentialIdB64(), $verified->credentialId);
	}//end testAcceptsAnExtensionCeremonyFromATrustedOrigin()

	/**
	 * Each refusal of an extension ceremony.
	 *
	 * @return array<string,array{0: bool, 1: string, 2: array<string,mixed>, 3: string}>
	 */
	public static function refusedExtensionCeremonies(): array {
		$trusted = '["' . self::EXTENSION_ORIGIN . '"]';
		return [
			'origin is another extension' => [true, $trusted, ['origin' => 'chrome-extension://ponmlkjihgfedcbaponmlkjihgfedcba'], 'The extension origin is not trusted'],
			'origin is the web origin' => [true, $trusted, ['origin' => WebAuthnFixture::ORIGIN], 'The extension origin is not trusted'],
			'origin has a trailing slash' => [true, $trusted, ['origin' => self::EXTENSION_ORIGIN . '/'], 'The extension origin is not trusted'],
			'extension passkeys are off' => [false, $trusted, [], 'Extension passkeys are switched off'],
			'the origin setting is broken' => [true, '{not json', [], 'The trusted extension origins setting is unreadable'],
			'the origin setting holds a wildcard' => [true, '["chrome-extension://*"]', [], 'The trusted extension origins setting is unreadable'],
			'the user was not verified' => [true, $trusted, ['flags' => 0x41], 'User authentication required.'],
			'rpId hash is another relying party' => [true, $trusted, ['rpId' => 'evil.test'], 'rpId hash mismatch.'],
		];
	}//end refusedExtensionCeremonies()

	/**
	 * A refused extension ceremony.
	 *
	 * @param bool $enabled The extension passkey switch
	 * @param string $origins The stored admin origins
	 * @param array<string,mixed> $override The broken field
	 * @param string $reason The check that must refuse it
	 *
	 * @return void
	 */
	#[DataProvider('refusedExtensionCeremonies')]
	public function testRefusesAnExtensionCeremony(bool $enabled, string $origins, array $override, string $reason): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage($reason);
		$this->verifyExtension(verifier: $this->extensionVerifier(enabled: $enabled, origins: $origins), override: $override);
	}//end testRefusesAnExtensionCeremony()

	/**
	 * A web ceremony never accepts an extension origin, even a trusted one.
	 *
	 * @return void
	 */
	public function testAWebCeremonyRefusesATrustedExtensionOrigin(): void {
		$this->expectException(PasskeyVerificationException::class);
		$this->expectExceptionMessage('Invalid scheme. HTTPS required.');
		$this->extensionVerifier()->verifyEnrolment(
			uid: self::UID,
			rpId: WebAuthnFixture::RP_ID,
			challenge: $this->challenge,
			assertionChallenge: $this->assertionChallenge,
			attestation: $this->passkey->attestation($this->challenge, ['origin' => self::EXTENSION_ORIGIN]),
			assertion: $this->passkey->assertion($this->assertionChallenge, self::UID, ['origin' => self::EXTENSION_ORIGIN]),
		);
	}//end testAWebCeremonyRefusesATrustedExtensionOrigin()

	/**
	 * A counter that did not increase is reported apart from other refusals.
	 *
	 * @return void
	 */
	public function testACounterThatDidNotIncreaseIsToldApart(): void {
		$verified = $this->verify(attestation: ['counter' => 1], assertion: ['counter' => 2]);
		$challenge = random_bytes(32);

		$this->expectException(PasskeyCounterException::class);
		$this->verifier->verifyUnlock(
			uid: self::UID,
			rpId: WebAuthnFixture::RP_ID,
			challenge: $challenge,
			assertion: $this->passkey->assertion($challenge, self::UID, ['counter' => 2]),
			credentialId: $verified->credentialId,
			publicKey: $verified->publicKey,
			counter: 2,
		);
	}//end testACounterThatDidNotIncreaseIsToldApart()
}//end class
