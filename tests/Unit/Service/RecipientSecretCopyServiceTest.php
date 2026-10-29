<?php

/**
 * Unit tests for RecipientSecretCopyService::deleteByShare — the defence-in-depth
 * guard that keeps a team-folder revoke from deleting a secret the share's target
 * does not genuinely hold (GHSA-m5p9-6pw7-pf3g).
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

use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Service\RecipientSecretCopyFactory;
use OCA\Keepiq\Service\RecipientSecretCopyService;
use OCA\Keepiq\Service\SecretTypeService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RecipientSecretCopyService.
 */
class RecipientSecretCopyServiceTest extends TestCase {

	/**
	 * @var SecretMapper&\PHPUnit\Framework\MockObject\MockObject
	 */
	private SecretMapper $secretMapper;

	/**
	 * The service under test.
	 *
	 * @var RecipientSecretCopyService
	 */
	private RecipientSecretCopyService $service;

	/**
	 * Set up fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->service = new RecipientSecretCopyService(
			secretMapper: $this->secretMapper,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			typeService: $this->createMock(SecretTypeService::class),
		);
	}//end setUp()

	/**
	 * Build a share-target row.
	 *
	 * @param string $secretId     The linked copy id
	 * @param string $targetUserId The recipient
	 *
	 * @return ShareTarget
	 */
	private function share(string $secretId, string $targetUserId): ShareTarget {
		$share = new ShareTarget();
		$share->setId('st-1');
		$share->setSourceSecretId('src-1');
		$share->setSecretId($secretId);
		$share->setTargetUserId($targetUserId);
		return $share;
	}//end share()

	/**
	 * Build a secret with an owner.
	 *
	 * @param string $id        The secret id
	 * @param string $ownerType The owner type
	 * @param string $ownerId   The owner id
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerType, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType($ownerType);
		$secret->setOwnerId($ownerId);
		return $secret;
	}//end secret()

	/**
	 * A copy genuinely held by its target is deleted on revoke.
	 *
	 * @return void
	 */
	public function testDeletesACopyHeldByItsTarget(): void {
		$copy = $this->secret('copy-1', 'user', 'bob');
		$this->secretMapper->method('findById')->willReturn($copy);
		$this->secretMapper->expects($this->once())->method('delete')->with($copy);

		$this->service->deleteByShare($this->share('copy-1', 'bob'));
	}//end testDeletesACopyHeldByItsTarget()

	/**
	 * A copy NOT held by its target (a poisoned or drifted secret_id owned by
	 * someone else) is left untouched: the guard keeps a team-folder revoke from
	 * deleting another user's secret. Removing the guard line deletes it and
	 * turns this test red.
	 *
	 * @return void
	 */
	public function testKeepsACopyNotHeldByItsTarget(): void {
		// The link's target is bob, but the secret it names is owned by victim.
		$copy = $this->secret('copy-1', 'user', 'victim');
		$this->secretMapper->method('findById')->willReturn($copy);
		$this->secretMapper->expects($this->never())->method('delete');

		$this->service->deleteByShare($this->share('copy-1', 'bob'));
	}//end testKeepsACopyNotHeldByItsTarget()

	/**
	 * Every value field the browser encrypted for the recipient lands on the
	 * copy; the TOTP seed and passkeys may be left out.
	 *
	 * @return void
	 */
	public function testCreateSetsEveryValueFieldOnTheCopy(): void {
		$service = new RecipientSecretCopyService(
			secretMapper: $this->secretMapper,
			suiteMapper: $this->activeSuiteMapper(),
			typeService: $this->createMock(SecretTypeService::class),
		);
		$withFields = $this->secret('src-1', 'user', 'alice');
		$withFields->setTotp('SOURCE-T');
		$withFields->setPasskeys('SOURCE-P');
		$plain = $this->secret('src-2', 'user', 'alice');
		$this->secretMapper->method('findById')->willReturnCallback(
			static fn (string $id): Secret => $id === 'src-1' ? $withFields : $plain
		);
		$this->secretMapper->method('insert')->willReturnArgument(0);

		$copy = $service->create('src-1', 'bob', 'K', 'L', 'F', 'T', 'P');
		$legacy = $service->create('src-2', 'bob', 'K', null, null);

		$this->assertInstanceOf(Secret::class, $copy);
		$this->assertInstanceOf(Secret::class, $legacy);
		$this->assertSame(['K', 'L', 'F', 'T', 'P'], [$copy->getKey(), $copy->getLogin(), $copy->getAdditionalFields(), $copy->getTotp(), $copy->getPasskeys()]);
		$this->assertSame([null, null], [$legacy->getTotp(), $legacy->getPasskeys()]);

		// A row without the source's fields, or with ones it does not hold, mints nothing and says why.
		$this->assertSame('copy_fields_mismatch', $service->create('src-1', 'bob', 'K', null, null));
		$this->assertSame('copy_fields_mismatch', $service->create('src-2', 'bob', 'K', null, null, 'PLANTED'));
	}//end testCreateSetsEveryValueFieldOnTheCopy()

	/**
	 * The copy factory sets the same fields from the source it is handed.
	 *
	 * @return void
	 */
	public function testTheCopyFactorySetsEveryValueFieldOnTheCopy(): void {
		$factory = new RecipientSecretCopyFactory(secretMapper: $this->secretMapper, suiteMapper: $this->activeSuiteMapper());
		$this->secretMapper->method('insert')->willReturnArgument(0);

		$copy = $factory->create(
			source: $this->secret('src-1', 'user', 'alice'),
			targetUserId: 'bob',
			encryptedKey: 'K',
			encryptedLogin: 'L',
			encryptedExtras: 'F',
			encryptedTotp: 'T',
			encryptedPasskeys: 'P',
		);

		$this->assertSame(['K', 'L', 'F', 'T', 'P'], [$copy?->getKey(), $copy?->getLogin(), $copy?->getAdditionalFields(), $copy?->getTotp(), $copy?->getPasskeys()]);
	}//end testTheCopyFactorySetsEveryValueFieldOnTheCopy()

	/**
	 * A suite mapper that finds an active suite for anyone.
	 *
	 * @return EncryptionSuiteMapper
	 */
	private function activeSuiteMapper(): EncryptionSuiteMapper {
		$suite = new EncryptionSuite();
		$suite->setId('suite-bob');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturn($suite);

		return $suites;
	}//end activeSuiteMapper()
}//end class
