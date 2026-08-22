<?php

/**
 * Unit tests for RotationPolicyService (rotation-expiry-policies §8.1/§8.2).
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
use OCA\Keepiq\Db\ExpiryPolicy;
use OCA\Keepiq\Db\ExpiryPolicyMapper;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\RotationFlagMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\RotationFlagService;
use OCA\Keepiq\Service\RotationPolicyService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IAppConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RotationPolicyService.
 */
class RotationPolicyServiceTest extends TestCase {
	private RotationPolicyService $service;

	private ExpiryPolicyMapper&MockObject $policyMapper;

	private RotationFlagMapper&MockObject $flagMapper;

	private SecretMapper&MockObject $secretMapper;

	private IAppConfig&MockObject $appConfig;

	/**
	 * Build the service with fresh mocks; admin default OFF.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->policyMapper = $this->createMock(originalClassName: ExpiryPolicyMapper::class);
		$this->flagMapper = $this->createMock(originalClassName: RotationFlagMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->appConfig = $this->createMock(originalClassName: IAppConfig::class);
		$this->appConfig->method('getValueInt')->willReturnCallback(
			static fn (string $app, string $key, int $default = 0): int => $default
		);

		$this->service = new RotationPolicyService(
			policyMapper: $this->policyMapper,
			flagService: new RotationFlagService(
				flagMapper: $this->flagMapper,
				secretMapper: $this->secretMapper,
				eventDispatcher: null,
			),
			appConfig: $this->appConfig,
			eventDispatcher: null,
			config: null,
		);
	}//end setUp()

	/**
	 * Build an owned Secret.
	 *
	 * @param string $ownerId The owner
	 *
	 * @return Secret
	 */
	private function ownedSecret(string $ownerId = 'alice'): Secret {
		$secret = new Secret();
		$secret->setId('sec-1');
		$secret->setName('Wiki');
		$secret->setOwnerType('user');
		$secret->setOwnerId($ownerId);
		$secret->setTypeId('type-login');
		$secret->setCreatedAt(new DateTime('2026-01-01T00:00:00Z'));
		return $secret;
	}//end ownedSecret()

	/**
	 * Build a type policy.
	 *
	 * @param int|null $maxAgeDays Max age in days
	 * @param string $scope The scope
	 * @param string $scopeId The scoped id
	 *
	 * @return ExpiryPolicy
	 */
	private function policy(?int $maxAgeDays, string $scope = 'type', string $scopeId = 'type-login'): ExpiryPolicy {
		$policy = new ExpiryPolicy();
		$policy->setId('pol-1');
		$policy->setOwnerId('alice');
		$policy->setScope($scope);
		$policy->setScopeId($scopeId);
		$policy->setMaxAgeDays($maxAgeDays);
		return $policy;
	}//end policy()

	/**
	 * 8.1: no per-secret expiry, no policies, admin default off →
	 * the secret never expires.
	 *
	 * @return void
	 */
	public function testResolveNoPolicyNeverExpires(): void {
		$this->policyMapper->method('findApplicable')->willReturn([]);

		$this->assertNull($this->service->resolveEffectiveExpiry(secret: $this->ownedSecret()));
	}//end testResolveNoPolicyNeverExpires()

	/**
	 * 8.1: the EARLIEST applicable instant wins — a per-secret expiry
	 * before the policy-derived instant is returned, and vice versa.
	 *
	 * @return void
	 */
	public function testResolvePicksEarliestInstant(): void {
		$secret = $this->ownedSecret();
		$secret->setKeyUpdatedAt(new DateTime('2026-06-01T00:00:00Z'));
		// Policy: key age + 90 days = 2026-08-30.
		$this->policyMapper->method('findApplicable')->willReturn([$this->policy(90)]);

		// Per-secret expiry EARLIER than the policy instant.
		$secret->setExpiresAt(new DateTime('2026-07-01T00:00:00Z'));
		$resolved = $this->service->resolveEffectiveExpiry(secret: $secret);
		$this->assertSame('2026-07-01', $resolved->format('Y-m-d'));

		// Per-secret expiry LATER than the policy instant.
		$secret->setExpiresAt(new DateTime('2026-12-01T00:00:00Z'));
		$resolved = $this->service->resolveEffectiveExpiry(secret: $secret);
		$this->assertSame('2026-08-30', $resolved->format('Y-m-d'));
	}//end testResolvePicksEarliestInstant()

	/**
	 * 8.1: when key_updated_at is null the policy base falls back to
	 * created_at; non-matching scopes are ignored; no decryption is
	 * involved anywhere (server-visible fields only).
	 *
	 * @return void
	 */
	public function testResolveFallsBackToCreatedAtAndFiltersScope(): void {
		$secret = $this->ownedSecret();
		// key_updated_at null → base = created_at 2026-01-01 (+30 = 01-31).
		$this->policyMapper->method('findApplicable')->willReturn([
			$this->policy(30),
			$this->policy(5, 'type', 'type-OTHER'),
			$this->policy(5, 'folder', 'folder-OTHER'),
		]);

		$resolved = $this->service->resolveEffectiveExpiry(secret: $secret);
		$this->assertSame('2026-01-31', $resolved->format('Y-m-d'));
	}//end testResolveFallsBackToCreatedAtAndFiltersScope()

	/**
	 * 8.2: flag() is idempotent — an existing OPEN flag is returned
	 * as-is, no second row inserted.
	 *
	 * @return void
	 */
	public function testFlagIdempotentOnePerSecret(): void {
		$existing = new RotationFlag();
		$existing->setId('flag-1');
		$existing->setSecretId('sec-1');
		$existing->setStatus('open');
		$this->flagMapper->method('findBySecret')->willReturn($existing);
		$this->flagMapper->expects($this->never())->method('insert');
		$this->flagMapper->expects($this->never())->method('update');

		$result = $this->service->flag(secretId: 'sec-1', reason: 'policy_expiry');
		$this->assertSame('flag-1', $result->getId());
	}//end testFlagIdempotentOnePerSecret()

