<?php

/**
 * A change of only the name or URL of a federated source reaches the remote
 * copies (sharing-federated-recipients task 4.5, decision of 4 Oct 2026): the
 * owner's server sends SHARE_UPDATED to every live federated recipient, with
 * no new ciphertext, and the recipient's server pulls the new name and URL.
 *
 * Runs through PUT /api/v1/secrets/{id} (SecretUpdateController and the real
 * SecretService) with the real FederatedShareService behind it.
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-new-name-reaches-bob
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\SecretUpdateController;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
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
use OCA\Keepiq\Service\LinkShareService;
use OCA\Keepiq\Service\MigrationService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretTypeService;
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
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedSourceDetailsTest extends TestCase {
	use FederationFixtures;

	private Secret $source;

	/** @var FederatedShare[] */
	private array $shares = [];

	/** @var FakeCloudFederationNotification[] */
	private array $sent = [];

	/** @var string[] */
	private array $sentTo = [];

	/** @var AuditEvent[] */
	private array $audit = [];

	protected function setUp(): void {
		$this->source = new Secret();
		$this->source->setId('src');
		$this->source->setName('Supplier portal');
		$this->source->setUrl('https://portal.example');
		$this->source->setKey('ALICE-CIPHER-KEY');
		$this->source->setOwnerType('user');
		$this->source->setOwnerId('alice');
		$this->source->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));

		$this->shares = [
			$this->share('fs-bob', 'bob@cloud.partner.example', FederatedShare::STATUS_ACTIVE),
			$this->share('fs-carol', 'carol@cloud.other.example', FederatedShare::STATUS_ACTIVE),
			$this->share('fs-dave', 'dave@cloud.partner.example', FederatedShare::STATUS_SUSPENDED),
			$this->share('fs-erin', 'erin@cloud.partner.example', FederatedShare::STATUS_DECLINED),
		];
	}

	private function share(string $id, string $recipient, string $status): FederatedShare {
		$row = new FederatedShare();
		$row->setId($id);
		$row->setSourceSecretId('src');
		$row->setOwnerId('alice');
		$row->setRecipientCloudId($recipient);
		$row->setPartnerId('p-' . explode('@', $recipient)[1]);
		$row->setRecipientCertFingerprint(str_repeat('a', 64));
		$row->setKey('CIPHER-KEY-FOR-' . $id);
		$row->setSharedSecretHash(hash('sha256', 'secret-' . $id));
		$row->setStatus($status);
		$row->setNotifyAttempts(0);

		return $row;
	}

	/**
	 * PUT /api/v1/secrets/src as Alice.
	 *
	 * @param array<string,mixed> $params The request body
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function put(array $params) {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturn($this->source);
		$secrets->method('update')->willReturnArgument(0);

		$shareMapper = $this->createMock(FederatedShareMapper::class);
		$shareMapper->method('findBySourceSecret')->willReturnCallback(
			fn (string $id): array => ($id === 'src') ? $this->shares : []
		);
		$shareMapper->method('update')->willReturnArgument(0);

		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());
		$ocm = $this->createMock(ICloudFederationProviderManager::class);
		$ocm->method('sendCloudNotification')->willReturnCallback(
			function (string $remote, FakeCloudFederationNotification $notification): IResponse {
				$this->sent[] = $notification;
				$this->sentTo[] = $remote;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(201);
				return $response;
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (object $event): void {
				if ($event instanceof AuditEvent && str_starts_with($event->getEventType(), 'federated_share.')) {
					$this->audit[] = $event;
				}
			}
		);
		$audit = new FederatedShareAuditTrail($dispatcher);
		$messenger = new FederatedShareMessenger($ocm, $factory, $this->cloudIdManager(), $this->createMock(IUserManager::class));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$federated = new FederatedShareService(
			shareMapper: $shareMapper,
			secretMapper: $secrets,
			partners: new FederationPartnerService(
				$this->partnerMapper([$this->partner('cloud.partner.example', true, true), $this->partner('cloud.other.example', true, true)]),
				$this->createMock(IClientService::class),
				$config
			),
			root: new FederationRootService($this->caMapper()),
			cloudIdManager: $this->cloudIdManager(),
			messenger: $messenger,
			random: $this->createMock(ISecureRandom::class),
			delivery: new FederatedNotificationDelivery($shareMapper, $messenger, $audit),
			audit: $audit,
		);

		$typeService = $this->createMock(SecretTypeService::class);
		$typeService->method('resolveTypeForSecret')->willReturnArgument(0);
		$service = new SecretService(
			mapper: $secrets,
			typeService: $typeService,
			suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
			migrationService: $this->createMock(MigrationService::class),
			linkShareService: $this->createMock(LinkShareService::class),
			logger: $this->createMock(LoggerInterface::class),
			federatedShares: $federated,
		);

		$request = $this->createMock(IRequest::class);
		$request->method('getParam')->willReturnCallback(
			static fn (string $name, mixed $default = null): mixed => array_key_exists($name, $params) ? $params[$name] : $default
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return (new SecretUpdateController($request, $service, $session))->update('src');
	}

	/**
	 * @return string[] The share ids SHARE_UPDATED went out for
	 */
	private function updatedShares(): array {
		$ids = [];
		foreach ($this->sent as $notification) {
			$message = $notification->getMessage();
			$this->assertSame('SHARE_UPDATED', $message['notificationType']);
			$ids[] = $message['providerId'];
		}

		return $ids;
	}

	public function testANewNameTellsEveryLiveRecipientOnce(): void {
		$response = $this->put(['name' => 'Supplier portal (2026)']);

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['fs-bob', 'fs-carol'], $this->updatedShares());
		$this->assertSame(['cloud.partner.example', 'cloud.other.example'], $this->sentTo);
		// No new ciphertext: the stored one stays, and none travels.
		$this->assertSame('CIPHER-KEY-FOR-fs-bob', $this->shares[0]->getKey());
		$this->assertStringNotContainsString('CIPHER-KEY', json_encode(array_map(static fn ($n) => $n->getMessage(), $this->sent)));
		$this->assertCount(2, $this->audit);
		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_UPDATED, $this->audit[0]->getEventType());
		$this->assertSame('alice', $this->audit[0]->getActorId());
	}

	public function testANewUrlTellsEveryLiveRecipient(): void {
		$this->put(['url' => 'https://portal.example/login']);

		$this->assertSame(['fs-bob', 'fs-carol'], $this->updatedShares());
	}

	public function testTheSameNameSentAgainTellsNoOne(): void {
		$this->put(['name' => 'Supplier portal', 'url' => 'https://portal.example']);

		$this->assertSame([], $this->sent);
	}

	public function testAValueChangeLeavesTheNotificationToTheBrowserSync(): void {
		// The browser encrypts the new value for each recipient and its
		// PUT /federated-shares/{id} sends SHARE_UPDATED (task 4.1).
		$this->put(['name' => 'Supplier portal (2026)', 'key' => 'NEW-ALICE-CIPHER']);

		$this->assertSame([], $this->sent);
	}

	public function testFilingTheSourceTellsNoOne(): void {
		$this->put(['typeId' => 'login']);

		$this->assertSame([], $this->sent);
	}
}
