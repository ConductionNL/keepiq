<?php

/**
 * JwtAssertionVerifier: an application's client assertion, read, held to
 * its claims and checked against the application's key.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\KeyManagement\JWKFactory;
use Jose\Component\Signature\Algorithm\ES256;
use Jose\Component\Signature\Algorithm\RS256;
use Jose\Component\Signature\JWSBuilder;
use Jose\Component\Signature\Serializer\CompactSerializer;
use OCA\Keepiq\Service\JwtAssertionVerifier;
use OCA\Keepiq\Service\JwtAuthService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The client assertion verifier.
 */
class JwtAssertionVerifierTest extends TestCase {
	/**
	 * A compact JWS of the given payload, signed with the key.
	 *
	 * @param string $payload The payload.
	 * @param JWK $key The signing key.
	 * @param string $alg The algorithm.
	 *
	 * @return string
	 */
	private function sign(string $payload, JWK $key, string $alg='ES256'): string {
		$builder = new JWSBuilder(new AlgorithmManager([new ES256(), new RS256()]));
		$jws     = $builder->create()->withPayload($payload)->addSignature($key, ['alg' => $alg])->build();
		return (new CompactSerializer())->serialize($jws, 0);
	}//end sign()

	/**
	 * Claims that are valid now, with the given changes.
	 *
	 * @param array<string, mixed> $changes Claims to replace or add.
	 *
	 * @return string
	 */
	private function claims(array $changes=[]): string {
		$now = time();
		return (string)json_encode($changes + ['iss' => 'app-1', 'aud' => 'keepiq', 'iat' => $now, 'exp' => $now + 60, 'jti' => 'j1']);
	}//end claims()

	/**
	 * A verifier with a quiet logger.
	 *
	 * @return JwtAssertionVerifier
	 */
	private function verifier(): JwtAssertionVerifier {
		return new JwtAssertionVerifier(logger: $this->createMock(originalClassName: LoggerInterface::class));
	}//end verifier()

	/**
	 * A current, well-formed assertion yields its claims.
	 *
	 * @return void
	 */
	public function testAGoodAssertionYieldsItsClaims(): void {
		$key    = JWKFactory::createECKey('P-256');
		$claims = $this->verifier()->readAcceptableClaims(assertion: $this->sign(payload: $this->claims(changes: ['scope' => 'read']), key: $key));
		$this->assertSame(expected: ['app-1', 'keepiq', 'j1', 'read'], actual: [$claims['iss'], $claims['aud'], $claims['jti'], $claims['scope']]);
	}//end testAGoodAssertionYieldsItsClaims()

	/**
	 * Each required claim, which an assertion may not leave out.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function requiredClaims(): array {
		return ['iss' => ['iss'], 'aud' => ['aud'], 'exp' => ['exp'], 'iat' => ['iat'], 'jti' => ['jti']];
	}//end requiredClaims()

	/**
	 * An assertion without a required claim is refused and the claim is named.
	 *
	 * @param string $claim The claim left out.
	 *
	 * @return void
	 */
	#[DataProvider('requiredClaims')]
	public function testAMissingClaimIsNamed(string $claim): void {
		$payload = json_decode($this->claims(), true);
		unset($payload[$claim]);
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Missing required claim: '.$claim);
		$this->verifier()->readAcceptableClaims(assertion: $this->sign(payload: (string)json_encode($payload), key: JWKFactory::createECKey('P-256')));
	}//end testAMissingClaimIsNamed()

	/**
	 * Claims about time and the refusal each gives.
	 *
	 * @return array<string, array{0: int, 1: int, 2: string}>
	 */
	public static function timings(): array {
		return [
			'expired'             => [-120, -1, 'Assertion expired'],
			'expiring right now'  => [-60, 0, 'Assertion expired'],
			'issued in the future' => [3600, 3660, 'Assertion iat in future'],
			'living too long'     => [0, 86400 * 30, 'Assertion lifetime exceeds the maximum'],
		];
	}//end timings()

	/**
	 * An expired, future or overlong assertion is refused.
	 *
	 * @param int $iat The issue moment, relative to now.
	 * @param int $exp The expiry, relative to now.
	 * @param string $message The refusal.
	 *
	 * @return void
	 */
	#[DataProvider('timings')]
	public function testTheTimingIsChecked(int $iat, int $exp, string $message): void {
		$now = time();
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: $message);
		$this->verifier()->readAcceptableClaims(
			assertion: $this->sign(payload: $this->claims(changes: ['iat' => $now + $iat, 'exp' => $now + $exp]), key: JWKFactory::createECKey('P-256'))
		);
	}//end testTheTimingIsChecked()

	/**
	 * An issue moment within the clock skew is accepted.
	 *
	 * @return void
	 */
	public function testASmallClockSkewIsAccepted(): void {
		$now    = time();
		$skewed = $now + JwtAuthService::CLOCK_SKEW_SECONDS - 5;
		$claims = $this->verifier()->readAcceptableClaims(
			assertion: $this->sign(payload: $this->claims(changes: ['iat' => $skewed, 'exp' => $skewed + 60]), key: JWKFactory::createECKey('P-256'))
		);
		$this->assertSame(expected: $skewed, actual: $claims['iat']);
	}//end testASmallClockSkewIsAccepted()

	/**
	 * A payload that is not a JSON object is refused.
	 *
	 * @return void
	 */
	public function testAPayloadMustBeAnObject(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Assertion payload is not a JSON object');
		$this->verifier()->readAcceptableClaims(assertion: $this->sign(payload: '["iss","aud"]', key: JWKFactory::createECKey('P-256')));
	}//end testAPayloadMustBeAnObject()

	/**
	 * Something that is not a compact JWS is refused, and the failure is logged.
	 *
	 * @return void
	 */
	public function testAMalformedAssertionIsLogged(): void {
		$logger = $this->createMock(originalClassName: LoggerInterface::class);
		$logger->expects($this->once())->method('warning');
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Invalid assertion format');
		(new JwtAssertionVerifier(logger: $logger))->readAcceptableClaims(assertion: 'not.a-jws');
	}//end testAMalformedAssertionIsLogged()

	/**
	 * The signature verifies with the signer's public key, ES256 or RS256.
	 *
	 * @return void
	 */
	public function testTheSignatureVerifiesWithTheSignersKey(): void {
		$ec  = JWKFactory::createECKey('P-256');
		$rsa = JWKFactory::createRSAKey(2048);
		$this->verifier()->verifySignature(assertion: $this->sign(payload: $this->claims(), key: $ec), jwk: $ec->toPublic());
		$this->verifier()->verifySignature(assertion: $this->sign(payload: $this->claims(), key: $rsa, alg: 'RS256'), jwk: $rsa->toPublic());
		$this->addToAssertionCount(count: 2);
	}//end testTheSignatureVerifiesWithTheSignersKey()

	/**
	 * A signature by another key is refused.
	 *
	 * @return void
	 */
	public function testAnotherKeysSignatureIsRefused(): void {
		$this->expectException(exception: RuntimeException::class);
		$this->expectExceptionMessage(message: 'Assertion signature verification failed');
		$this->verifier()->verifySignature(
			assertion: $this->sign(payload: $this->claims(), key: JWKFactory::createECKey('P-256')),
			jwk: JWKFactory::createECKey('P-256')->toPublic()
		);
	}//end testAnotherKeysSignatureIsRefused()
}//end class
