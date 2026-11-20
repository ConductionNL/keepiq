<?php

/**
 * Unit tests for BrokerMintRecorder and the broker mint path of SecretService.
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
use InvalidArgumentException;
use OCA\Keepiq\Db\BrokerMint;
use OCA\Keepiq\Db\BrokerMintMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\BrokerMintRecorder;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
use OCA\Keepiq\Service\ViewOnlyCopyGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A broker mint carries the certificate's notAfter into expires_at, and who it
 * was minted for into the mint record, without touching the ciphertext.
 *
 * @spec openspec/specs/certificate-lifecycle/spec.md#requirement-a-certificate-minted-for-a-connection-carries-its-expiry
 */
#[CoversClass(BrokerMintRecorder::class)]
#[UsesClass(SecretService::class)]
#[UsesClass(BrokerMint::class)]
#[UsesClass(Secret::class)]
#[UsesClass(EncryptionSuite::class)]
class BrokerMintRecorderTest extends TestCase {

	/**
	 * The mint records.
	 *
	 * @var BrokerMintMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $mints;

	/**
	 * The recorder under test.
	 *
	 * @var BrokerMintRecorder
	 */
	private BrokerMintRecorder $recorder;

	/**
	 * Build the recorder over a mocked mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mints = $this->createMock(BrokerMintMapper::class);
		$this->recorder = new BrokerMintRecorder(mints: $this->mints);
	}//end setUp()

	/**
	 * A secret with an id and a ciphertext.
	 *
	 * @return Secret
	 */
	private static function secret(): Secret {
		$secret = new Secret();
		$secret->setId('9a1b2c3d-0000-4000-8000-000000000001');
		$secret->setKey('CIPHER');
		$secret->setOwnerType('application');
		$secret->setOwnerId('app-or');
		return $secret;
	}//end secret()

	/**
	 * notAfter becomes expires_at in UTC, and the ciphertext stays as it was.
	 *
	 * @return void
	 */
	public function testNotAfterBecomesExpiresAtInUtc(): void {
		$secret = self::secret();
		$this->recorder->applyExpiry(secret: $secret, data: ['notAfter' => '2026-11-30T23:59:59+01:00']);

		$this->assertSame('2026-11-30T22:59:59+00:00', $secret->getExpiresAt()?->format('c'));
		$this->assertSame('CIPHER', $secret->getKey());
	}//end testNotAfterBecomesExpiresAtInUtc()

	/**
	 * A write without notAfter leaves the expiry alone; a sent null clears it.
	 *
	 * @return void
	 */
	public function testAnAbsentNotAfterKeepsTheExpiryAndANullClearsIt(): void {
		$secret = self::secret();
		$secret->setExpiresAt(new DateTime('2027-01-01T00:00:00+00:00'));

		$this->recorder->applyExpiry(secret: $secret, data: ['name' => 'x']);
		$this->assertSame('2027-01-01T00:00:00+00:00', $secret->getExpiresAt()?->format('c'));

		$this->recorder->applyExpiry(secret: $secret, data: ['notAfter' => null]);
		$this->assertNull($secret->getExpiresAt());
	}//end testAnAbsentNotAfterKeepsTheExpiryAndANullClearsIt()

	/**
	 * A notAfter that is not a date is refused.
	 *
	 * @return void
	 */
	public function testANotAfterThatIsNotADateIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('notAfter must be an ISO 8601 date');
		$this->recorder->applyExpiry(secret: self::secret(), data: ['notAfter' => 'next spring']);
	}//end testANotAfterThatIsNotADateIsRefused()

	/**
	 * The organisation and consumer replace the secret's earlier mint record.
	 *
	 * @return void
	 */
	public function testTheOrganisationAndConsumerAreRecorded(): void {
		$this->mints->expects($this->once())->method('deleteBySecret')->with('9a1b2c3d-0000-4000-8000-000000000001');
		$stored = null;
		$this->mints->expects($this->once())->method('insert')->willReturnCallback(
			static function (BrokerMint $mint) use (&$stored): BrokerMint {
				$stored = $mint;
				return $mint;
			}
		);

		$this->recorder->record(secret: self::secret(), data: ['organisation' => ' zuiddrecht ', 'consumer' => 'integriq', 'key' => 'CIPHER']);

		$this->assertInstanceOf(BrokerMint::class, $stored);
		$this->assertSame('9a1b2c3d-0000-4000-8000-000000000001', $stored->getSecretId());
		$this->assertSame('zuiddrecht', $stored->getOrganisationId());
		$this->assertSame('integriq', $stored->getConsumer());
		$this->assertNotSame('', $stored->getId());
	}//end testTheOrganisationAndConsumerAreRecorded()

	/**
	 * A write naming neither keeps whatever was recorded.
	 *
	 * @return void
	 */
	public function testAWriteNamingNeitherRecordsNothing(): void {
		$this->mints->expects($this->never())->method('deleteBySecret');
		$this->mints->expects($this->never())->method('insert');

		$this->recorder->record(secret: self::secret(), data: ['key' => 'CIPHER']);
	}//end testAWriteNamingNeitherRecordsNothing()

	/**
	 * Through SecretService: an application create with notAfter stores the
	 * expiry on the inserted row and records the mint after it.
	 *
	 * @return void
	 */
	public function testCreateByApplicationStoresTheExpiryAndTheMint(): void {
		$mapper = $this->createMock(SecretMapper::class);
		$types = $this->createMock(SecretTypeService::class);
		$types->method('resolveTypeForSecret')->willReturn('certificate');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suite = new EncryptionSuite();
		$suite->setId('suite-1');
		$suites->method('findActiveByOwner')->willReturn($suite);

		$inserted = null;
		$mapper->expects($this->once())->method('insert')->willReturnCallback(
			static function (Secret $secret) use (&$inserted): Secret {
				$inserted = $secret;
				return $secret;
			}
		);
		$this->mints->expects($this->once())->method('insert');

		$service = new SecretService(
			mapper: $mapper,
			typeService: $types,
			suiteMapper: $suites,
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			db: $this->createMock(\OCP\IDBConnection::class),
			viewOnlyCopies: $this->createStub(ViewOnlyCopyGuard::class),
			brokerMints: $this->recorder,
		);

		$service->createByApplication(
			data: ['name' => 'brp-cert', 'key' => 'CIPHER', 'notAfter' => '2026-11-30T00:00:00Z', 'organisation' => 'zuiddrecht', 'consumer' => 'integriq'],
			applicationId: 'app-or'
		);

		$this->assertInstanceOf(Secret::class, $inserted);
		$this->assertSame('2026-11-30T00:00:00+00:00', $inserted->getExpiresAt()?->format('c'));
		$this->assertSame('CIPHER', $inserted->getKey());
	}//end testCreateByApplicationStoresTheExpiryAndTheMint()
}//end class
