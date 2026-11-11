<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Golden outcomes of the offline snapshot: what it carries for its owner,
 * and what the two-factor policy takes out of it.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service
 * @author   Conduction B.V. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://conduction.nl
 *
 * @spec openspec/specs/offline-readonly-cache/spec.md#requirement-online-sessions-write-through-an-encrypted-local-snapshot
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FolderMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretTypeMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\OfflineManifestService;
use OCA\Keepiq\Service\TwoFactorGate;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Offline snapshot outcomes.
 */
class OfflineManifestTest extends TestCase {

	/**
	 * The suite mapper.
	 *
	 * @var EncryptionSuiteMapper&MockObject
	 */
	private EncryptionSuiteMapper $suites;

	/**
	 * The secret mapper.
	 *
	 * @var SecretMapper&MockObject
	 */
	private SecretMapper $secrets;

	/**
	 * The two-factor gate.
	 *
	 * @var TwoFactorGate&MockObject
	 */
	private TwoFactorGate $twoFactor;

	/**
	 * The service under test.
	 *
	 * @var OfflineManifestService
	 */
	private OfflineManifestService $service;

	/**
	 * The secret alice owns, a copy of s-source.
	 *
	 * @var Secret
	 */
	private Secret $copy;

	/**
	 * A secret alice owns that is no copy.
	 *
	 * @var Secret
	 */
	private Secret $own;

	/**
	 * Wire the mappers with one user's rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$suite = new EncryptionSuite();
		$suite->setOwnerType('user');
		$suite->setOwnerId('alice');
		$this->suites = $this->createMock(EncryptionSuiteMapper::class);
		$this->suites->method('findActiveByOwner')->willReturnCallback(
			static fn (string $type, string $owner): EncryptionSuite => $type === 'user' && $owner === 'alice'
				? $suite
				: throw new DoesNotExistException('no suite')
		);

		$this->copy = $this->secret(id: 's-copy');
		$this->own = $this->secret(id: 's-own');
		$source = $this->secret(id: 's-source');
		$source->setUpdatedAt(new DateTime('2026-03-04T05:06:07+00:00'));
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findByOwner')->willReturnCallback(
			fn (string $ownerType, string $ownerId, ...$rest): array => $ownerType === 'user' && $ownerId === 'alice'
				&& ($rest[6] ?? null) === SecretMapper::STATE_LIVE ? [$this->copy, $this->own] : []
		);
		$this->secrets->method('findByIds')->willReturnCallback(
			static fn (array $ids): array => $ids === ['s-source'] ? ['s-source' => $source] : []
		);

		$folders = $this->createMock(FolderMapper::class);
		$folders->method('findByOwner')->willReturnCallback(
			fn (string $type, string $owner): array => $type === 'user' && $owner === 'alice' ? [$this->serialises(data: ['id' => 'f-1'])] : []
		);
		$types = $this->createMock(SecretTypeMapper::class);
		$types->method('findAvailableForUser')->willReturnCallback(
			fn (string $userId): array => $userId === 'alice' ? [$this->serialises(data: ['id' => 'type-login'])] : []
		);
		$target = new ShareTarget();
		$target->setSecretId('s-copy');
		$target->setSourceSecretId('s-source');
		$shareTargets = $this->createMock(ShareTargetMapper::class);
		$shareTargets->method('findByTargetUser')->willReturnCallback(
			static fn (string $userId): array => $userId === 'alice' ? [$target] : []
		);
		$this->twoFactor = $this->createMock(TwoFactorGate::class);

		$this->service = new OfflineManifestService(
			suiteMapper: $this->suites,
			secretMapper: $this->secrets,
			folderMapper: $folders,
			typeMapper: $types,
			twoFactor: $this->twoFactor,
			shareTargets: $shareTargets,
		);
	}//end setUp()

	/**
	 * A secret owned by alice.
	 *
	 * @param string $id The secret id
	 *
	 * @return Secret
	 */
	private function secret(string $id): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('Secret ' . $id);
		$secret->setOwnerType('user');
		$secret->setOwnerId('alice');
		return $secret;
	}//end secret()

	/**
	 * An object that serialises to the given data.
	 *
	 * @param array<string,string> $data The serialised form
	 *
	 * @return object
	 */
	private function serialises(array $data): object {
		return new class($data) {
			/**
			 * Keep the data.
			 *
			 * @param array<string,string> $data The serialised form
			 */
			public function __construct(private array $data) {
			}

			/**
			 * The serialised form.
			 *
			 * @return array<string,string>
			 */
			public function jsonSerialize(): array {
				return $this->data;
			}
		};
	}//end serialises()

	/**
	 * The owner's snapshot carries the suite, the live secrets with their
	 * source version, the folders and the types, in this key order.
	 *
	 * @return void
	 */
	public function testTheOwnersSnapshotCarriesEverythingItNeedsOffline(): void {
		$this->twoFactor->method('blocks')->willReturn(false);

		$manifest = $this->service->buildForUser(userId: 'alice');

		$this->assertSame(['suite', 'secrets', 'folders', 'types', 'syncedAt', 'unlockBlocked'], array_keys($manifest));
		$this->assertSame('alice', $manifest['suite']['ownerId']);
		$this->assertSame(
			[
				[...$this->copy->jsonSerialize(), 'sourceUpdatedAt' => '2026-03-04T05:06:07+00:00'],
				[...$this->own->jsonSerialize(), 'sourceUpdatedAt' => null],
			],
			$manifest['secrets']
		);
		$this->assertSame([['id' => 'f-1']], $manifest['folders']);
		$this->assertSame([['id' => 'type-login']], $manifest['types']);
		$this->assertNotFalse(DateTime::createFromFormat(DateTime::ATOM, $manifest['syncedAt']));
		$this->assertNull($manifest['unlockBlocked']);
	}//end testTheOwnersSnapshotCarriesEverythingItNeedsOffline()

	/**
	 * Under the two-factor policy the suite is left out and the reason is
	 * named, so the snapshot cannot be unlocked offline either.
	 *
	 * @return void
	 */
	public function testTheTwoFactorPolicyLeavesTheSuiteOut(): void {
		$this->twoFactor->method('blocks')->willReturnCallback(static fn (string $userId): bool => $userId === 'alice');

		$manifest = $this->service->buildForUser(userId: 'alice');

		$this->assertNull($manifest['suite']);
		$this->assertSame(TwoFactorGate::CODE, $manifest['unlockBlocked']);
		$this->assertCount(2, $manifest['secrets']);
	}//end testTheTwoFactorPolicyLeavesTheSuiteOut()

	/**
	 * A user without an active suite gets no snapshot at all, before any
	 * other read.
	 *
	 * @return void
	 */
	public function testNoSuiteMeansNoSnapshot(): void {
		$this->twoFactor->expects($this->never())->method('blocks');
		$this->secrets->expects($this->never())->method('findByOwner');

		$this->expectException(DoesNotExistException::class);
		$this->service->buildForUser(userId: 'bob');
	}//end testNoSuiteMeansNoSnapshot()
}//end class
