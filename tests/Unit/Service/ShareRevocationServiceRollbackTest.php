<?php

/**
 * Unit tests for ShareRevocationService's whole-secret revoke and its rollbacks.
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

use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\RecipientCopyCleanup;
use OCA\Keepiq\Service\ShareRevocationService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Revoking every share of a secret asks the scan first, deletes copies and rows
 * in one transaction, and rolls back and rethrows when a step fails.
 */
class ShareRevocationServiceRollbackTest extends TestCase {
	/** @var ShareTargetMapper&MockObject */
	private ShareTargetMapper $mapper;

	/** @var SecretMapper&MockObject */
	private SecretMapper $secrets;

	/** @var IDBConnection&MockObject */
	private IDBConnection $db;

	/** @var ShareQuarantineScanner&MockObject */
	private ShareQuarantineScanner $scan;

	/** @var list<string> */
	private array $calls = [];

	/**
	 * Fresh doubles; the transaction calls are recorded in order.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(ShareTargetMapper::class);
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->scan = $this->createMock(ShareQuarantineScanner::class);
		$this->db = $this->createMock(IDBConnection::class);
		$this->calls = [];
		foreach (['beginTransaction', 'commit', 'rollBack'] as $call) {
			$this->db->method($call)->willReturnCallback(function () use ($call): bool {
				$this->calls[] = $call;
				return true;
			});
		}
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @return ShareRevocationService
	 */
	private function service(): ShareRevocationService {
		$auth = $this->createMock(ShareAuthorizationService::class);
		$owner = new Secret();
		$owner->setId('src-1');
		$owner->setOwnerType('user');
		$owner->setOwnerId('alice');
		$auth->method('loadSecret')->willReturn($owner);

		return new ShareRevocationService(
			copyCleanup: $this->createStub(RecipientCopyCleanup::class),
			mapper: $this->mapper,
			secretMapper: $this->secrets,
			db: $this->db,
			logger: $this->createMock(LoggerInterface::class),
			auth: $auth,
			quarantineScan: $this->scan,
		);
	}//end service()

	/**
	 * A share row of src-1 to the recipient, naming the recipient's copy.
	 *
	 * @param string $id        The row id
	 * @param string $recipient The recipient, who owns copy-of-<recipient>
	 *
	 * @return array{0: ShareTarget, 1: Secret}
	 */
	private function share(string $id, string $recipient): array {
		$row = new ShareTarget();
		$row->setId($id);
		$row->setSourceSecretId('src-1');
		$row->setSecretId('copy-of-' . $recipient);
		$row->setTargetUserId($recipient);
		$copy = new Secret();
		$copy->setId('copy-of-' . $recipient);
		$copy->setOwnerType('user');
		$copy->setOwnerId($recipient);
		return [$row, $copy];
	}//end share()

	/**
	 * Every row is put to the scan first, then every held copy and the rows go in one commit.
	 *
	 * @return void
	 */
	public function testRevokeAllDeletesEveryCopyAndTheRowsInOneCommit(): void {
		[$bobRow, $bobCopy] = $this->share(id: 'st-1', recipient: 'bob');
		[$carolRow, $carolCopy] = $this->share(id: 'st-2', recipient: 'carol');
		$this->mapper->method('findBySourceSecret')->with('src-1')->willReturn([$bobRow, $carolRow]);
		$this->secrets->method('findById')->willReturnMap([['copy-of-bob', $bobCopy], ['copy-of-carol', $carolCopy]]);
		$refused = [];
		$this->scan->method('refuseUnjudged')->willReturnCallback(function (ShareTarget $row) use (&$refused): bool {
			$refused[] = $row->getId();
			return false;
		});
		$deleted = [];
		$this->secrets->method('delete')->willReturnCallback(function (Secret $copy) use (&$deleted): Secret {
			$deleted[] = $copy->getId();
			return $copy;
		});
		$this->mapper->expects($this->once())->method('deleteBySourceSecret')->with('src-1');

		$this->service()->revokeAllForSecret(sourceSecretId: 'src-1');

		$this->assertSame(['st-1', 'st-2', 'st-1', 'st-2'], $refused);
		$this->assertSame(['copy-of-bob', 'copy-of-carol'], $deleted);
		$this->assertSame(['beginTransaction', 'commit'], $this->calls);
	}//end testRevokeAllDeletesEveryCopyAndTheRowsInOneCommit()

	/**
	 * A failing row delete rolls the whole-secret revoke back and rethrows.
	 *
	 * @return void
	 */
	public function testRevokeAllRollsBackWhenTheRowDeleteFails(): void {
		$this->mapper->method('findBySourceSecret')->willReturn([]);
		$failure = new RuntimeException('deadlock');
		$this->mapper->method('deleteBySourceSecret')->willThrowException($failure);

		try {
			$this->service()->revokeAllForSecret(sourceSecretId: 'src-1');
			$this->fail('the failure was swallowed');
		} catch (RuntimeException $exception) {
			$this->assertSame($failure, $exception);
		}

		$this->assertSame(['beginTransaction', 'rollBack'], $this->calls);
	}//end testRevokeAllRollsBackWhenTheRowDeleteFails()

	/**
	 * A single revoke whose row delete fails is rolled back and rethrown.
	 *
	 * @return void
	 */
	public function testRevokeShareRollsBackWhenTheRowDeleteFails(): void {
		[$row, $copy] = $this->share(id: 'st-1', recipient: 'bob');
		$this->mapper->method('findById')->with('st-1')->willReturn($row);
		$this->secrets->method('findById')->willReturn($copy);
		$failure = new RuntimeException('deadlock');
		$this->mapper->method('delete')->willThrowException($failure);

		try {
			$this->service()->revokeShare(shareId: 'st-1', userId: 'alice');
			$this->fail('the failure was swallowed');
		} catch (RuntimeException $exception) {
			$this->assertSame($failure, $exception);
		}

		$this->assertSame(['beginTransaction', 'rollBack'], $this->calls);
	}//end testRevokeShareRollsBackWhenTheRowDeleteFails()
}//end class
