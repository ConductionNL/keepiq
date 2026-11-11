<?php

/**
 * Characterisation of the private CA status read.
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

use DateTime;
use OCA\Keepiq\Db\CACertificate;
use OCA\Keepiq\Db\CACertificateMapper;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Service\CertificateAuthorityStatusService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\TestCase;

/**
 * Every status the CA can report, and the counts that come with it.
 *
 * @covers \OCA\Keepiq\Service\CertificateAuthorityStatusService
 */
final class CertificateAuthorityStatusServiceTest extends TestCase {
	/**
	 * The status for each expiry and revocation combination.
	 *
	 * @return array<string,array{0:?string,1:?string,2:bool,3:string}>
	 */
	public static function health(): array {
		return [
			'both far off'              => ['+60 days', '+200 days', false, 'healthy'],
			'no expiries recorded'      => [null, null, false, 'healthy'],
			'intermediate expiring'     => ['+10 days', '+200 days', false, 'expiring_soon'],
			'root expiring'             => ['+60 days', '+60 days', false, 'action_required'],
			'both expiring'             => ['+10 days', '+60 days', false, 'action_required'],
			'intermediate revoked'      => ['+60 days', '+200 days', true, 'action_required'],
			'revoked and expiring'      => ['+10 days', '+200 days', true, 'action_required'],
		];
	}//end health()

	/**
	 * A configured CA reports its health, both certificates and the counts.
	 *
	 * @param string|null $intermediateExpiry The intermediate's expiry, relative
	 * @param string|null $rootExpiry         The root's expiry, relative
	 * @param bool        $revoked            Whether the intermediate is revoked
	 * @param string      $status             The expected status
	 *
	 * @dataProvider health
	 *
	 * @return void
	 */
	public function testReportsTheHealthOfAConfiguredCa(?string $intermediateExpiry, ?string $rootExpiry, bool $revoked, string $status): void {
		$root = $this->certificate(expiry: $rootExpiry, revoked: false);
		$intermediate = $this->certificate(expiry: $intermediateExpiry, revoked: $revoked);
		$service = $this->service(caStatus: 'healthy', root: $root, intermediate: $intermediate, typed: true);

		self::assertSame(
			[
				'status'       => $status,
				'root'         => $root->jsonSerialize(),
				'intermediate' => $intermediate->jsonSerialize(),
				'issued'       => ['activeUserSuites' => 3, 'activeApplicationSuites' => 2, 'storedCertificates' => 9, 'storedExpiringSoon' => 4],
			],
			$service->getStatus()
		);
	}//end testReportsTheHealthOfAConfiguredCa()

	/**
	 * A degraded CA, or one without a root or an intermediate, is not configured.
	 *
	 * @return void
	 */
	public function testReportsNotConfigured(): void {
		$cert = $this->certificate(expiry: '+60 days', revoked: false);
		$cases = [
			'degraded'        => $this->service(caStatus: 'degraded', root: $cert, intermediate: $cert, typed: true),
			'no root'         => $this->service(caStatus: 'healthy', root: null, intermediate: $cert, typed: true),
			'no intermediate' => $this->service(caStatus: 'healthy', root: $cert, intermediate: null, typed: true),
		];
		foreach ($cases as $case => $service) {
			self::assertSame(['status' => 'not_configured', 'root' => null, 'intermediate' => null], $service->getStatus(), $case);
		}
	}//end testReportsNotConfigured()

	/**
	 * Without the secret mappers, or before the certificate type is seeded,
	 * only the suite counts are filled in.
	 *
	 * @return void
	 */
	public function testStoredCountsStayZeroWithoutTheCertificateType(): void {
		$cert = $this->certificate(expiry: '+60 days', revoked: false);
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('countActiveByOwnerType')->willReturnMap([['user', 3], ['application', 2]]);
		$mappers = $this->mappers(root: $cert, intermediate: $cert);
		$unwired = new CertificateAuthorityStatusService($mappers, $suites, $this->appConfig('healthy'));
		$unseeded = $this->service(caStatus: 'healthy', root: $cert, intermediate: $cert, typed: false);

		$expected = ['activeUserSuites' => 3, 'activeApplicationSuites' => 2, 'storedCertificates' => 0, 'storedExpiringSoon' => 0];
		self::assertSame($expected, $unwired->getStatus()['issued']);
		self::assertSame($expected, $unseeded->getStatus()['issued']);
	}//end testStoredCountsStayZeroWithoutTheCertificateType()

	/**
	 * The service over stubbed mappers.
	 *
	 * @param string             $caStatus     The stored ca_status
	 * @param CACertificate|null $root         The root, or null when missing
	 * @param CACertificate|null $intermediate The active intermediate, or null when missing
	 * @param bool               $typed        Whether the certificate type is seeded
	 *
	 * @return CertificateAuthorityStatusService
	 */
	private function service(string $caStatus, ?CACertificate $root, ?CACertificate $intermediate, bool $typed): CertificateAuthorityStatusService {
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('countActiveByOwnerType')->willReturnMap([['user', 3], ['application', 2]]);
		$types = $this->createMock(SecretTypeMapper::class);
		if ($typed === true) {
			$type = new SecretType();
			$type->setId('type-cert');
			$types->method('findByName')->with('certificate')->willReturn($type);
		} else {
			$types->method('findByName')->willThrowException(new DoesNotExistException('none'));
		}

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('countByTypeId')->willReturnCallback(
			static fn (string $typeId, ?DateTime $expiresBefore = null): int => $typeId === 'type-cert' ? ($expiresBefore === null ? 9 : 4) : -1
		);

		return new CertificateAuthorityStatusService($this->mappers($root, $intermediate), $suites, $this->appConfig($caStatus), $secrets, $types);
	}//end service()

	/**
	 * A CA certificate mapper answering the root and the active intermediate.
	 *
	 * @param CACertificate|null $root         The root, or null when missing
	 * @param CACertificate|null $intermediate The intermediate, or null when missing
	 *
	 * @return CACertificateMapper
	 */
	private function mappers(?CACertificate $root, ?CACertificate $intermediate): CACertificateMapper {
		$mapper = $this->createMock(CACertificateMapper::class);
		$root === null
			? $mapper->method('findRoot')->willThrowException(new DoesNotExistException('none'))
			: $mapper->method('findRoot')->willReturn($root);
		$intermediate === null
			? $mapper->method('findActiveIntermediate')->willThrowException(new DoesNotExistException('none'))
			: $mapper->method('findActiveIntermediate')->willReturn($intermediate);
		return $mapper;
	}//end mappers()

	/**
	 * An app config holding one ca_status.
	 *
	 * @param string $caStatus The stored ca_status
	 *
	 * @return IAppConfig
	 */
	private function appConfig(string $caStatus): IAppConfig {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->with('keepiq', 'ca_status', 'unknown')->willReturn($caStatus);
		return $config;
	}//end appConfig()

	/**
	 * A CA certificate.
	 *
	 * @param string|null $expiry  Its expiry relative to now, or null
	 * @param bool        $revoked Whether it is revoked
	 *
	 * @return CACertificate
	 */
	private function certificate(?string $expiry, bool $revoked): CACertificate {
		$cert = new CACertificate();
		$cert->setId('ca-' . bin2hex(random_bytes(4)));
		if ($expiry !== null) {
			$cert->setExpiresAt(new DateTime($expiry));
		}

		if ($revoked === true) {
			$cert->setRevokedAt(new DateTime('-1 day'));
		}

		return $cert;
	}//end certificate()
}//end class
