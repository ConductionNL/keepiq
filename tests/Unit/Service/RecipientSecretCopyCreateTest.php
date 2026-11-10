<?php

/**
 * Recipient copy creation tests.
 *
 * Pins the copy RecipientSecretCopyFactory::create() and
 * RecipientSecretCopyService::create() insert, column by column, the type
 * each resolves (and its fallback when the owner's type is not visible to
 * the recipient), and every case that mints no copy: no source, a totp or
 * passkeys that do not mirror the source, no active recipient suite.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests the copy both create paths insert.
 */
class RecipientSecretCopyCreateTest extends TestCase {

	/**
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper&MockObject $secretMapper;

	/**
	 * @var EncryptionSuiteMapper&MockObject
	 */
	private EncryptionSuiteMapper&MockObject $suiteMapper;

	/**
	 * @var SecretTypeService&MockObject
	 */
	private SecretTypeService&MockObject $typeService;

	/**
	 * The rows inserted, in order.
	 *
	 * @var list<Secret>
	 */
	private array $inserted = [];

	/**
	 * The owner's source secret.
	 *
	 * @var Secret
	 */
	private Secret $source;

	/**
	 * Wire mappers that answer for bob (with a suite) and nobody (without).
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->source = new Secret();
		$this->source->setId('src-1');
		$this->source->setName('Mail');
		$this->source->setUrl('https://mail.test');
		$this->source->setTypeId('owner-type');
		$this->source->setFolderId('owner-folder');
		$this->source->setOwnerType('user');
		$this->source->setOwnerId('alice');
		$this->source->setTotp('OWNER-TOTP');

		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->secretMapper->method('findById')->willReturnCallback(
			fn (string $id): Secret => $id === 'src-1' ? $this->source : throw new DoesNotExistException('gone')
		);
		$this->secretMapper->method('insert')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->inserted[] = $secret;
				return $secret;
			}
		);
		$this->suiteMapper = $this->createMock(EncryptionSuiteMapper::class);
		$this->suiteMapper->method('findActiveByOwner')->willReturnCallback(
			static function (string $ownerType, string $ownerId): EncryptionSuite {
				if ($ownerId !== 'bob') {
					throw new DoesNotExistException('none');
				}

				$suite = new EncryptionSuite();
				$suite->setId('suite-bob');
				return $suite;
			}
		);
		$this->typeService = $this->createMock(SecretTypeService::class);
		$this->typeService->method('resolveTypeForSecret')->willReturnCallback(
			static function (?string $typeId, string $userId): string {
				if ($typeId === 'private-type') {
					throw new InvalidArgumentException('not visible');
				}

				return 'type-'.($typeId ?? 'default').'-'.$userId;
			}
		);
	}//end setUp()

	/**
	 * Assert the copy carries the source's name and URL, the blobs, bob as owner and one stamp.
	 *
	 * @param Secret|string|null $copy   The created copy
	 * @param string|null        $typeId The type the copy should carry; null for none set (the column keeps its default)
	 *
	 * @return void
	 */
	private function assertCopy(Secret|string|null $copy, ?string $typeId): void {
		$this->assertInstanceOf(Secret::class, $copy);
		$this->assertSame([$copy], $this->inserted);
		$this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $copy->getId());
		$this->assertNotSame('src-1', $copy->getId());
		$this->assertSame('Mail', $copy->getName());
		$this->assertSame('https://mail.test', $copy->getUrl());
		$this->assertSame($typeId ?? '', $copy->getTypeId());
		$this->assertNull($copy->getFolderId());
		$this->assertSame('KEY', $copy->getKey());
		$this->assertSame('LOGIN', $copy->getLogin());
		$this->assertNull($copy->getAdditionalFields());
		$this->assertSame('TOTP', $copy->getTotp());
		$this->assertNull($copy->getPasskeys());
		$this->assertSame('suite-bob', $copy->getEncryptionSuiteId());
		$this->assertSame('user', $copy->getOwnerType());
		$this->assertSame('bob', $copy->getOwnerId());
		$this->assertNotNull($copy->getCreatedAt());
		$this->assertSame($copy->getCreatedAt(), $copy->getUpdatedAt());
		$this->assertSame($copy->getCreatedAt(), $copy->getKeyUpdatedAt());
		$fields = array_keys($copy->getUpdatedFields());
		sort($fields);
		$expected = ['createdAt', 'encryptionSuiteId', 'id', 'key', 'keyUpdatedAt', 'login', 'name', 'ownerId', 'ownerType', 'totp', 'updatedAt', 'url'];
		if ($typeId !== null) {
			$expected[] = 'typeId';
			sort($expected);
		}