	/**
	 * 8.2: mark-rotated refuses to close the flag when key_updated_at
	 * has NOT advanced past the flag-time value; it closes on a real
	 * advance.
	 *
	 * @return void
	 */
	public function testMarkRotatedRequiresProvenKeyAdvance(): void {
		$frozen = new DateTime('2026-06-01T00:00:00Z');

		$flagRow = new RotationFlag();
		$flagRow->setId('flag-1');
		$flagRow->setSecretId('sec-1');
		$flagRow->setStatus('open');
		$flagRow->setReason('user_flagged');
		$flagRow->setKeyUpdatedAtAtFlag($frozen);
		$this->flagMapper->method('findById')->willReturn($flagRow);

		$secret = $this->ownedSecret();
		$secret->setKeyUpdatedAt(clone $frozen);
		$this->secretMapper->method('findById')->willReturn($secret);

		// No advance → requiresRotation, flag untouched.
		$this->flagMapper->expects($this->never())->method('update');
		$result = $this->service->markRotated(flagId: 'flag-1', userId: 'alice');
		$this->assertFalse($result['resolved']);
		$this->assertTrue($result['requiresRotation']);
		$this->assertSame('open', $flagRow->getStatus());
	}//end testMarkRotatedRequiresProvenKeyAdvance()

	/**
	 * 8.2: mark-rotated closes the flag when the head's key advanced.
	 *
	 * @return void
	 */
	public function testMarkRotatedClosesOnKeyAdvance(): void {
		$flagRow = new RotationFlag();
		$flagRow->setId('flag-1');
		$flagRow->setSecretId('sec-1');
		$flagRow->setStatus('open');
		$flagRow->setReason('user_flagged');
		$flagRow->setKeyUpdatedAtAtFlag(new DateTime('2026-06-01T00:00:00Z'));
		$this->flagMapper->method('findById')->willReturn($flagRow);

		$secret = $this->ownedSecret();
		$secret->setKeyUpdatedAt(new DateTime('2026-06-02T00:00:00Z'));
		$this->secretMapper->method('findById')->willReturn($secret);

		$updated = false;
		$this->flagMapper->method('update')->willReturnCallback(
			static function (RotationFlag $row) use (&$updated) {
				$updated = true;
				return $row;
			}
		);

		$result = $this->service->markRotated(flagId: 'flag-1', userId: 'alice');
		$this->assertTrue($result['resolved']);
		$this->assertTrue($updated);
		$this->assertSame('rotated', $flagRow->getStatus());
		$this->assertNotNull($flagRow->getResolvedAt());
	}//end testMarkRotatedClosesOnKeyAdvance()

	/**
	 * 8.2: batch-flag persists IDs only with reason user_flagged and
	 * skips secrets the caller does not own.
	 *
	 * @return void
	 */
	public function testFlagBatchOwnerScopedIdsOnly(): void {
		$mine = $this->ownedSecret();
		$mine->setId('sec-mine');
		$foreign = $this->ownedSecret('bob');
		$foreign->setId('sec-foreign');

		$this->secretMapper->method('findById')->willReturnCallback(
			static function (string $id) use ($mine, $foreign): Secret {
				return match ($id) {
					'sec-mine' => $mine,
					'sec-foreign' => $foreign,
					default => throw new DoesNotExistException('missing'),
				};
			}
		);
		$this->flagMapper->method('findBySecret')->willThrowException(new DoesNotExistException('none'));

		$inserted = [];
		$this->flagMapper->method('insert')->willReturnCallback(
			static function (RotationFlag $row) use (&$inserted) {
				$inserted[] = $row;
				return $row;
			}
		);

		$flagged = $this->service->flagBatch(userId: 'alice', secretIds: ['sec-mine', 'sec-foreign', 'sec-gone']);

		$this->assertSame(1, $flagged);
		$this->assertCount(1, $inserted);
		$this->assertSame('sec-mine', $inserted[0]->getSecretId());
		$this->assertSame('user_flagged', $inserted[0]->getReason());
		$this->assertSame('alice', $inserted[0]->getFlaggedBy());
	}//end testFlagBatchOwnerScopedIdsOnly()

	/**
	 * 8.2: the compromise sweep auto-raises suite_compromise flags for
	 * every possibly-compromised secret and skips the rest.
	 *
	 * @return void
	 */
	public function testFlagCompromisedSecretsRaisesOnlyMarked(): void {
		$clean = $this->ownedSecret();
		$clean->setId('sec-clean');
		$hit = $this->ownedSecret();
		$hit->setId('sec-hit');
		$hit->setPossiblyCompromisedAt(new DateTime('2026-07-01T00:00:00Z'));

		$this->secretMapper->method('findByOwner')->willReturn([$clean, $hit]);
		$this->secretMapper->method('findById')->willReturn($hit);
		$this->flagMapper->method('findBySecret')->willThrowException(new DoesNotExistException('none'));

		$inserted = [];
		$this->flagMapper->method('insert')->willReturnCallback(
			static function (RotationFlag $row) use (&$inserted) {
				$inserted[] = $row;
				return $row;
			}
		);

		$count = $this->service->flagCompromisedSecrets(ownerId: 'alice');

		$this->assertSame(1, $count);
		$this->assertCount(1, $inserted);
		$this->assertSame('sec-hit', $inserted[0]->getSecretId());
		$this->assertSame('suite_compromise', $inserted[0]->getReason());
	}//end testFlagCompromisedSecretsRaisesOnlyMarked()
}//end class
