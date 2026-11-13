<?php

/**
 * HoneyTripwireService::raiseAlert, pinned: lookup, alert row, pages and audit.
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
use OCA\Keepiq\Db\HoneyAlert;
use OCA\Keepiq\Db\HoneyAlertMapper;
use OCA\Keepiq\Db\HoneyFlag;
use OCA\Keepiq\Db\HoneyFlagMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Service\HoneyAccess;
use OCA\Keepiq\Service\HoneyAlertRecorder;
use OCA\Keepiq\Service\HoneyTripwireService;
use OCA\Keepiq\Service\NotificationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Every call one honey access makes, new and collapsed.
 */
class HoneyTripwireAlertTest extends TestCase {

	/**
	 * Run one access against a flagged secret and answer the calls.
	 *
	 * @param HoneyAlert|null $latest The accessor's latest alert
	 * @param string|null $accessorId The accessor id
	 *
	 * @return array<int,array<int,mixed>>
	 */
	private function access(?HoneyAlert $latest, ?string $accessorId): array {
		$calls = [];
		$flag = new HoneyFlag();
		$flag->setId('flag-1');
		$flag->setSecretId('secret-1');
		$flag->setOwnerId('alice');
		$flags = $this->createMock(HoneyFlagMapper::class);
		$flags->method('findBySecretId')->willReturnCallback(static function (string $secretId) use (&$calls, $flag): HoneyFlag {
			$calls[] = ['findFlag', $secretId];
			return $flag;
		});

		$alerts = $this->createMock(HoneyAlertMapper::class);
		$alerts->method('findLatestForAccessor')->willReturnCallback(static function (...$args) use (&$calls, $latest): ?HoneyAlert {
			$calls[] = array_merge(['findLatest'], $args);
			return $latest;
		});
		foreach (['insert', 'update'] as $method) {
			$alerts->method($method)->willReturnCallback(static function (HoneyAlert $alert) use (&$calls, $method): HoneyAlert {
				$calls[] = [$method, strlen((string)$alert->getId()), $alert->getHoneyFlagId(), $alert->getSecretId(), $alert->getAccessorType(), $alert->getAccessorId(), $alert->getChannel(), $alert->getIp(), $alert->getUserAgent(), $alert->getAccessCount(), $alert->getAccessedAt() !== null];
				return $alert;
			});
		}

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueInt')->willReturnCallback(static function (string $app, string $key, int $default) use (&$calls): int {
			$calls[] = ['config', $key, $default];
			return $default;
		});

		$admin = $this->createMock(IUser::class);
		$admin->method('getUID')->willReturn('root');
		$owner = $this->createMock(IUser::class);
		$owner->method('getUID')->willReturn('alice');
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn([$admin, $owner]);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(static function (...$args) use (&$calls): bool {
			$calls[] = array_merge(['notify'], $args);
			return true;
		});

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(static function (AuditEvent $event) use (&$calls): void {
			$calls[] = ['audit', $event->getActorType(), $event->getEventType(), $event->getObjectType(), $event->getObjectId(), $event->getMetadata()];
		});

		$service = new HoneyTripwireService(
			flagMapper: $flags,
			alertRecorder: new HoneyAlertRecorder(alertMapper: $alerts, appConfig: $config),
			groupManager: $groups,
			notificationService: $notifications,
			logger: new NullLogger(),
			eventDispatcher: $events,
		);
		$calls[] = ['returned', $service->raiseAlert(new HoneyAccess('secret-1', 'link_visitor', $accessorId, 'link', '198.51.100.4', 'Agent/2'))];

		return $calls;
	}//end access()

	/**
	 * A first access raises a new alert, pages the owner and each admin once, and audits.
	 *
	 * @return void
	 */
	public function testANewAccessRaisesPagesAndAudits(): void {
		$calls = $this->access(latest: null, accessorId: null);

		$this->assertSame(
			[
				['findFlag', 'secret-1'],
				['config', 'honey_dedup_window_seconds', $calls[1][2]],
				['findLatest', 'flag-1', 'link_visitor', null, 'link'],
				['insert', 36, 'flag-1', 'secret-1', 'link_visitor', null, 'link', '198.51.100.4', 'Agent/2', 1, true],
				['notify', 'honey_access', 'alice', ['channel' => 'link', 'accessor' => 'anonymous'], 'secret', 'secret-1'],
				['notify', 'honey_access', 'root', ['channel' => 'link', 'accessor' => 'anonymous'], 'secret', 'secret-1'],
				['audit', AuditEvent::ACTOR_SYSTEM, 'honey.accessed', 'secret', 'secret-1', ['channel' => 'link']],
				['returned', true],
			],
			$calls
		);
	}//end testANewAccessRaisesPagesAndAudits()

	/**
	 * A repeat inside the window collapses onto the latest alert: no page, still audited.
	 *
	 * @return void
	 */
	public function testARepeatCollapsesWithoutPaging(): void {
		$latest = new HoneyAlert();
		$latest->setId('alert-1');
		$latest->setHoneyFlagId('flag-1');
		$latest->setAccessCount(2);
		$latest->setAccessedAt(new DateTime('-5 seconds'));

		$calls = $this->access(latest: $latest, accessorId: 'visitor-7');

		$this->assertSame(['findLatest', 'flag-1', 'link_visitor', 'visitor-7', 'link'], $calls[2]);
		$this->assertSame(['update', 7, 'flag-1', '', '', null, '', null, null, 3, true], $calls[3]);
		$this->assertSame('audit', $calls[4][0]);
		$this->assertSame(['returned', true], $calls[5]);
		$this->assertCount(6, $calls);
	}//end testARepeatCollapsesWithoutPaging()
}//end class
