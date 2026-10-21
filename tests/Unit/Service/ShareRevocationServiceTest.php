<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareRevocationService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Revocation's defence in depth (GHSA-m5p9-6pw7-pf3g): a revoke deletes the
 * share row, but only deletes the linked secret when that secret is genuinely
 * held by the share's target.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-revoke-share
 */
class ShareRevocationServiceTest extends TestCase {

	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $mapper;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secretMapper;

	/** @var ShareAuthorizationService&MockObject */
	private ShareAuthorizationService $auth;

	private ShareRevocationService $service;

	protected function setUp(): void {
		$this->mapper       = $this->createMock(ShareTargetMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->auth         = $this->createMock(ShareAuthorizationService::class);
		$this->auth->method('loadSecret')->willReturn($this->secret('src-1', 'alice'));

		$this->service = new ShareRevocationService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			db: $this->createMock(IDBConnection::class),
			logger: $this->createMock(LoggerInterface::class),
			auth: $this->auth,
		);
	}

	/**
	 * Build a user-owned secret.
	 *
	 * @param string $id      The id
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function secret(string $id, string $ownerId): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		return $secret;
	}

	/**
	 * Build a share row.
	 *
	 * @param string $copyId The linked copy
	 * @param string $target The recipient
	 *
	 * @return ShareTarget
	 */
	private function row(string $copyId, string $target): ShareTarget {
		$row = new ShareTarget();
		$row->setId('st-1');
		$row->setSourceSecretId('src-1');
		$row->setSecretId($copyId);
		$row->setTargetUserId($target);
		return $row;
	}

	/**
	 * A revoke of a genuine share deletes the recipient copy and the row.
	 *
	 * @return void
	 */
	public function testAGenuineRevokeDeletesTheCopyAndTheRow(): void {
		$entity = $this->row('copy-1', 'bob');
		$copy   = $this->secret('copy-1', 'bob');
		$this->mapper->method('findById')->willReturn($entity);
		$this->secretMapper->method('findById')->willReturn($copy);

		$this->secretMapper->expects($this->once())->method('delete')->with($copy);
		$this->mapper->expects($this->once())->method('delete')->with($entity);

		$this->service->revokeShare(shareId: 'st-1', userId: 'alice');
	}

	/**
	 * A revoke whose row points at a secret the target does NOT hold keeps the
	 * secret and still deletes the share row, so a revoke can never delete
	 * another user's secret.
	 *
	 * @return void
	 */
	public function testARevokeKeepsASecretNotHeldByTheTarget(): void {
		$entity = $this->row('victim', 'bob');
		$victim = $this->secret('victim', 'carol');
		$this->mapper->method('findById')->willReturn($entity);
		$this->secretMapper->method('findById')->willReturn($victim);

		$this->secretMapper->expects($this->never())->method('delete');
		$this->mapper->expects($this->once())->method('delete')->with($entity);

		$this->service->revokeShare(shareId: 'st-1', userId: 'alice');
	}

	/**
	 * The cascade delete of a secret's shares keeps a copy not held by its
	 * target while still severing every link row.
	 *
	 * @return void
	 */
	public function testDeleteAllForSecretKeepsACopyNotHeldByItsTarget(): void {
		$held   = $this->row('copy-held', 'bob');
		$poison = $this->row('victim', 'bob');
		$this->mapper->method('findBySourceSecret')->willReturn([$held, $poison]);

		$this->secretMapper->method('findById')->willReturnMap(
			[
				['copy-held', $this->secret('copy-held', 'bob')],
				['victim', $this->secret('victim', 'carol')],
			]
		);

		$deleted = [];
		$this->secretMapper->method('delete')->willReturnCallback(
			static function (Secret $secret) use (&$deleted): Secret {
				$deleted[] = $secret->getId();
				return $secret;
			}
		);
		$this->mapper->expects($this->once())->method('deleteBySourceSecret')->with('src-1');

		$this->service->deleteAllForSecret(sourceSecretId: 'src-1');

		$this->assertSame(['copy-held'], $deleted);
	}

	/**
	 * GHSA-m5p9-6pw7-pf3g: a revoke of a QUARANTINED row touches nothing — the
	 * secret (which may be the victim's own) and the row (the audit record) are
	 * both left intact. Removing the quarantine guard in revokeShare deletes the
	 * secret here and turns this red.
	 *
	 * @return void
	 */
	public function testARevokeOfAQuarantinedRowLeavesTheSecretAndRowIntact(): void {
		$entity = $this->row('victim', 'victim');
		$entity->setQuarantinedAt(new \DateTime());
		$this->mapper->method('findById')->willReturn($entity);

		$this->secretMapper->expects($this->never())->method('findById');
		$this->secretMapper->expects($this->never())->method('delete');
		$this->mapper->expects($this->never())->method('delete');

		$this->service->revokeShare(shareId: 'st-1', userId: 'alice');
	}

	/**
	 * GHSA-m5p9-6pw7-pf3g: the cascade delete keeps a QUARANTINED row's secret
	 * (it may be a victim's own) while still severing the link rows in bulk.
	 *
	 * @return void
	 */
	public function testDeleteAllForSecretKeepsAQuarantinedRowsSecret(): void {
		$held       = $this->row('copy-held', 'bob');
		$quarantined = $this->row('victim', 'victim');
		$quarantined->setQuarantinedAt(new \DateTime());
		$this->mapper->method('findBySourceSecret')->willReturn([$held, $quarantined]);

		$this->secretMapper->method('findById')->willReturnMap(
			[
				['copy-held', $this->secret('copy-held', 'bob')],
				['victim', $this->secret('victim', 'victim')],
			]
		);

		$deleted = [];
		$this->secretMapper->method('delete')->willReturnCallback(
			static function (Secret $secret) use (&$deleted): Secret {
				$deleted[] = $secret->getId();
				return $secret;
			}
		);
		$this->mapper->expects($this->once())->method('deleteBySourceSecret')->with('src-1');

		$this->service->deleteAllForSecret(sourceSecretId: 'src-1');

		$this->assertSame(['copy-held'], $deleted, 'the quarantined row\'s secret is never deleted');
	}

	/**
	 * A revoke whose copy is already gone still removes the row.
	 *
	 * @return void
	 */
	public function testARevokeOfAnAlreadyGoneCopyStillRemovesTheRow(): void {
		$entity = $this->row('copy-1', 'bob');
		$this->mapper->method('findById')->willReturn($entity);
		$this->secretMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$this->secretMapper->expects($this->never())->method('delete');
		$this->mapper->expects($this->once())->method('delete')->with($entity);

		$this->service->revokeShare(shareId: 'st-1', userId: 'alice');
	}
}
