<?php

/**
 * The owner's side of updates, revocation, suspension and retries
 * (sharing-federated-recipients tasks 4.1 to 4.3).
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Federation
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\FederatedShareController;
use OCA\Keepiq\Controller\FederationPartnerController;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedShareOwnerChangesTest extends TestCase {
	use FederationFixtures;

	private const SHARED_SECRET = 'the-shared-secret';
	private const NEW_KEY = 'NEW-CIPHER-KEY-FOR-BOB';
	private const FINGERPRINT = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	/** @var array<string,FederatedShare> */
	private array $rows = [];

	/** @var FakeCloudFederationNotification[] Notifications sent, with the remote they went to */
	private array $sent = [];

	/** @var string[] */
	private array $sentTo = [];

	/** Whether the recipient's instance takes notifications. */
	private bool $reachable = true;

	/** @var AuditEvent[] */
	private array $audit = [];

	private FederatedShareService $service;

	private FederatedNotificationDelivery $delivery;

	protected function setUp(): void {
		$row = new FederatedShare();
		$row->setId('fs-1');
		$row->setSourceSecretId('src');
		$row->setOwnerId('alice');
		$row->setRecipientCloudId('bob@cloud.partner.example');
		$row->setPartnerId('p-cloud.partner.example');
		$row->setRecipientCertFingerprint(str_repeat('a', 64));
		$row->setKey('OLD-CIPHER-KEY-FOR-BOB');
		$row->setLogin('OLD-CIPHER-LOGIN');
		$row->setSharedSecretHash(hash('sha256', self::SHARED_SECRET));
		$row->setStatus(FederatedShare::STATUS_ACTIVE);
		$row->setNotifyAttempts(0);
		$row->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
		$this->rows['fs-1'] = $row;

		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): FederatedShare => $this->rows[$id] ?? throw new DoesNotExistException('none')
		);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('delete')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				unset($this->rows[$row->getId()]);
				return $row;
			}
		);
		$mapper->method('findByPartner')->willReturnCallback(fn (): array => array_values($this->rows));
		$mapper->method('findDueNotifications')->willReturnCallback(
			fn (DateTime $now): array => array_values(array_filter(
				$this->rows,
				static fn (FederatedShare $r): bool => $r->getPendingNotification() !== null
					&& $r->getNextNotifyAt() !== null && $r->getNextNotifyAt() <= $now
			))
		);

		$source = new Secret();
		$source->setId('src');
		$source->setName('Supplier portal');
		$source->setOwnerType('user');
		$source->setOwnerId('alice');
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturn($source);

		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());
		$ocm = $this->createMock(ICloudFederationProviderManager::class);
		$ocm->method('sendCloudNotification')->willReturnCallback(
			function (string $remote, FakeCloudFederationNotification $notification): IResponse {
				$this->sent[] = $notification;
				$this->sentTo[] = $remote;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($this->reachable ? 201 : 503);
				return $response;
			}
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->audit[] = $event;
			}
		);
		$audit = new FederatedShareAuditTrail($dispatcher);
		$messenger = new FederatedShareMessenger($ocm, $factory, $this->cloudIdManager(), $this->createMock(IUserManager::class));
		$this->delivery = new FederatedNotificationDelivery($mapper, $messenger, $audit);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$this->service = new FederatedShareService(
			shareMapper: $mapper,
			secretMapper: $secrets,
			partners: new FederationPartnerService(
				$this->partnerMapper([$this->partner('cloud.partner.example', true, true)]),
				$this->createMock(IClientService::class),
				$config
			),
			root: new FederationRootService($this->caMapper()),
			cloudIdManager: $this->cloudIdManager(),
			messenger: $messenger,
			random: $this->createMock(ISecureRandom::class),
			delivery: $this->delivery,
			audit: $audit,
		);
	}

	private function controller(string $uid = 'alice'): FederatedShareController {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederatedShareController($this->createMock(IRequest::class), $this->service, $session);
	}

	/**
	 * The audit entries carry identifiers only: none of the ciphertexts, the
	 * shared secret or its hash, and only whitelisted metadata keys.
	 */
	private function assertIdentifiersOnly(): void {
		$this->assertNotSame([], $this->audit);
		$secrets = ['OLD-CIPHER-KEY-FOR-BOB', 'OLD-CIPHER-LOGIN', self::NEW_KEY, 'NEW-LOGIN', self::SHARED_SECRET, hash('sha256', self::SHARED_SECRET)];
		foreach ($this->audit as $event) {
			$entry = json_encode([$event->getObjectId(), $event->getObjectName(), $event->getMetadata()]);
			foreach ($secrets as $secret) {
				$this->assertStringNotContainsString($secret, $entry, $event->getEventType());
			}
			$allowed = AuditEventTypes::WHITELIST[$event->getEventType()];
			$this->assertSame([], array_diff(array_keys($event->getMetadata()), $allowed), $event->getEventType());
			$this->assertSame([], array_intersect(array_keys($event->getMetadata()), AuditEventTypes::FORBIDDEN_KEYS));
		}
	}

	public function testAnUpdateReplacesTheCiphertextAndTellsBobsInstance(): void {
		$response = $this->controller()->update('fs-1', self::FINGERPRINT, self::NEW_KEY, 'NEW-LOGIN', null);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$row = $this->rows['fs-1'];
		$this->assertSame(self::NEW_KEY, $row->getKey());
		$this->assertSame('NEW-LOGIN', $row->getLogin());
		$this->assertNull($row->getAdditionalFields());
		$this->assertSame(self::FINGERPRINT, $row->getRecipientCertFingerprint());
		$this->assertNull($row->getPendingNotification());

		$this->assertCount(1, $this->sent);
		$message = $this->sent[0]->getMessage();
		$this->assertSame('SHARE_UPDATED', $message['notificationType']);
		$this->assertSame('keepiq-secret', $message['resourceType']);
		$this->assertSame('fs-1', $message['providerId']);
		$this->assertSame('cloud.partner.example', $this->sentTo[0]);
		// The hash, never the secret, and never ciphertext.
		$this->assertSame(hash('sha256', self::SHARED_SECRET), $message['notification']['sharedSecret']);
		// The share id and the owner, so Bob's Nextcloud can find whose
		// signature this is (ISignedCloudFederationProvider).
		$this->assertSame('fs-1', $message['notification']['providerId']);
		$this->assertSame('alice@cloud.here.example', $message['notification']['sender']);
		$this->assertStringNotContainsString(self::NEW_KEY, json_encode($message));

		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_UPDATED, $this->audit[0]->getEventType());
		$this->assertSame('alice', $this->audit[0]->getActorId());
		$this->assertIdentifiersOnly();
	}

	public function testOnlyTheOwnerUpdatesALiveShare(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('mallory')->update('fs-1', self::FINGERPRINT, self::NEW_KEY)->getStatus());
		$this->rows['fs-1']->setStatus(FederatedShare::STATUS_SUSPENDED);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller()->update('fs-1', self::FINGERPRINT, self::NEW_KEY)->getStatus());
		$this->rows['fs-1']->setStatus(FederatedShare::STATUS_ACTIVE);
		$this->assertSame(Http::STATUS_BAD_REQUEST, $this->controller()->update('fs-1', 'nope', self::NEW_KEY)->getStatus());

		$this->assertSame('OLD-CIPHER-KEY-FOR-BOB', $this->rows['fs-1']->getKey());
		$this->assertSame([], $this->sent);
	}

	public function testARevocationThatArrivesRemovesTheShare(): void {
		$response = $this->controller()->destroy('fs-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('SHARE_UNSHARED', $this->sent[0]->getMessage()['notificationType']);
		$this->assertSame([], $this->rows);
		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_REVOKED, $this->audit[0]->getEventType());
		$this->assertIdentifiersOnly();
	}

	public function testOnlyTheOwnerRevokes(): void {
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('mallory')->destroy('fs-1')->getStatus());
		$this->assertSame(FederatedShare::STATUS_ACTIVE, $this->rows['fs-1']->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testAFailedRevocationIsRetriedWithBackoffAndThenFails(): void {
		$this->reachable = false;
		$this->controller()->destroy('fs-1');

		$row = $this->rows['fs-1'];
		$this->assertSame(FederatedShare::STATUS_REVOKED, $row->getStatus());
		$this->assertSame('SHARE_UNSHARED', $row->getPendingNotification());
		$this->assertSame(1, $row->getNotifyAttempts());

		// Nothing is served while the revocation is on its way.
		$this->assertNull($this->service->answerPull('cloud.partner.example', 'fs-1', ['sharedSecret' => self::SHARED_SECRET]));

		// The waits double: 1, 2, 4, 8 minutes after each failure.
		$waits = [];
		for ($i = 0; $i < 4; $i++) {
			$due = clone $row->getNextNotifyAt();
			$this->assertSame(0, $this->delivery->retryDue((clone $due)->modify('-1 second')));
			$waits[] = $due->getTimestamp();
			$this->assertSame(0, $this->delivery->retryDue($due));
		}
		$this->assertSame([120, 240, 480], [$waits[1] - $waits[0], $waits[2] - $waits[1], $waits[3] - $waits[2]]);
		$this->assertSame(5, $row->getNotifyAttempts());

		// The sixth failure gives up; the owner sees the share failed.
		$this->delivery->retryDue(clone $row->getNextNotifyAt());
		$this->assertSame(FederatedShare::STATUS_FAILED, $row->getStatus());
		$this->assertNull($row->getNextNotifyAt());
		$this->assertSame(6, count($this->sent));
		$last = end($this->audit);
		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_FAILED, $last->getEventType());
		$this->assertSame(['federatedShareId' => 'fs-1', 'recipientCloudId' => 'bob@cloud.partner.example', 'partnerId' => 'p-cloud.partner.example', 'notification' => 'SHARE_UNSHARED'], $last->getMetadata());
		$this->assertIdentifiersOnly();
	}

	public function testARetryThatArrivesCompletesTheRevocation(): void {
		$this->reachable = false;
		$this->controller()->destroy('fs-1');
		$this->reachable = true;

		$this->assertSame(1, $this->delivery->retryDue(clone $this->rows['fs-1']->getNextNotifyAt()));
		$this->assertSame([], $this->rows);
	}

	public function testTheOwnerSuspendsAShareWhoseCertificateNoLongerVerifies(): void {
		$response = $this->controller()->suspend('fs-1', 'untrusted_root');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('suspended', $response->getData()['status']);
		$this->assertNull($this->service->answerPull('cloud.partner.example', 'fs-1', ['sharedSecret' => self::SHARED_SECRET]));
		$this->assertSame(['federatedShareId' => 'fs-1', 'recipientCloudId' => 'bob@cloud.partner.example', 'partnerId' => 'p-cloud.partner.example', 'reason' => 'untrusted_root'], $this->audit[0]->getMetadata());
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller('mallory')->suspend('fs-2', 'x')->getStatus());
	}

	public function testRemovingThePartnerSuspendsItsShares(): void {
		$partners = $this->createMock(FederationPartnerService::class);
		$partners->expects($this->once())->method('remove')->with('p-cloud.partner.example');
		$root = new FederationRootService($this->caMapper());
		$session = $this->createMock(IUserSession::class);
		$controller = new FederationPartnerController($this->createMock(IRequest::class), $partners, $root, $session, $this->service);

		$controller->destroy('p-cloud.partner.example');

		$this->assertSame(FederatedShare::STATUS_SUSPENDED, $this->rows['fs-1']->getStatus());
		$this->assertNull($this->audit[0]->getActorId());
		$this->assertSame('partner_removed', $this->audit[0]->getMetadata()['reason']);
		$this->assertIdentifiersOnly();
	}
}
