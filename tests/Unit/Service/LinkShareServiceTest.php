<?php

/**
 * Unit tests for LinkShareService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
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

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretDelegation;
use OCA\Keepiq\Db\SecretDelegationMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\ShareAuthorizationService;
use OCA\Keepiq\Service\WriteLockService;
use OCP\AppFramework\Db\DoesNotExistException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Tests for LinkShareService.
 */
class LinkShareServiceTest extends TestCase {

	/**
	 * The service under test.
	 *
	 * @var LinkShareService
	 */
	private LinkShareService $service;

	/**
	 * The mocked mapper.
	 *
	 * @var LinkShareMapper
	 */
	private LinkShareMapper $mapper;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(originalClassName: LinkShareMapper::class);
		$logger = $this->createMock(originalClassName: LoggerInterface::class);

		// The REAL authorization service over mocked mappers (keepiq#214):
		// every secret is alice's own unless a test says otherwise.
		$this->secrets = $this->createMock(SecretMapper::class);
		$this->secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($this->secretRows[$id] ?? $this->secret(id: $id, ownerId: 'alice'))
		);
		$this->delegations = $this->createMock(SecretDelegationMapper::class);
		$this->delegations->method('findActiveBySecretAndUser')->willReturnCallback(
			function (string $secretId, string $userId): SecretDelegation {
				if (in_array($secretId . '/' . $userId, $this->delegates, true) === true) {
					return new SecretDelegation();
				}

				throw new DoesNotExistException('none');
			}
		);
		$this->shareTargets = $this->createMock(ShareTargetMapper::class);
		$this->shareTargets->method('findByRecipientSecret')->willReturnCallback(
			function (string $copyId): ShareTarget {
				if (isset($this->copies[$copyId]) === false) {
					throw new DoesNotExistException('not a copy');
				}

				$row = new ShareTarget();
				$row->setSourceSecretId($this->copies[$copyId]);
				return $row;
			}
		);

		$this->service = new LinkShareService(
			mapper: $this->mapper,
			logger: $logger,
			writeLockService: $this->createMock(WriteLockService::class),
			shareAuth: new ShareAuthorizationService(
				secretMapper: $this->secrets,
				delegationMapper: $this->delegations,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				shareTargetMapper: $this->shareTargets,
			),
		);
	}//end setUp()

	/**
	 * Secret rows by id that override the alice-owned default.
	 *
	 * @var array<string,Secret>
	 */
	private array $secretRows = [];

	/**
	 * Active delegations as "secretId/userId".
	 *
	 * @var string[]
	 */
	private array $delegates = [];

	/**
	 * Received copies: copy id => source secret id.
	 *
	 * @var array<string,string>
	 */
	private array $copies = [];

	/**
	 * The mocked secret mapper.
	 *
	 * @var SecretMapper
	 */
	private SecretMapper $secrets;

	/**
	 * The mocked delegation mapper.
	 *
	 * @var SecretDelegationMapper
	 */
	private SecretDelegationMapper $delegations;

	/**
	 * The mocked share-target mapper.
	 *
	 * @var ShareTargetMapper
	 */
	private ShareTargetMapper $shareTargets;

	/**
	 * Build a user-owned secret row.
	 *
	 * @param string $id      The secret id
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
	}//end secret()

	/**
	 * Create a link share as $userId for $secretId with valid fields.
	 *
	 * @param string $secretId The secret id
	 * @param string $userId   The acting user
	 *
	 * @return LinkShare
	 */
	private function createAs(string $secretId, string $userId): LinkShare {
		return $this->service->create(
			secretId: $secretId,
			encryptedSnapshot: 'the-blob',
			salt: 'the-salt',
			encryptionSuiteId: 'suite-1',
			usageLimit: 1,
			expiresAt: null,
			userId: $userId
		);
	}//end createAs()

	/**
	 * The owner may create a link share (keepiq#214).
	 *
	 * @return void
	 */
	public function testTheOwnerMayCreateALinkShare(): void {
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('alice', $this->createAs(secretId: 'secret-1', userId: 'alice')->getCreatedBy());
	}//end testTheOwnerMayCreateALinkShare()

	/**
	 * A recipient whose share permits re-sharing (an active delegate) may
	 * create one from their own copy, which is judged by its source.
	 *
	 * @return void
	 */
	public function testADelegateMayCreateALinkShareFromTheirCopy(): void {
		$this->secretRows['copy-bob'] = $this->secret(id: 'copy-bob', ownerId: 'bob');
		$this->copies['copy-bob'] = 'secret-1';
		$this->delegates[] = 'secret-1/bob';
		$this->mapper->expects($this->once())->method('insert')->willReturnArgument(0);

		$this->assertSame('bob', $this->createAs(secretId: 'copy-bob', userId: 'bob')->getCreatedBy());
	}//end testADelegateMayCreateALinkShareFromTheirCopy()

	/**
	 * A plain recipient owns their copy row, but the copy's source is not
	 * theirs and they hold no delegation: refused, nothing written.
	 *
	 * @return void
	 */
	public function testAPlainRecipientIsRefused(): void {
		$this->secretRows['copy-carol'] = $this->secret(id: 'copy-carol', ownerId: 'carol');
		$this->copies['copy-carol'] = 'secret-1';
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->createAs(secretId: 'copy-carol', userId: 'carol');
	}//end testAPlainRecipientIsRefused()

	/**
	 * A stranger naming someone else's secret is refused, nothing written.
	 *
	 * @return void
	 */
	public function testAStrangerIsRefused(): void {
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$this->createAs(secretId: 'secret-1', userId: 'mallory');
	}//end testAStrangerIsRefused()

	/**
	 * A secret that does not exist gets the same refusal as a foreign one.
	 *
	 * @return void
	 */
	public function testAMissingSecretIsRefusedTheSameWay(): void {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willThrowException(new DoesNotExistException('gone'));
		$service = new LinkShareService(
			mapper: $this->mapper,
			logger: $this->createMock(LoggerInterface::class),
			writeLockService: $this->createMock(WriteLockService::class),
			shareAuth: new ShareAuthorizationService(
				secretMapper: $secrets,
				delegationMapper: $this->delegations,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				shareTargetMapper: $this->shareTargets,
			),
		);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(DoesNotExistException::class);
		$service->create(
			secretId: 'nope',
			encryptedSnapshot: 'the-blob',
			salt: 'the-salt',
			encryptionSuiteId: 'suite-1',
			usageLimit: 1,
			expiresAt: null,
			userId: 'alice'
		);
	}//end testAMissingSecretIsRefusedTheSameWay()

	/**
	 * Build a LinkShare entity with sensible defaults for tests.
	 *
	 * @param array<string,mixed> $overrides Field overrides
	 *
	 * @return LinkShare
	 */
	private function makeShare(array $overrides = []): LinkShare {
		$share = new LinkShare();
		$share->setId($overrides['id'] ?? 'ls-1');
		$share->setSecretId($overrides['secretId'] ?? 'secret-1');
		$share->setToken($overrides['token'] ?? 'tok-1');
		$share->setEncryptedSecretSnapshot($overrides['blob'] ?? 'blob');
		$share->setArgon2idSalt($overrides['salt'] ?? 'salt');
		$share->setEncryptionSuiteId($overrides['suiteId'] ?? 'suite-1');
		$share->setUsageLimit($overrides['usageLimit'] ?? 3);
		$share->setUsageCount($overrides['usageCount'] ?? 0);
		$share->setFailedAttempts($overrides['failedAttempts'] ?? 0);
		$share->setCreatedBy($overrides['createdBy'] ?? 'alice');
		$share->setCreatedAt(new DateTime());
		if (array_key_exists('expiresAt', $overrides) === true) {
			$share->setExpiresAt($overrides['expiresAt']);
		}

		return $share;
	}//end makeShare()

	/**
	 * Test create generates a 32-char hex token and persists the row.
	 *
	 * @return void
	 */
	public function testCreateGeneratesTokenAndPersists(): void {
		$captured = null;
		$this->mapper->expects($this->once())
			->method('insert')
			->willReturnCallback(
				function (LinkShare $share) use (&$captured) {
					$captured = $share;
					return $share;
				}
			);

		$result = $this->service->create(
			secretId: 'secret-9',
			encryptedSnapshot: 'the-blob',
			salt: 'the-salt',
			encryptionSuiteId: 'suite-42',
			usageLimit: 5,
			expiresAt: null,
			userId: 'alice'
		);

		$this->assertSame('secret-9', $result->getSecretId());
		$this->assertSame(5, $result->getUsageLimit());
		$this->assertSame(0, $result->getUsageCount());
		$this->assertSame('alice', $result->getCreatedBy());
		// 128 bits of entropy = 32 hex chars.
		$this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $result->getToken());
		$this->assertSame($captured, $result);
	}//end testCreateGeneratesTokenAndPersists()

	/**
	 * Test create rejects a usage limit below the minimum.
	 *
	 * @return void
	 */
	public function testCreateRejectsUsageLimitZero(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(InvalidArgumentException::class);

		$this->service->create(
			secretId: 'secret-1',
			encryptedSnapshot: 'blob',
			salt: 'salt',
			encryptionSuiteId: 'suite-1',
			usageLimit: 0,
			expiresAt: null,
			userId: 'alice'
		);
	}//end testCreateRejectsUsageLimitZero()

	/**
	 * Test create rejects a usage limit above the maximum.
	 *
	 * @return void
	 */
	public function testCreateRejectsUsageLimitEleven(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(InvalidArgumentException::class);

		$this->service->create(
			secretId: 'secret-1',
			encryptedSnapshot: 'blob',
			salt: 'salt',
			encryptionSuiteId: 'suite-1',
			usageLimit: 11,
			expiresAt: null,
			userId: 'alice'
		);
	}//end testCreateRejectsUsageLimitEleven()

	/**
	 * Test create rejects missing required fields.
	 *
	 * @return void
	 */
	public function testCreateRejectsEmptySnapshot(): void {
		$this->mapper->expects($this->never())->method('insert');
		$this->expectException(InvalidArgumentException::class);

		$this->service->create(
			secretId: 'secret-1',
			encryptedSnapshot: '',
			salt: 'salt',
			encryptionSuiteId: 'suite-1',
			usageLimit: 1,
			expiresAt: null,
			userId: 'alice'
		);
	}//end testCreateRejectsEmptySnapshot()

	/**
	 * Test getByToken returns a valid link share.
	 *
	 * @return void
	 */
	public function testGetByTokenReturnsValidShare(): void {
		$share = $this->makeShare(['token' => 'good', 'usageCount' => 1, 'usageLimit' => 3]);
		$this->mapper->method('findByToken')->with('good')->willReturn($share);

		$result = $this->service->getByToken('good');

		$this->assertSame('good', $result->getToken());
	}//end testGetByTokenReturnsValidShare()

	/**
	 * Test getByToken throws when the token does not exist.
	 *
	 * @return void
	 */
	public function testGetByTokenThrowsWhenMissing(): void {
		$this->mapper->method('findByToken')->willThrowException(new DoesNotExistException('nope'));
		$this->expectException(RuntimeException::class);

		$this->service->getByToken('missing');
	}//end testGetByTokenThrowsWhenMissing()

	/**
	 * Test getByToken deletes and throws when usage is exhausted.
	 *
	 * @return void
	 */
	public function testGetByTokenThrowsAndDeletesWhenUsageExhausted(): void {
		$share = $this->makeShare(['usageCount' => 3, 'usageLimit' => 3]);
		$this->mapper->method('findByToken')->willReturn($share);
		$this->mapper->expects($this->once())->method('delete')->with($share);

		$this->expectException(RuntimeException::class);
		$this->service->getByToken('exhausted');
	}//end testGetByTokenThrowsAndDeletesWhenUsageExhausted()

	/**
	 * Test getByToken deletes and throws when expired.
	 *
	 * @return void
	 */
	public function testGetByTokenThrowsAndDeletesWhenExpired(): void {
		$share = $this->makeShare(['expiresAt' => new DateTime('2000-01-01')]);
		$this->mapper->method('findByToken')->willReturn($share);
		$this->mapper->expects($this->once())->method('delete')->with($share);

		$this->expectException(RuntimeException::class);
		$this->service->getByToken('expired');
	}//end testGetByTokenThrowsAndDeletesWhenExpired()

	/**
	 * Test getByToken deletes and throws when brute-force threshold reached.
	 *
	 * @return void
	 */
	public function testGetByTokenThrowsAndDeletesWhenBruteForced(): void {
		$share = $this->makeShare(['failedAttempts' => 5]);
		$this->mapper->method('findByToken')->willReturn($share);
		$this->mapper->expects($this->once())->method('delete')->with($share);

		$this->expectException(RuntimeException::class);
		$this->service->getByToken('bruteforced');
	}//end testGetByTokenThrowsAndDeletesWhenBruteForced()

	/**
	 * Test confirmAccess increments usage atomically and resets failures.
	 *
	 * @return void
	 */
	public function testConfirmAccessIncrementsUsage(): void {
		$updated = $this->makeShare(['usageCount' => 2, 'usageLimit' => 3]);
		$this->mapper->expects($this->once())
			->method('incrementUsageIfBelowLimit')
			->with('tok')
			->willReturn(1);
		$this->mapper->method('findByToken')->with('tok')->willReturn($updated);
		// Not yet at the limit, so it must NOT be deleted.
		$this->mapper->expects($this->never())->method('delete');

		$result = $this->service->confirmAccess('tok');

		$this->assertSame(2, $result->getUsageCount());
	}//end testConfirmAccessIncrementsUsage()

	/**
	 * Test confirmAccess deletes the share when the limit is reached.
	 *
	 * @return void
	 */
	public function testConfirmAccessDeletesWhenLimitReached(): void {
		$updated = $this->makeShare(['usageCount' => 3, 'usageLimit' => 3]);
		$this->mapper->method('incrementUsageIfBelowLimit')->willReturn(1);
		$this->mapper->method('findByToken')->willReturn($updated);
		$this->mapper->expects($this->once())->method('delete')->with($updated);

		$result = $this->service->confirmAccess('tok');

		$this->assertSame(3, $result->getUsageCount());
	}//end testConfirmAccessDeletesWhenLimitReached()

	/**
	 * Test confirmAccess throws when the atomic update affects no rows.
	 *
	 * @return void
	 */
	public function testConfirmAccessThrowsWhenNoRowsAffected(): void {
		$this->mapper->method('incrementUsageIfBelowLimit')->willReturn(0);
		$this->mapper->expects($this->never())->method('findByToken');

		$this->expectException(RuntimeException::class);
		$this->service->confirmAccess('tok');
	}//end testConfirmAccessThrowsWhenNoRowsAffected()

	/**
	 * Test recordFailedAttempt increments the counter below the threshold.
	 *
	 * @return void
	 */
	public function testRecordFailedAttemptIncrements(): void {
		$share = $this->makeShare(['failedAttempts' => 2]);
		$this->mapper->method('findByToken')->willReturn($share);
		$this->mapper->expects($this->once())
			->method('update')
			->willReturnCallback(
				function (LinkShare $s) {
					$this->assertSame(3, $s->getFailedAttempts());
					return $s;
				}
			);
		$this->mapper->expects($this->never())->method('delete');

		$this->service->recordFailedAttempt('tok');
	}//end testRecordFailedAttemptIncrements()

	/**
	 * Test recordFailedAttempt deletes the share at the 5th failure.
	 *
	 * @return void
	 */
	public function testRecordFailedAttemptDeletesAtThreshold(): void {
		$share = $this->makeShare(['failedAttempts' => 4]);
		$this->mapper->method('findByToken')->willReturn($share);
		$this->mapper->expects($this->once())->method('delete')->with($share);
		$this->mapper->expects($this->never())->method('update');

		$this->service->recordFailedAttempt('tok');

		$this->assertSame(5, $share->getFailedAttempts());
	}//end testRecordFailedAttemptDeletesAtThreshold()

	/**
	 * Test recordFailedAttempt is a no-op for a non-existent token.
	 *
	 * @return void
	 */
	public function testRecordFailedAttemptNoopForMissingToken(): void {
		$this->mapper->method('findByToken')->willThrowException(new DoesNotExistException('nope'));
		$this->mapper->expects($this->never())->method('update');
		$this->mapper->expects($this->never())->method('delete');

		$this->service->recordFailedAttempt('missing');
	}//end testRecordFailedAttemptNoopForMissingToken()

	/**
	 * Test listBySecret filters to the requesting owner only (IDOR-safe).
	 *
	 * @return void
	 */
	public function testListBySecretFiltersByOwner(): void {
		$mine = $this->makeShare(['id' => 'a', 'createdBy' => 'alice']);
		$theirs = $this->makeShare(['id' => 'b', 'createdBy' => 'bob']);
		$this->mapper->method('findBySecretId')->with('secret-1')->willReturn([$mine, $theirs]);

		$result = $this->service->listBySecret('secret-1', 'alice');

		$this->assertCount(1, $result);
		$this->assertSame('a', $result[0]->getId());
	}//end testListBySecretFiltersByOwner()

	/**
	 * Test delete validates ownership and removes the row.
	 *
	 * @return void
	 */
	public function testDeleteByOwnerSucceeds(): void {
		$share = $this->makeShare(['id' => 'ls-7', 'createdBy' => 'alice']);
		$this->mapper->method('findById')->with('ls-7')->willReturn($share);
		$this->mapper->expects($this->once())->method('delete')->with($share);

		$this->service->delete('ls-7', 'alice');
	}//end testDeleteByOwnerSucceeds()

	/**
	 * Test delete rejects a non-owner with InvalidArgumentException.
	 *
	 * @return void
	 */
	public function testDeleteByNonOwnerRejected(): void {
		$share = $this->makeShare(['id' => 'ls-7', 'createdBy' => 'alice']);
		$this->mapper->method('findById')->willReturn($share);
		$this->mapper->expects($this->never())->method('delete');

		$this->expectException(InvalidArgumentException::class);
		$this->service->delete('ls-7', 'mallory');
	}//end testDeleteByNonOwnerRejected()

	/**
	 * Test delete throws when the link share does not exist.
	 *
	 * @return void
	 */
	public function testDeleteThrowsWhenMissing(): void {
		$this->mapper->method('findById')->willThrowException(new DoesNotExistException('nope'));

		$this->expectException(RuntimeException::class);
		$this->service->delete('missing', 'alice');
	}//end testDeleteThrowsWhenMissing()

	/**
	 * Test deleteBySecretId cascades to the mapper.
	 *
	 * @return void
	 */
	public function testDeleteBySecretIdCascades(): void {
		$this->mapper->expects($this->once())->method('deleteBySecretId')->with('secret-1');

		$this->service->deleteBySecretId('secret-1');
	}//end testDeleteBySecretIdCascades()

	/**
	 * Test deleteByUserId cascades to the mapper.
	 *
	 * @return void
	 */
	public function testDeleteByUserIdCascades(): void {
		$this->mapper->expects($this->once())->method('deleteByUserId')->with('alice');

		$this->service->deleteByUserId('alice');
	}//end testDeleteByUserIdCascades()
}//end class
