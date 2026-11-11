<?php

/**
 * Golden outcomes of a certificate metadata submission.
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
use OCA\Keepiq\Db\CertificateMetadata;
use OCA\Keepiq\Db\CertificateMetadataMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretType;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Service\CertificateMetadataService;
use OCA\Keepiq\Service\SecretService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Who may submit, what is refused with which message, and what an allowed
 * submission stores, in the order it reads and writes.
 *
 * @covers \OCA\Keepiq\Service\CertificateMetadataService
 * @uses   \OCA\Keepiq\Db\CertificateMetadata
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretType
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 */
final class CertificateMetadataSubmitGoldenTest extends TestCase {
	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The refused submissions: owner type, owner, seeded type, secret type,
	 * fields, the expected message and the calls made before the refusal.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function refusals(): array {
		$owner = 'Only the owner may submit certificate metadata';
		$type = 'Secret is not a certificate-type secret';
		return [
			'application-owned'  => ['application', 'alice', 'type-cert', 'type-cert', [], $owner, [['findById', 's1']]],
			'another owner'      => ['user', 'mallory', 'type-cert', 'type-cert', [], $owner, [['findById', 's1']]],
			'type not seeded'    => ['user', 'alice', null, 'type-cert', [], $type, [['findById', 's1'], ['findByName', 'certificate']]],
			'not a certificate'  => ['user', 'alice', 'type-cert', 'type-note', [], $type, [['findById', 's1'], ['findByName', 'certificate']]],
			'bad notBefore'      => ['user', 'alice', 'type-cert', 'type-cert', ['notBefore' => 'soon'], 'Unparseable certificate date: soon', [['findById', 's1'], ['findByName', 'certificate']]],
			'bad notAfter'       => ['user', 'alice', 'type-cert', 'type-cert', ['notAfter' => 'later'], 'Unparseable certificate date: later', [['findById', 's1'], ['findByName', 'certificate']]],
		];
	}//end refusals()

	/**
	 * A refused submission throws its message and writes nothing.
	 *
	 * @param string      $ownerType The secret's owner type
	 * @param string      $ownerId   The secret's owner
	 * @param string|null $certType  The seeded certificate type id, or null
	 * @param string      $typeId    The secret's type id
	 * @param array       $fields    The submitted fields
	 * @param string      $message   The expected message
	 * @param array       $calls     The expected calls
	 *
	 * @dataProvider refusals
	 *
	 * @return void
	 */
	public function testRefuses(string $ownerType, string $ownerId, ?string $certType, string $typeId, array $fields, string $message, array $calls): void {
		$service = $this->service(ownerType: $ownerType, ownerId: $ownerId, certType: $certType, typeId: $typeId, existing: false);

		try {
			$service->submitMetadata(secretId: 's1', userId: 'alice', fields: $fields);
			self::fail('expected a refusal');
		} catch (InvalidArgumentException $e) {
			self::assertSame($message, $e->getMessage());
		}

		self::assertSame($calls, $this->calls);
	}//end testRefuses()

	/**
	 * The owner's first submission inserts a row and mirrors notAfter.
	 *
	 * @return void
	 */
	public function testTheOwnerInsertsAndMirrorsTheExpiry(): void {
		$service = $this->service(ownerType: 'user', ownerId: 'alice', certType: 'type-cert', typeId: 'type-cert', existing: false);

		$row = $service->submitMetadata(
			secretId: 's1',
			userId: 'alice',
			fields: [
				'subject' => '  CN=example  ', 'issuer' => '', 'serial' => 42, 'fingerprintSha256' => 'ab:cd',
				'notBefore' => '2026-01-01T00:00:00Z', 'notAfter' => '2027-01-01T00:00:00Z',
			]
		);

		self::assertSame(
			[
				['findById', 's1'], ['findByName', 'certificate'], ['findBySecretId', 's1'],
				['insert', 's1', 'alice', 'CN=example', null, null, 'ab:cd', '2026-01-01', '2027-01-01', true, true],
				['setExpiry', 's1', '2027-01-01', 'alice'],
			],
			$this->calls
		);
		self::assertInstanceOf(CertificateMetadata::class, $row);
	}//end testTheOwnerInsertsAndMirrorsTheExpiry()

