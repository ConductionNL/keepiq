<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Exception\ConflictException;
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

	/** @var QuarantinedShareTargetMapper&MockObject */
	private QuarantinedShareTargetMapper $quarantined;

	protected function setUp(): void {
		$this->mapper       = $this->createMock(ShareTargetMapper::class);
		$this->quarantined  = $this->createMock(QuarantinedShareTargetMapper::class);
		$this->secretMapper = $this->createMock(SecretMapper::class);
		$this->auth         = $this->createMock(ShareAuthorizationService::class);
		$this->auth->method('loadSecret')->willReturn($this->secret('src-1', 'alice'));

		$this->service = new ShareRevocationService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			db: $this->createMock(IDBConnection::class),
			logger: $this->createMock(LoggerInterface::class),
			auth: $this->auth,
			quarantined: $this->quarantined,
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
	 * GHSA-m5p9-6pw7-pf3g: the mapper hides a quarantined row, so its owner's
	 * revoke finds no live share. The owner is told the share is quarantined,
	 * an explicit error rather than a silent success or "not found", and
	 * nothing is deleted.
	 *
	 * @return void
	 */
	public function testRevokingAQuarantinedRowIsAnExplicitError(): void {
		$entity = $this->row('victim', 'victim');
		$entity->setQuarantinedAt(new \DateTime());
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('quarantined'));
		$this->quarantined->method('findQuarantinedById')->with('st-1')->willReturn($entity);
		$this->auth->method('isOwnerOrDelegate')->willReturn(true);

		$this->secretMapper->expects($this->never())->method('delete');
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(ConflictException::class);
		$this->expectExceptionMessage('quarantined');
		$this->service->revokeShare(shareId: 'st-1', userId: 'alice');
	}

	/**
	 * To anyone but the owner or a delegate of its source, a quarantined row
	 * reads as "not found", like any unknown id.
	 *
	 * @return void
	 */
	public function testAQuarantinedRowIsNotFoundForAnyoneElse(): void {
		$entity = $this->row('victim', 'victim');
		$entity->setQuarantinedAt(new \DateTime());
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('quarantined'));
		$this->quarantined->method('findQuarantinedById')->willReturn($entity);
		$this->auth->method('isOwnerOrDelegate')->willReturn(false);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Share not found');
		$this->service->revokeShare(shareId: 'st-1', userId: 'mallory');
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

	/**
	 * An id that is neither a live nor a quarantined row is "not found".
	 *
	 * @return void
	 */
	public function testAnUnknownIdIsNotFound(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('none'));
		$this->quarantined->method('findQuarantinedById')->willThrowException(new DoesNotExistException('none'));

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Share not found');
		$this->service->revokeShare(shareId: 'st-unknown', userId: 'alice');
	}

	/**
	 * A quarantined row whose source is gone is "not found": no owner or
	 * delegate is left to tell.
	 *
	 * @return void
	 */
	public function testAQuarantinedRowWhoseSourceIsGoneIsNotFound(): void {
		$auth = $this->createMock(ShareAuthorizationService::class);
		$auth->method('loadSecret')->willThrowException(new \InvalidArgumentException('Secret not found'));
		$auth->expects($this->never())->method('isOwnerOrDelegate');
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('quarantined'));
		$this->quarantined->method('findQuarantinedById')->willReturn($this->row('victim', 'victim'));
		$service = new ShareRevocationService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			db: $this->createMock(IDBConnection::class),
			logger: $this->createMock(LoggerInterface::class),
			auth: $auth,
			quarantined: $this->quarantined,
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Share not found');
		$service->revokeShare(shareId: 'st-1', userId: 'alice');
	}

	/**
	 * Without the quarantine mapper, an unknown id is "not found" too.
	 *
	 * @return void
	 */
	public function testWithoutTheQuarantineMapperAnUnknownIdIsNotFound(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('none'));
		$service = new ShareRevocationService(
			mapper: $this->mapper,
			secretMapper: $this->secretMapper,
			db: $this->createMock(IDBConnection::class),
			logger: $this->createMock(LoggerInterface::class),
			auth: $this->auth,
		);

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('Share not found');
		$service->revokeShare(shareId: 'st-unknown', userId: 'alice');
	}
}
