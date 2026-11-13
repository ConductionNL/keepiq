<?php

/**
 * Unit tests for MigrationRecordRef.
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

use OCA\Keepiq\Db\AttachmentGrant;
use OCA\Keepiq\Db\AttachmentGrantMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersion;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCA\Keepiq\Db\SuiteMigration;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\MigrationRecordRef;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for resolving a migration record and checking it belongs to the caller.
 *
 * @covers \OCA\Keepiq\Service\MigrationRecordRef
 * @uses   \OCA\Keepiq\Db\AttachmentGrant
 * @uses   \OCA\Keepiq\Db\Secret
 * @uses   \OCA\Keepiq\Db\SecretVersion
 * @uses   \OCA\Keepiq\Db\SuiteMigration
 * @uses   \OCA\Keepiq\Db\StringPrimaryKey
 * @uses   \OCA\Keepiq\Exception\ForbiddenException
 */
class MigrationRecordRefTest extends TestCase {
	private function ref(): MigrationRecordRef {
		$migration = new SuiteMigration();
		$migration->setOldSuiteId('suite-old');

		return new MigrationRecordRef($migration, 'alice', 'rec-1');
	}

	private function secret(string $ownerType = 'user', string $ownerId = 'alice', string $suite = 'suite-old'): Secret {
		$secret = new Secret();
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		$secret->setEncryptionSuiteId($suite);

		return $secret;
	}

	private function secrets(?Secret $secret, string $id = 'rec-1'): SecretMapper {
		$mapper = $this->createMock(SecretMapper::class);
		$call = $mapper->expects($this->once())->method('findById')->with($id);
		if ($secret === null) {
			$call->willThrowException(new DoesNotExistException('gone'));
		} else {
			$call->willReturn($secret);
		}

		return $mapper;
	}

	public function testOwnedSecretReturnsTheCallersOldSuiteSecret(): void {
		$secret = $this->secret();

		$this->assertSame($secret, $this->ref()->ownedSecret($this->secrets($secret)));
	}

	public function testOwnedSecretMapsAMissingRowToNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Secret not found');
		$this->ref()->ownedSecret($this->secrets(null));
	}

	public function testOwnedSecretRefusesAnotherOwner(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Secret does not belong to you');
		$this->ref()->ownedSecret($this->secrets($this->secret(ownerId: 'bob')));
	}

	public function testOwnedSecretRefusesAnOrganisationOwnedSecret(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Secret does not belong to you');
		$this->ref()->ownedSecret($this->secrets($this->secret(ownerType: 'organisation')));
	}

	public function testOwnedSecretRefusesAnotherSuite(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage("Secret is not bound to this migration's old suite");
		$this->ref()->ownedSecret($this->secrets($this->secret(suite: 'suite-new')));
	}

	private function version(string $suite = 'suite-old'): SecretVersion {
		$version = new SecretVersion();
		$version->setSecretId('sec-9');
		$version->setEncryptionSuiteId($suite);

		return $version;
	}

	private function versions(?SecretVersion $version): SecretVersionMapper {
		$mapper = $this->createMock(SecretVersionMapper::class);
		$call = $mapper->expects($this->once())->method('findById')->with('rec-1');
		if ($version === null) {
			$call->willThrowException(new DoesNotExistException('gone'));
		} else {
			$call->willReturn($version);
		}

		return $mapper;
	}

	public function testOwnedVersionReturnsTheVersionOfTheCallersSecret(): void {
		$version = $this->version();

		$this->assertSame(
			$version,
			$this->ref()->ownedVersion($this->versions($version), $this->secrets($this->secret(suite: 'other'), 'sec-9'))
		);
	}

	public function testOwnedVersionMapsAMissingVersionToNotFound(): void {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->expects($this->never())->method('findById');

		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Secret version not found');
		$this->ref()->ownedVersion($this->versions(null), $secrets);
	}

	public function testOwnedVersionRefusesAnotherSuiteBeforeLoadingTheSecret(): void {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->expects($this->never())->method('findById');

		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage("Version is not bound to this migration's old suite");
		$this->ref()->ownedVersion($this->versions($this->version('suite-new')), $secrets);
	}

	public function testOwnedVersionMapsAMissingSecretToNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Owning secret not found');
		$this->ref()->ownedVersion($this->versions($this->version()), $this->secrets(null, 'sec-9'));
	}

	public function testOwnedVersionRefusesAnotherOwnersSecret(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Version does not belong to you');
		$this->ref()->ownedVersion(
			$this->versions($this->version()),
			$this->secrets($this->secret(ownerId: 'bob'), 'sec-9')
		);
	}

	private function grants(?AttachmentGrant $grant): AttachmentGrantMapper {
		$mapper = $this->createMock(AttachmentGrantMapper::class);
		$call = $mapper->expects($this->once())->method('findById')->with('rec-1');
		if ($grant === null) {
			$call->willThrowException(new DoesNotExistException('gone'));
		} else {
			$call->willReturn($grant);
		}

		return $mapper;
	}

	private function grant(string $type = 'user', string $recipient = 'alice', ?string $suite = 'suite-old'): AttachmentGrant {
		$grant = new AttachmentGrant();
		$grant->setRecipientType($type);
		$grant->setRecipientId($recipient);
		$grant->setEncryptionSuiteId($suite);

		return $grant;
	}

	public function testOwnedGrantReturnsTheCallersGrant(): void {
		$grant = $this->grant();

		$this->assertSame($grant, $this->ref()->ownedGrant($this->grants($grant)));
	}

	public function testOwnedGrantMapsAMissingRowToNotFound(): void {
		$this->expectException(NotFoundException::class);
		$this->expectExceptionMessage('Attachment grant not found');
		$this->ref()->ownedGrant($this->grants(null));
	}

	public function testOwnedGrantRefusesAGroupGrant(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Attachment grant does not belong to you');
		$this->ref()->ownedGrant($this->grants($this->grant(type: 'group')));
	}

	public function testOwnedGrantRefusesAnotherRecipient(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage('Attachment grant does not belong to you');
		$this->ref()->ownedGrant($this->grants($this->grant(recipient: 'bob')));
	}

	public function testOwnedGrantRefusesAnotherSuite(): void {
		$this->expectException(ForbiddenException::class);
		$this->expectExceptionMessage("Attachment grant is not bound to this migration's old suite");
		$this->ref()->ownedGrant($this->grants($this->grant(suite: null)));
	}
}
