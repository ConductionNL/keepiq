<?php

/**
 * Unit tests for HoneyTripwireListener (honey-credentials §6): channel
 * derivation per source event, the share-copy pivot, and fail-softness.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Listener
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

namespace OCA\Keepiq\Tests\Unit\Listener;

use OCA\Keepiq\Db\LinkShare;
use OCA\Keepiq\Db\LinkShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\ShareTarget;
use OCA\Keepiq\Db\ShareTargetMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\HoneyTripwireListener;
use OCA\Keepiq\Service\HoneyTripwireService;
use OCA\Keepiq\Service\ShareQuarantineScanner;
use OCA\Keepiq\Service\ShareRowJudge;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the central tripwire listener.
 */
class HoneyTripwireListenerTest extends TestCase {
	private HoneyTripwireListener $listener;

	private HoneyTripwireService&MockObject $honeyService;

	private LinkShareMapper&MockObject $linkShareMapper;

	private ShareTargetMapper&MockObject $shareTargetMapper;

	private ShareQuarantineScanner&MockObject $scanner;

	/**
	 * Build the listener over mocked collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->honeyService = $this->createMock(originalClassName: HoneyTripwireService::class);
		$this->linkShareMapper = $this->createMock(originalClassName: LinkShareMapper::class);
		$this->shareTargetMapper = $this->createMock(originalClassName: ShareTargetMapper::class);
		$this->scanner = $this->createMock(originalClassName: ShareQuarantineScanner::class);
		$copy = new Secret();
		$copy->setId('copy-1');
		$copy->setOwnerType('user');
		$copy->setOwnerId('bob');
		$secrets = $this->createMock(originalClassName: SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			static fn (string $id): Secret => ($id === 'copy-1' ? $copy : throw new DoesNotExistException('none'))
		);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');
		$request->method('getHeader')->willReturn('TestAgent/1.0');

		$this->listener = new HoneyTripwireListener(
			honeyService: $this->honeyService,
			linkShareMapper: $this->linkShareMapper,
			request: $request,
			logger: new NullLogger(),
			rowJudge: new ShareRowJudge(targets: $this->shareTargetMapper, secrets: $secrets, scanner: $this->scanner),
		);
	}//end setUp()

	/**
	 * A UI read of a flagged secret trips with channel ui + transport
	 * metadata (§6.1).
	 *
	 * @return void
	 */
	public function testSecretReadTripsUiChannel(): void {
		$this->honeyService->expects($this->once())->method('raiseAlert')
			->with(
				$this->equalTo('secret-1'),
				$this->equalTo('user'),
				$this->equalTo('bob'),
				$this->equalTo('ui'),
				$this->equalTo('192.0.2.7'),
				$this->equalTo('TestAgent/1.0'),
			)
			->willReturn(true);
		$this->shareTargetMapper->expects($this->never())->method('findByRecipientSecret');

		$this->listener->handle(
			AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: 'secret-1')
		);
	}//end testSecretReadTripsUiChannel()

	/**
	 * A read of an UNflagged copy pivots to its flagged source with
	 * channel share (§6.1).
	 *
	 * @return void
	 */
	public function testCopyReadPivotsToSourceAsShareChannel(): void {
		$this->copyOneIsBobsCopyOfSourceOne();

		$calls = [];
		$this->honeyService->method('raiseAlert')
			->willReturnCallback(static function (string $secretId, string $accessorType, ?string $accessorId, string $channel) use (&$calls): bool {
				$calls[] = [$secretId, $channel];

				return ($secretId === 'source-1');
			});

		$this->listener->handle(
			AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: 'copy-1')
		);

		$this->assertSame([['copy-1', 'ui'], ['source-1', 'share']], $calls);
	}//end testCopyReadPivotsToSourceAsShareChannel()

	/**
	 * While the quarantine scan is pending, a read of a secret whose share
	 * row the scan quarantines on the spot, or cannot judge, trips nothing on
	 * that row's source: the reader's id, address and agent are not handed
	 * to its owner.
	 *
	 * @param string $verdict The scan's verdict on the row
	 *
	 * @return void
	 */
	#[DataProvider('heldBackVerdicts')]
	public function testAReadThroughAHeldBackRowTripsNothingOnItsSource(string $verdict): void {
		$this->copyOneIsBobsCopyOfSourceOne();
		$this->scanner->method('heldBack')->willReturn($verdict);

		$calls = [];
		$this->honeyService->method('raiseAlert')
			->willReturnCallback(static function (string $secretId, string $accessorType, ?string $accessorId, string $channel) use (&$calls): bool {
				$calls[] = [$secretId, $channel];

				// The copy itself is not flagged, so the read reaches its share row.
				return ($secretId === 'source-1');
			});

		$this->listener->handle(
			AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_READ, objectType: 'secret', objectId: 'copy-1')
		);

		$this->assertSame([['copy-1', 'ui']], $calls);
	}//end testAReadThroughAHeldBackRowTripsNothingOnItsSource()

	/**
	 * The pending scan's verdicts that hold a row back.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function heldBackVerdicts(): array {
		return [
			'quarantined on the spot' => [ShareQuarantineScanner::HELD_QUARANTINED],
			'cannot be judged' => [ShareQuarantineScanner::HELD_UNJUDGED],
		];
	}//end heldBackVerdicts()

	/**
	 * Bob holds copy-1 through a share row of source-1.
	 *
	 * @return void
	 */
	private function copyOneIsBobsCopyOfSourceOne(): void {
		$shareTarget = new ShareTarget();
		$shareTarget->setSourceSecretId('source-1');
		$shareTarget->setTargetUserId('bob');
		$shareTarget->setSecretId('copy-1');
		$this->shareTargetMapper->method('findByRecipientSecret')->with('copy-1')->willReturn($shareTarget);
	}//end copyOneIsBobsCopyOfSourceOne()

	/**
	 * A machine retrieval trips with channel machine_api (§6.1).
	 *
	 * @return void
	 */
	public function testApplicationRetrievalTripsMachineChannel(): void {
		$this->honeyService->expects($this->once())->method('raiseAlert')
			->with($this->anything(), $this->equalTo('application'), $this->equalTo('app-9'), $this->equalTo('machine_api'))
			->willReturn(true);

		$this->listener->handle(
			AuditEvent::forApplication(
				actorId: 'app-9',
				eventType: AuditEventTypes::APPLICATION_SECRET_RETRIEVED,
				objectType: 'secret',
				objectId: 'secret-1'
			)
		);
	}//end testApplicationRetrievalTripsMachineChannel()

	/**
	 * An anonymous link access resolves the link row to its secret and
	 * trips with channel link (§6.1).
	 *
	 * @return void
	 */
	public function testLinkAccessResolvesSecretAndTripsLinkChannel(): void {
		$linkShare = new LinkShare();
		$linkShare->setSecretId('secret-1');
		$this->linkShareMapper->method('findById')->with('link-1')->willReturn($linkShare);

		$this->honeyService->expects($this->once())->method('raiseAlert')
			->with($this->equalTo('secret-1'), $this->equalTo('link_visitor'), $this->isNull(), $this->equalTo('link'))
			->willReturn(true);

		$this->listener->handle(
			AuditEvent::forLinkVisitor(eventType: AuditEventTypes::LINK_SHARE_ACCESSED, objectType: 'link_share', objectId: 'link-1')
		);
	}//end testLinkAccessResolvesSecretAndTripsLinkChannel()

	/**
	 * Non-read events never touch the honey service (§6.1).
	 *
	 * @return void
	 */
	public function testNonReadEventsAreIgnored(): void {
		$this->honeyService->expects($this->never())->method('raiseAlert');

		$this->listener->handle(
			AuditEvent::forUser(actorId: 'bob', eventType: AuditEventTypes::SECRET_CREATED, objectType: 'secret', objectId: 'secret-1')
		);
		$this->listener->handle(
			AuditEvent::forSystem(eventType: AuditEventTypes::HONEY_ACCESSED, objectType: 'secret', objectId: 'secret-1')
		);
	}//end testNonReadEventsAreIgnored()

	/**
	 * The listener is fail-soft: a resolver failure is swallowed (§6.2).
	 *
	 * @return void
	 */
	public function testListenerIsFailSoft(): void {
		$this->linkShareMapper->method('findById')->willThrowException(new DoesNotExistException('gone'));

		$this->listener->handle(
			AuditEvent::forLinkVisitor(eventType: AuditEventTypes::LINK_SHARE_ACCESSED, objectType: 'link_share', objectId: 'link-x')
		);

		$this->addToAssertionCount(1);
	}//end testListenerIsFailSoft()
}//end class
