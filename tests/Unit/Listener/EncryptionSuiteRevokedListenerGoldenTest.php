<?php

/**
 * Golden outcomes of the suite-revoked listener.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\EncryptionSuiteRevokedEvent;
use OCA\Keepiq\Listener\EncryptionSuiteRevokedListener;
use OCA\Keepiq\Service\DelegationService;
use OCP\EventDispatcher\Event;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which revocations sweep, promote or revoke delegations, and what each
 * logs, in order.
 *
 * @covers \OCA\Keepiq\Listener\EncryptionSuiteRevokedListener
 * @uses   \OCA\Keepiq\Event\EncryptionSuiteRevokedEvent
 */
final class EncryptionSuiteRevokedListenerGoldenTest extends TestCase {
	/**
	 * Every collaborator call, in order.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * The scenarios: owner type, compromised, sweep result, delegation
	 * result ('throw' or a count) and the expected calls.
	 *
	 * @return array<string,array<int,mixed>>
	 */
	public static function scenarios(): array {
		$app = ['app' => 'keepiq'];
		$sweep = ['sweep', 'alice', 'suite-1'];
		return [
			'application suite' => ['application', false, 'ok', 1, []],
			'promotes'          => ['user', false, 'ok', 2, [
				$sweep, ['makePermanent', 'alice'],
				['info', 'Keepiq: promoted 2 delegations to permanent after revoking suite-1 (owner=alice)', $app],
			]],
			'nothing to promote' => ['user', false, 'ok', 0, [$sweep, ['makePermanent', 'alice']]],
			'promote fails'      => ['user', false, 'ok', 'throw', [
				$sweep, ['makePermanent', 'alice'],
				['warning', 'Keepiq: EncryptionSuiteRevokedListener delegation-promote failed for alice: delegations down', $app],
			]],
			'sweep fails, still promotes' => ['user', false, 'throw', 1, [
				$sweep,
				['warning', 'Keepiq: EncryptionSuiteRevokedListener share-target sweep failed for alice: targets down', $app],
				['makePermanent', 'alice'],
				['info', 'Keepiq: promoted 1 delegations to permanent after revoking suite-1 (owner=alice)', $app],
			]],
			'compromise revokes' => ['user', true, 'ok', 3, [
				$sweep, ['revokeTemporary', 'alice', 'admin'],
				['info', 'Keepiq: revoked 3 temporary delegations after the compromise revoke of suite-1 (owner=alice)', $app],
			]],
			'compromise, nothing to revoke' => ['user', true, 'ok', 0, [$sweep, ['revokeTemporary', 'alice', 'admin']]],
			'compromise revoke fails' => ['user', true, 'throw', 'throw', [
				$sweep,
				['warning', 'Keepiq: EncryptionSuiteRevokedListener share-target sweep failed for alice: targets down', $app],
				['revokeTemporary', 'alice', 'admin'],
				['warning', 'Keepiq: EncryptionSuiteRevokedListener delegation-revoke failed for alice: delegations down', $app],
			]],
		];
	}//end scenarios()

	/**
	 * A revocation is handled exactly as pinned.
	 *
	 * @param string     $ownerType   The suite's owner type
	 * @param bool       $compromised Whether it is a compromise force-revoke
	 * @param string     $sweep       'ok' or 'throw'
	 * @param int|string $delegations A count, or 'throw'
	 * @param array      $expected    The expected calls
	 *
	 * @dataProvider scenarios
	 *
	 * @return void
	 */
	public function testHandles(string $ownerType, bool $compromised, string $sweep, int|string $delegations, array $expected): void {
		$this->listener(sweep: $sweep, delegations: $delegations)->handle(
			new EncryptionSuiteRevokedEvent(suiteId: 'suite-1', ownerType: $ownerType, ownerId: 'alice', revokedBy: 'admin', compromised: $compromised)
		);

		self::assertSame($expected, $this->calls);
	}//end testHandles()

	/**
	 * Any other event touches nothing.
	 *
	 * @return void
	 */
	public function testIgnoresOtherEvents(): void {
		$this->listener(sweep: 'ok', delegations: 1)->handle(new Event());

		self::assertSame([], $this->calls);
	}//end testIgnoresOtherEvents()

	/**
	 * The listener over recording collaborators.
	 *
	 * @param string     $sweep       'ok' or 'throw'
	 * @param int|string $delegations A count, or 'throw'
	 *
	 * @return EncryptionSuiteRevokedListener
	 */
	private function listener(string $sweep, int|string $delegations): EncryptionSuiteRevokedListener {
		$targets = $this->createMock(ShareTargetMapper::class);
		$targets->method('deleteByTargetUserAndSuite')->willReturnCallback(function (string $targetUserId, string $suiteId) use ($sweep): int {
			$this->calls[] = ['sweep', $targetUserId, $suiteId];
			if ($sweep === 'throw') {
				throw new RuntimeException('targets down');
			}

			return 1;
		});
		$answer = static function () use ($delegations): int {
			if ($delegations === 'throw') {
				throw new RuntimeException('delegations down');
			}

			return $delegations;
		};
		$service = $this->createMock(DelegationService::class);
		$service->method('makePermanent')->willReturnCallback(function (string $originalOwnerId) use ($answer): int {
			$this->calls[] = ['makePermanent', $originalOwnerId];
			return $answer();
		});
		$service->method('revokeTemporary')->willReturnCallback(function (string $originalOwnerId, string $revokedBy) use ($answer): int {
			$this->calls[] = ['revokeTemporary', $originalOwnerId, $revokedBy];
			return $answer();
		});
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(function (string $message, array $context = []) use ($level): void {
				$this->calls[] = [$level, $message, $context];
			});
		}

		return new EncryptionSuiteRevokedListener($targets, $service, $logger);
	}//end listener()
}//end class