		$this->assertSame($expected, $fields);
	}//end assertCopy()

	/**
	 * The service mints the copy with the source's type resolved for the recipient.
	 *
	 * @return void
	 */
	public function testServiceMintsTheCopy(): void {
		$service = new RecipientSecretCopyService(secretMapper: $this->secretMapper, suiteMapper: $this->suiteMapper, typeService: $this->typeService);

		$copy = $service->create(sourceSecretId: 'src-1', targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: 'LOGIN', encryptedExtras: null, encryptedTotp: 'TOTP');

		$this->assertCopy(copy: $copy, typeId: 'type-owner-type-bob');
	}//end testServiceMintsTheCopy()

	/**
	 * A type the recipient cannot see falls back to the default type.
	 *
	 * @return void
	 */
	public function testAnInvisibleTypeFallsBackToTheDefault(): void {
		$this->source->setTypeId('private-type');
		$service = new RecipientSecretCopyService(secretMapper: $this->secretMapper, suiteMapper: $this->suiteMapper, typeService: $this->typeService);
		$factory = new RecipientSecretCopyFactory(secretMapper: $this->secretMapper, suiteMapper: $this->suiteMapper, typeService: $this->typeService);

		$this->assertSame('type-default-bob', $service->create(sourceSecretId: 'src-1', targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: 'LOGIN', encryptedExtras: null, encryptedTotp: 'TOTP')->getTypeId());
		$this->assertSame('type-default-bob', $factory->create(source: $this->source, targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: null, encryptedExtras: null)->getTypeId());
	}//end testAnInvisibleTypeFallsBackToTheDefault()

	/**
	 * The service mints nothing without a source, for unmirrored value blobs, or without a recipient suite.
	 *
	 * @return void
	 */
	public function testServiceRefusals(): void {
		$service = new RecipientSecretCopyService(secretMapper: $this->secretMapper, suiteMapper: $this->suiteMapper, typeService: $this->typeService);

		$this->assertSame('not_found', $service->create(sourceSecretId: 'gone', targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: null, encryptedExtras: null, encryptedTotp: 'TOTP'));
		$this->assertSame('copy_fields_mismatch', $service->create(sourceSecretId: 'src-1', targetUserId: 'nobody', encryptedKey: 'KEY', encryptedLogin: null, encryptedExtras: null));
		$this->assertSame('copy_fields_mismatch', $service->create(sourceSecretId: 'src-1', targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: null, encryptedExtras: null, encryptedTotp: 'TOTP', encryptedPasskeys: 'PK'));
		$this->assertSame('no_suite', $service->create(sourceSecretId: 'src-1', targetUserId: 'nobody', encryptedKey: 'KEY', encryptedLogin: null, encryptedExtras: null, encryptedTotp: 'TOTP'));
		$this->assertSame([], $this->inserted);
	}//end testServiceRefusals()

	/**
	 * The factory mints the same copy for a source it is handed, and nothing without a recipient suite.
	 *
	 * @return void
	 */
	public function testFactoryMintsTheCopy(): void {
		$factory = new RecipientSecretCopyFactory(secretMapper: $this->secretMapper, suiteMapper: $this->suiteMapper, typeService: $this->typeService);

		$this->assertNull($factory->create(source: $this->source, targetUserId: 'nobody', encryptedKey: 'KEY', encryptedLogin: 'LOGIN', encryptedExtras: null, encryptedTotp: 'TOTP'));
		$this->assertSame([], $this->inserted);

		$copy = $factory->create(source: $this->source, targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: 'LOGIN', encryptedExtras: null, encryptedTotp: 'TOTP');
		$this->assertCopy(copy: $copy, typeId: 'type-owner-type-bob');
	}//end testFactoryMintsTheCopy()

	/**
	 * Without a type service the factory leaves the type unset.
	 *
	 * @return void
	 */
	public function testFactoryWithoutATypeServiceSetsNoType(): void {
		$factory = new RecipientSecretCopyFactory(secretMapper: $this->secretMapper, suiteMapper: $this->suiteMapper);

		$copy = $factory->create(source: $this->source, targetUserId: 'bob', encryptedKey: 'KEY', encryptedLogin: 'LOGIN', encryptedExtras: null, encryptedTotp: 'TOTP');

		$this->assertCopy(copy: $copy, typeId: null);
	}//end testFactoryWithoutATypeServiceSetsNoType()
}//end class
