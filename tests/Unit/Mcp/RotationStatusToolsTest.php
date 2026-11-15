<?php

/**
 * Unit tests for the keepiq.rotationStatus MCP tool.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Mcp
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

namespace OCA\Keepiq\Tests\Unit\Mcp;

use DateTime;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Mcp\McpToolContext;
use OCA\Keepiq\Mcp\RotationStatusTools;
use OCA\Keepiq\Service\RotationFlagService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * RotationStatusTools: names of vanished secrets, foreign rows, dates and the audit count.
 *
 * @covers \OCA\Keepiq\Mcp\RotationStatusTools
 * @uses   \OCA\Keepiq\Mcp\McpToolContext
 * @uses   \OCA\Keepiq\Mcp\MetadataAllowList
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Db\RotationFlag
 * @uses   \OCA\Keepiq\Db\Secret
 */
class RotationStatusToolsTest extends TestCase {

	/** @var list<AuditEvent> */
	private array $audits = [];

	/**
	 * The tool over the given open flags, for alice.
	 *
	 * @param list<mixed> $flags What openFlags returns
	 * @param SecretMapper $mapper The secret mapper
	 *
	 * @return RotationStatusTools
	 */
	private function tools(array $flags, SecretMapper $mapper): RotationStatusTools {
		$service = $this->createMock(RotationFlagService::class);
		$service->expects($this->once())->method('openFlags')->with('alice')->willReturn($flags);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->audits[] = $event;
		});

		return new RotationStatusTools(
			flags: $service,
			secretMapper: $mapper,
			context: new McpToolContext(userSession: $session, dispatcher: $dispatcher)
		);
	}//end tools()

	/**
	 * An open flag on a secret.
	 *
	 * @param string $secretId The secret
	 * @param string $reason The reason
	 *
	 * @return RotationFlag
	 */
	private static function flag(string $secretId, string $reason): RotationFlag {
		$flag = new RotationFlag();
		$flag->setSecretId($secretId);
		$flag->setReason($reason);
		$flag->setStatus('open');
		return $flag;
	}//end flag()

	/**
	 * A flag whose secret is gone has a null name; the key date is formatted.
	 *
	 * @return void
	 */
	public function testVanishedSecretHasNullNameAndDatesAreFormatted(): void {
		$flag = self::flag(secretId: 'gone', reason: 'user_flagged');
		$flag->setKeyUpdatedAtAtFlag(new DateTime('2026-08-15T09:30:00+00:00'));
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->expects($this->once())->method('findById')->with('gone')->willThrowException(new DoesNotExistException('gone'));

		$result = $this->tools(flags: [$flag], mapper: $mapper)->rotationStatus();

		$this->assertSame(
			[['id' => 'gone', 'name' => null, 'reason' => 'user_flagged', 'status' => 'open', 'flaggedAt' => null, 'keyUpdatedAtAtFlag' => '2026-08-15T09:30:00+00:00']],
			$result['flags']
		);
		$this->assertSame(['open' => 1, 'overdue' => 0, 'compromised' => 0], $result['counts']);
	}//end testVanishedSecretHasNullNameAndDatesAreFormatted()

	/**
	 * Rows that are not RotationFlags are skipped and not counted.
	 *
	 * @return void
	 */
	public function testForeignRowsAreSkipped(): void {
		$secret = new Secret();
		$secret->setName('db');
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->expects($this->once())->method('findById')->with('s1')->willReturn($secret);

		$result = $this->tools(flags: [new stdClass(), self::flag(secretId: 's1', reason: 'policy_expiry'), ['id' => 'x']], mapper: $mapper)->rotationStatus();

		$this->assertSame(['s1'], array_column($result['flags'], 'id'));
		$this->assertSame('db', $result['flags'][0]['name']);
		$this->assertSame(['open' => 1, 'overdue' => 1, 'compromised' => 0], $result['counts']);
		$this->assertSame(['tool' => 'rotationStatus', 'resultCount' => 1], $this->audits[0]->getMetadata());
	}//end testForeignRowsAreSkipped()

	/**
	 * No open flags: zero counts, an empty list, still one audit.
	 *
	 * @return void
	 */
	public function testNoFlagsIsZeroCountsAndAudited(): void {
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->expects($this->never())->method('findById');

		$this->assertSame(
			['flags' => [], 'counts' => ['open' => 0, 'overdue' => 0, 'compromised' => 0]],
			$this->tools(flags: [], mapper: $mapper)->rotationStatus()
		);
		$this->assertCount(1, $this->audits);
		$this->assertSame(['tool' => 'rotationStatus', 'resultCount' => 0], $this->audits[0]->getMetadata());
	}//end testNoFlagsIsZeroCountsAndAudited()
}//end class