	/**
	 * A later submission updates the stored row; without notAfter nothing is mirrored.
	 *
	 * @return void
	 */
	public function testTheOwnerUpdatesAndMirrorsNothingWithoutNotAfter(): void {
		$service = $this->service(ownerType: 'user', ownerId: 'alice', certType: 'type-cert', typeId: 'type-cert', existing: true);

		$service->submitMetadata(secretId: 's1', userId: 'alice', fields: ['subject' => 'CN=new', 'notAfter' => '']);

		self::assertSame(
			[
				['findById', 's1'], ['findByName', 'certificate'], ['findBySecretId', 's1'],
				['update', 's1', 'alice', 'CN=new', null, null, null, null, null, true, false],
			],
			$this->calls
		);
	}//end testTheOwnerUpdatesAndMirrorsNothingWithoutNotAfter()

	/**
	 * The service over recording mappers.
	 *
	 * @param string      $ownerType The secret's owner type
	 * @param string      $ownerId   The secret's owner
	 * @param string|null $certType  The seeded certificate type id, or null
	 * @param string      $typeId    The secret's type id
	 * @param bool        $existing  Whether a metadata row is stored already
	 *
	 * @return CertificateMetadataService
	 */
	private function service(string $ownerType, string $ownerId, ?string $certType, string $typeId, bool $existing): CertificateMetadataService {
		$secret = new Secret();
		$secret->setId('s1');
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$secret->setTypeId($typeId);
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(function (string $id) use ($secret): Secret {
			$this->calls[] = ['findById', $id];
			return $secret;
		});
		$types = $this->createMock(SecretTypeMapper::class);
		$types->method('findByName')->willReturnCallback(function (string $name) use ($certType): SecretType {
			$this->calls[] = ['findByName', $name];
			if ($certType === null) {
				throw new DoesNotExistException('none');
			}

			$type = new SecretType();
			$type->setId($certType);
			return $type;
		});

		return new CertificateMetadataService($this->metadata(existing: $existing), $secrets, $types, $this->secretService());
	}//end service()

	/**
	 * A recording metadata mapper.
	 *
	 * @param bool $existing Whether a row is stored already
	 *
	 * @return CertificateMetadataMapper
	 */
	private function metadata(bool $existing): CertificateMetadataMapper {
		$mapper = $this->createMock(CertificateMetadataMapper::class);
		$mapper->method('findBySecretId')->willReturnCallback(function (string $secretId) use ($existing): CertificateMetadata {
			$this->calls[] = ['findBySecretId', $secretId];
			if ($existing === false) {
				throw new DoesNotExistException('none');
			}

			$row = new CertificateMetadata();
			$row->setId('meta-1');
			$row->setSecretId($secretId);
			return $row;
		});
		foreach (['insert', 'update'] as $method) {
			$mapper->method($method)->willReturnCallback(function (CertificateMetadata $row) use ($method, $existing): CertificateMetadata {
				$this->calls[] = [
					$method, $row->getSecretId(), $row->getOwnerId(), $row->getSubject(), $row->getIssuer(), $row->getSerial(),
					$row->getFingerprintSha256(), $row->getNotBefore()?->format('Y-m-d'), $row->getNotAfter()?->format('Y-m-d'),
					$row->getParsedAt() instanceof DateTime, $existing === false && preg_match('/^[0-9a-f-]{36}$/', (string)$row->getId()) === 1,
				];
				return $row;
			});
		}

		return $mapper;
	}//end metadata()

	/**
	 * A secret service recording setExpiry.
	 *
	 * @return SecretService
	 */
	private function secretService(): SecretService {
		$service = $this->createMock(SecretService::class);
		$service->method('setExpiry')->willReturnCallback(function (string $id, DateTime $expiresAt, string $userId): Secret {
			$this->calls[] = ['setExpiry', $id, $expiresAt->format('Y-m-d'), $userId];
			return new Secret();
		});
		return $service;
	}//end secretService()
}//end class
