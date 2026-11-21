<?php

/**
 * DeviceApprovalService::create, pinned: refusals, the stored request, the page and the audit.
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

use InvalidArgumentException;
use OCA\Keepiq\Db\DeviceApproval;
use OCA\Keepiq\Db\DeviceApprovalMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Service\DeviceApprovalRequest;
use OCA\Keepiq\Service\DeviceApprovalService;
use OCA\Keepiq\Service\NotificationService;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Every refusal and write of a device-approval request.
 */
class DeviceApprovalCreateTest extends TestCase {

	/**
	 * The calls recorded so far.
	 *
	 * @var array<int,array<int,mixed>>
	 */
	private array $calls = [];

	/**
	 * Whether device approval is on.
	 *
	 * @var bool
	 */
	private bool $enabled = true;

	/**
	 * The service over recording collaborators.
	 *
	 * @return DeviceApprovalService
	 */
	private function service(): DeviceApprovalService {
		$mapper = $this->createMock(DeviceApprovalMapper::class);
		$mapper->method('insert')->willReturnCallback(function (DeviceApproval $row): DeviceApproval {
			$created = $row->getCreatedAt();
			$this->calls[] = [
				'insert',
				strlen((string)$row->getId()),
				$row->getUserId(),
				$row->getClientKind(),
				$row->getDeviceLabel(),
				$row->getRequesterIp(),
				$row->getRequesterAgent(),
				$row->getRequestPublicKey(),
				$row->getRequestSecretHash(),
				$row->getStatus(),
				$row->getExpiresAt()?->getTimestamp() - $created?->getTimestamp(),
			];
			return $row;
		});

		$notifications = $this->createMock(NotificationService::class);
		$notifications->method('notify')->willReturnCallback(function (...$args): bool {
			$this->calls[] = array_merge(['notify'], array_map(static fn ($a) => is_string($a) && strlen($a) === 36 ? 'uuid' : $a, $args));
			return true;
		});

		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueBool')->willReturnCallback(function (string $app, string $key, bool $default): bool {
			$this->calls[] = ['config', $app, $key, $default];
			return $this->enabled;
		});

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(function (int $length, string $chars): string {
			$this->calls[] = ['random', $length, $chars === ISecureRandom::CHAR_ALPHANUMERIC];
			return str_repeat('s', $length);
		});

		$events = $this->createMock(IEventDispatcher::class);
		$events->method('dispatchTyped')->willReturnCallback(function (AuditEvent $event): void {
			$this->calls[] = ['audit', $event->getActorId(), $event->getEventType(), $event->getObjectType(), strlen((string)$event->getObjectId()), $event->getMetadata()];
		});

		return new DeviceApprovalService(mapper: $mapper, notifications: $notifications, appConfig: $config, random: $random, eventDispatcher: $events);
	}//end service()

	/**
	 * Create and answer the trace with the outcome.
	 *
	 * @param array<int,string> $args userId, publicKey, clientKind, label, address, agent
	 *
	 * @return array<int,mixed>
	 */
	private function create(array $args): array {
		$this->calls = [];
		try {
			$result = $this->service()->create(new DeviceApprovalRequest(...$args));
			$this->calls[] = ['returned', strlen($result['id']), $result['requestSecret'], strlen($result['expiresAt'])];
		} catch (Throwable $e) {
			$this->calls[] = ['threw', get_class($e), $e->getMessage()];
		}

		return $this->calls;
	}//end create()

	/**
	 * Each refusal, in check order.
	 *
	 * @return void
	 */
	public function testRefusals(): void {
		$key = base64_encode(str_repeat('k', 32));

		$this->enabled = false;
		$this->assertSame(['threw', ForbiddenException::class, 'Device approval is turned off'], $this->lastOf(calls: $this->create(args: ['alice', 'nope', 'tv', 'L', '1', 'A'])));

		$this->enabled = true;
		$this->assertSame(['threw', InvalidArgumentException::class, 'publicKey must be a raw X25519 public key'], $this->lastOf(calls: $this->create(args: ['alice', base64_encode('short'), 'tv', 'L', '1', 'A'])));
		$this->assertSame(['threw', InvalidArgumentException::class, 'publicKey must be a raw X25519 public key'], $this->lastOf(calls: $this->create(args: ['alice', '***', 'web', 'L', '1', 'A'])));
		$this->assertSame(['threw', InvalidArgumentException::class, 'clientKind must be web or extension'], $this->lastOf(calls: $this->create(args: ['alice', $key, 'tv', 'L', '1', 'A'])));
	}//end testRefusals()

	/**
	 * A request stores the truncated facts and the secret's hash, pages the user and is audited.
	 *
	 * @return void
	 */
	public function testARequestIsStoredPagedAndAudited(): void {
		$key = base64_encode(str_repeat('k', 32));
		$calls = $this->create(args: ['alice', $key, 'extension', '  ' . str_repeat('L', 300) . '  ', str_repeat('9', 80), str_repeat('a', 600)]);

		$this->assertSame(
			[
				['config', 'keepiq', DeviceApprovalService::ENABLED_KEY, true],
				['random', 48, true],
				['insert', 36, 'alice', 'extension', str_repeat('L', 255), str_repeat('9', 64), str_repeat('a', 512), $key, hash('sha256', str_repeat('s', 48)), DeviceApproval::STATUS_PENDING, 900],
				['notify', 'device_approval_requested', 'alice', ['request_id' => $calls[2][0] === 'insert' ? $calls[3][3]['request_id'] : null, 'device_label' => str_repeat('L', 255)], 'device_approval', 'uuid'],
				['audit', 'alice', 'device_approval.requested', 'device_approval', 36, ['clientKind' => 'extension']],
				['returned', 36, str_repeat('s', 48), 25],
			],
			$calls
		);
		$this->assertSame(36, strlen((string)$calls[3][3]['request_id']));
	}//end testARequestIsStoredPagedAndAudited()

	/**
	 * The last recorded call.
	 *
	 * @param array<int,mixed> $calls The calls
	 *
	 * @return mixed
	 */
	private function lastOf(array $calls): mixed {
		return $calls[array_key_last($calls)];
	}//end lastOf()
}//end class
