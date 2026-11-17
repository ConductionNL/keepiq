<?php

/**
 * Unit tests for SiemService's dead-letter notice to administrators.
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

use OCA\Keepiq\Db\SiemQueueItemMapper;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Db\SiemSinkMapper;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\SiemService;
use OCA\Keepiq\Service\SiemSinkService;
use OCA\Keepiq\Service\SiemTransport;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The first dead letters of a sink notify every administrator once; a failing
 * notice is logged by class only and does not stop the others.
 */
class SiemServiceDeadLetterNoticeTest extends TestCase {
	/** @var SiemQueueItemMapper&MockObject */
	private SiemQueueItemMapper $queue;

	/** @var IGroupManager&MockObject */
	private IGroupManager $groups;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/**
	 * Fresh doubles: one enabled sink with nothing due.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->queue = $this->createMock(SiemQueueItemMapper::class);
		$this->queue->method('findDue')->willReturn([]);
		$this->groups = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The service over one enabled sink `soc`.
	 *
	 * @param NotificationService|null $notifications The notification service, or none
	 *
	 * @return SiemService
	 */
	private function service(?NotificationService $notifications): SiemService {
		$sink = new SiemSink();
		$sink->setId('sink-1');
		$sink->setName('soc');
		$sinks = $this->createMock(SiemSinkMapper::class);
		$sinks->method('findEnabled')->willReturn([$sink]);

		return new SiemService(
			sinkMapper: $sinks,
			queueMapper: $this->queue,
			transport: $this->createMock(SiemTransport::class),
			sinkService: $this->createMock(SiemSinkService::class),
			groupManager: $this->groups,
			notificationService: $notifications,
			logger: $this->logger,
		);
	}//end service()

	/**
	 * An administrator with the given uid.
	 *
	 * @param string $uid The uid
	 *
	 * @return IUser
	 */
	private function admin(string $uid): IUser {
		$user = $this->createStub(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}//end admin()

	/**
	 * First dead letters: each admin is told; one failing notice is logged without its message.
	 *
	 * @return void
	 */
	public function testFirstDeadLettersNotifyEveryAdmin(): void {
		$this->queue->method('countDead')->willReturnOnConsecutiveCalls(0, 3);
		$group = $this->createStub(IGroup::class);
		$group->method('getUsers')->willReturn([$this->admin(uid: 'root'), $this->admin(uid: 'ops')]);
		$this->groups->expects($this->once())->method('get')->with('admin')->willReturn($group);

		$told = [];
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->exactly(2))->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params, string $objectType, string $objectId) use (&$told): bool {
				$told[] = [$subject, $recipientId, $params, $objectType, $objectId];
				if ($recipientId === 'root') {
					throw new RuntimeException('smtp password rejected');
				}

				return true;
			}
		);
		$this->logger->expects($this->once())
			->method('warning')
			->with('Keepiq: SIEM dead-letter notification failed: RuntimeException', ['app' => 'keepiq']);

		$this->assertSame(0, $this->service(notifications: $notifications)->deliverDue());
		$this->assertSame(
			[
				['siem_dead_letter', 'root', ['sink_name' => 'soc'], 'siem_sink', 'sink-1'],
				['siem_dead_letter', 'ops', ['sink_name' => 'soc'], 'siem_sink', 'sink-1'],
			],
			$told
		);
	}//end testFirstDeadLettersNotifyEveryAdmin()

	/**
	 * Dead letters that were already there before this drain raise nothing.
	 *
	 * @return void
	 */
	public function testExistingDeadLettersRaiseNothing(): void {
		$this->queue->method('countDead')->willReturn(2);
		$this->groups->expects($this->never())->method('get');
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notify');

		$this->service(notifications: $notifications)->deliverDue();
	}//end testExistingDeadLettersRaiseNothing()

	/**
	 * Without a notification service, or without an admin group, nobody is told and nothing fails.
	 *
	 * @return void
	 */
	public function testNoNoticeWithoutServiceOrAdminGroup(): void {
		$this->queue->method('countDead')->willReturnOnConsecutiveCalls(0, 1, 0, 1);
		$this->groups->expects($this->once())->method('get')->with('admin')->willReturn(null);
		$notifications = $this->createMock(NotificationService::class);
		$notifications->expects($this->never())->method('notify');
		$this->logger->expects($this->never())->method('warning');

		$this->assertSame(0, $this->service(notifications: null)->deliverDue());
		$this->assertSame(0, $this->service(notifications: $notifications)->deliverDue());
	}//end testNoNoticeWithoutServiceOrAdminGroup()
}//end class
