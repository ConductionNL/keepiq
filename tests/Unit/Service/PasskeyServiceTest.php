<?php

/**
 * Unit tests for PasskeyService (passkey-vault-login §5.1).
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Doriath\Db\EncryptionSuite;
use OCA\Doriath\Db\EncryptionSuiteMapper;
use OCA\Doriath\Db\PasskeyCredential;
use OCA\Doriath\Db\PasskeyMapper;
use OCA\Doriath\Service\PasskeyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the passkey vault-login service.
 */
class PasskeyServiceTest extends TestCase {
	private PasskeyService $service;

	private PasskeyMapper&MockObject $mapper;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	/**
	 * Build the service over mocked mappers with a suite at epoch 3.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->mapper = $this->createMock(originalClassName: PasskeyMapper::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);

		$suite = new EncryptionSuite();
		$suite->setUnlockKeyEpoch(3);
		$this->suiteMapper->method('findActiveByOwner')->willReturn($suite);

		$random = $this->createMock(originalClassName: ISecureRandom::class);
		$random->method('generate')->willReturn('0123456789012345678901234567890123456789');

		$this->service = new PasskeyService(
			mapper: $this->mapper,
			suiteMapper: $this->suiteMapper,
			secureRandom: $random,
		);
	}//end setUp()

	/**
	 * A credential owned by alice.
	 *
	 * @param string $id The credential id
	 * @param int $epoch The unlock-key epoch it targets
	 *
	 * @return PasskeyCredential
	 */
	private function makeCredential(string $id, int $epoch = 3): PasskeyCredential {
		$credential = new PasskeyCredential();
		$credential->setId($id);
		$credential->setOwnerId('alice');
		$credential->setCredentialId('cred-' . $id);
		$credential->setPrfSalt('salt');
		$credential->setWrappedUnlockKey('envelope');
		$credential->setUnlockKeyEpoch($epoch);
		$credential->setStatus('active');

		return $credential;
	}//end makeCredential()

	/**
	 * Enrollment binds the envelope to the CURRENT suite epoch and rejects
	 * a missing envelope (§5.1 / §2.5).
	 *
	 * @return void
	 */
	public function testEnrollBindsCurrentEpoch(): void {
		$this->mapper->method('findByCredentialId')->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$credential = $this->service->enroll(
			uid: 'alice',
			dto: [
				'credentialId' => 'cred-x',
				'prfSalt' => 'salt',
				'wrappedUnlockKey' => 'envelope',
				'label' => 'Touch ID',
			],
		);

		$this->assertSame(3, $credential->getUnlockKeyEpoch());
		$this->assertSame('alice', $credential->getOwnerId());
	}//end testEnrollBindsCurrentEpoch()

	/**
	 * Enrollment without a wrapped envelope is rejected (§2.5).
	 *
	 * @return void
	 */
	public function testEnrollRejectsMissingEnvelope(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->enroll(uid: 'alice', dto: ['credentialId' => 'x', 'prfSalt' => 'y']);
	}//end testEnrollRejectsMissingEnvelope()

	/**
	 * A duplicate credential id is rejected (§enrollment).
	 *
	 * @return void
	 */
	public function testEnrollRejectsDuplicate(): void {
		$this->mapper->method('findByCredentialId')->willReturn($this->makeCredential('dup'));
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(InvalidArgumentException::class);
		$this->service->enroll(
			uid: 'alice',
			dto: ['credentialId' => 'cred-dup', 'prfSalt' => 's', 'wrappedUnlockKey' => 'e'],
		);
	}//end testEnrollRejectsDuplicate()

	/**
	 * loginOptions returns only epoch-current envelopes and marks a
	 * trailing-epoch credential stale (§2.2 / §D4).
	 *
	 * @return void
	 */
	public function testLoginOptionsRefusesStaleEpoch(): void {
		$current = $this->makeCredential('cur', 3);
		$old = $this->makeCredential('old', 2);
		$this->mapper->method('findActiveByOwner')->willReturn([$current, $old]);

		// The trailing-epoch credential is marked stale (an update).
		$staled = [];
		$this->mapper->method('update')->willReturnCallback(static function (PasskeyCredential $c) use (&$staled) {
			$staled[] = [$c->getId(), $c->getStatus()];

			return $c;
		});

		$options = $this->service->loginOptions('alice');

		$this->assertCount(1, $options['credentials']);
		$this->assertSame('cur', $options['credentials'][0]['id']);
		$this->assertSame([['old', 'stale']], $staled);
		$this->assertSame(3, $options['unlockKeyEpoch']);
		$this->assertNotEmpty($options['challenge']);
	}//end testLoginOptionsRefusesStaleEpoch()

	/**
	 * Revoke enforces the owner guard — a stranger cannot delete another
	 * user's passkey (§5.1 IDOR).
	 *
	 * @return void
	 */
	public function testRevokeRejectsCrossOwner(): void {
		$this->mapper->method('findById')->willReturn($this->makeCredential('c1'));
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(InvalidArgumentException::class);
		$this->service->revoke(uid: 'mallory', id: 'c1');
	}//end testRevokeRejectsCrossOwner()

	/**
	 * The owner can revoke their own passkey (§5.1).
	 *
	 * @return void
	 */
	public function testRevokeByOwner(): void {
		$credential = $this->makeCredential('c1');
		$this->mapper->method('findById')->willReturn($credential);
		$this->mapper->expects($this->once())->method('delete')->with($credential);

		$this->service->revoke(uid: 'alice', id: 'c1');
	}//end testRevokeByOwner()

	/**
	 * A password change marks all the owner's envelopes stale; a
	 * compromise rotation deletes them all (§D4).
	 *
	 * @return void
	 */
	public function testStalenessAndRotationHooks(): void {
		$this->mapper->expects($this->once())->method('markOwnerStale')->with('alice');
		$this->service->markStaleOnPasswordChange('alice');

		$this->mapper->expects($this->once())->method('deleteByOwner')->with('alice');
		$this->service->deleteAllOnRotation('alice');
	}//end testStalenessAndRotationHooks()

	/**
	 * A missing credential surfaces as DoesNotExist, never a silent pass
	 * (§5.1).
	 *
	 * @return void
	 */
	public function testRevokeMissingThrows(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$this->expectException(DoesNotExistException::class);
		$this->service->revoke(uid: 'alice', id: 'missing');
	}//end testRevokeMissingThrows()
}//end class
