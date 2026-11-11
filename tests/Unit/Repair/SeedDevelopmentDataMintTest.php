<?php

/**
 * Characterisation of the development suite mint.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Repair
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

namespace OCA\Keepiq\Tests\Unit\Repair;

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Repair\DevSuiteIntegrityGuard;
use OCA\Keepiq\Repair\SeedDevelopmentData;
use OCA\Keepiq\Service\CertificateAuthorityService;
use OCA\Keepiq\Service\EncryptService;
use OCP\IConfig;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * What mintSuite signs, encrypts, stores and reports, in order.
 *
 * @covers \OCA\Keepiq\Repair\SeedDevelopmentData
 */
final class SeedDevelopmentDataMintTest extends TestCase {
	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * A minted suite is signed for the user, its key encrypted with the
	 * password, stored active and reported.
	 *
	 * @return void
	 */
	public function testMintsSignsEncryptsAndStoresASuite(): void {
		$suite = $this->seed(caFails: false)->mintSuite(userId: 'bob', masterPassword: 'pw', output: $this->recordingOutput());

		self::assertInstanceOf(EncryptionSuite::class, $suite);
		self::assertSame(['sign', 'bob'], array_slice($this->calls[0], 0, 2));
		self::assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $this->calls[0][2]);
		self::assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $this->calls[0][3]);
		self::assertSame(['encrypt', $this->calls[0][3], 'pw'], $this->calls[1], 'the signed key pair\'s private key is encrypted');
		self::assertSame(['insert', 'user', 'bob', 'CERT', 'ENC', 'active', true], array_slice($this->calls[2], 0, 7));
		self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $this->calls[2][7]);
		self::assertSame(
			[
				['info', 'Dev EncryptionSuite created for user: bob'],
				['log', 'Keepiq dev seed: EncryptionSuite created for user: bob'],
			],
			array_slice($this->calls, 3)
		);
		self::assertCount(5, $this->calls);
		self::assertSame($this->calls[2][7], $suite->getId());
	}//end testMintsSignsEncryptsAndStoresASuite()

	/**
	 * Without a CA nothing is encrypted or stored, and the reason is reported.
	 *
	 * @return void
	 */
	public function testStoresNothingWithoutACa(): void {
		$suite = $this->seed(caFails: true)->mintSuite(userId: 'bob', masterPassword: 'pw', output: $this->recordingOutput());

		self::assertNull($suite);
		self::assertSame('sign', $this->calls[0][0]);
		self::assertSame([['warning', 'CA not available for dev seed: no ca']], array_slice($this->calls, 1));
	}//end testStoresNothingWithoutACa()

	/**
	 * The step over recording collaborators.
	 *
	 * @param bool $caFails Whether signing throws
	 *
	 * @return SeedDevelopmentData
	 */
	private function seed(bool $caFails): SeedDevelopmentData {
		$ca = $this->createMock(CertificateAuthorityService::class);
		$ca->method('signPublicKey')->willReturnCallback(
			function (string $publicKeyPem, string $commonName, string $privateKeyPem) use ($caFails): string {
				$this->calls[] = ['sign', $commonName, $publicKeyPem, $privateKeyPem];
				if ($caFails === true) {
					throw new RuntimeException('no ca');
				}

				return 'CERT';
			}
		);
		$encrypt = $this->createMock(EncryptService::class);
		$encrypt->method('encryptPrivateKey')->willReturnCallback(function (string $pem, string $password): string {
			$this->calls[] = ['encrypt', $pem, $password];
			return 'ENC';
		});
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('insert')->willReturnCallback(function (EncryptionSuite $suite): EncryptionSuite {
			$this->calls[] = [
				'insert', $suite->getOwnerType(), $suite->getOwnerId(), $suite->getCertificate(),
				$suite->getPrivateKey(), $suite->getStatus(), $suite->getCreatedAt() !== null, $suite->getId(),
			];
			return $suite;
		});
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(function (string $message): void {
			$this->calls[] = ['log', $message];
		});

		return new SeedDevelopmentData(
			$suites,
			$ca,
			$encrypt,
			$this->createMock(DevSuiteIntegrityGuard::class),
			$this->createMock(IConfig::class),
			$logger
		);
	}//end seed()

	/**
	 * A recording output.
	 *
	 * @return IOutput
	 */
	private function recordingOutput(): IOutput {
		$output = $this->createMock(IOutput::class);
		foreach (['info', 'warning'] as $level) {
			$output->method($level)->willReturnCallback(function (string $message) use ($level): void {
				$this->calls[] = [$level, $message];
			});
		}

		return $output;
	}//end recordingOutput()
}//end class
