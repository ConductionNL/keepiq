<?php

/**
 * A recipient who restores a declined read-only copy from the trash takes the
 * share back: the inbound share is accepted again, the owner's instance gets
 * OCM `SHARE_ACCEPTED` and its share leaves "declined", and the copy pulls the
 * current value. When the owner revoked the share meanwhile, the copy comes
 * back read-only and the restore says the share has ended (decision of
 * 4 Oct 2026, keepiq#789).
 *
 * Bob's side runs through SecretTrashService, the service behind
 * POST /api/v1/secrets/{id}/restore. His OCM notification is handed to
 * Alice's registered provider, as Nextcloud would after checking the
 * signature, and her provider's answer becomes the HTTP status Bob's
 * instance sees (201, or 400 for "share not found").
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
 * @spec openspec/specs/federated-sharing/spec.md#scenario-bob-restores-his-copy
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Federation\KeepiqSecretFederationProvider;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\FederatedCopyDeclineService;
use OCA\Keepiq\Service\FederatedCopyService;
use OCA\Keepiq\Service\FederatedDeclineReceiver;
use OCA\Keepiq\Service\FederatedRemoteChangeService;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedSharePuller;
use OCA\Keepiq\Service\FederatedShareReceiver;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\SecretService;
use OCA\Keepiq\Service\SecretSharingRevoker;
use OCA\Keepiq\Service\SecretTrashService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\OCM\Exceptions\OCMProviderException;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Security\Signature\IIncomingSignedRequest;
use OCP\Share\Exceptions\ShareNotFound;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedCopyRestoreTest extends TestCase {
	use FederationFixtures;

	private const SHARE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const SHARED_SECRET = 'the-shared-secret';

	private FederatedInbound $inbound;

	private Secret $copy;

	private ?FederatedShare $outbound;

	/** @var FederatedShare[] Alice's other shares of the same secret */
	private array $otherOutbound = [];

	/** @var FakeCloudFederationNotification[] */
	private array $sent = [];

	/** @var AuditEvent[] */
	private array $audit = [];

	private int $pulls = 0;

	/** @var string|null The verified signer of the request Alice receives */
	private ?string $signer = 'cloud.partner.example';

	/** Whether Alice's instance cannot be reached. */
	private bool $aliceDown = false;

	protected function setUp(): void {
		$this->inbound = new FederatedInbound();
		$this->inbound->setId('in-1');
		$this->inbound->setRecipientUid('bob');
		$this->inbound->setSenderCloudId('alice@cloud.city.example');
		$this->inbound->setPartnerId('p-cloud.city.example');
		$this->inbound->setRemoteShareId(self::SHARE_ID);
		$this->inbound->setName('Supplier portal');
		$this->inbound->setSharedSecretEnc('enc(' . self::SHARED_SECRET . ')');
		// Bob trashed the copy: the share is declined, the link to the copy kept.
		$this->inbound->setSecretId('copy-1');
		$this->inbound->setStatus(FederatedInbound::STATUS_DECLINED);

		$this->copy = new Secret();
		$this->copy->setId('copy-1');
		$this->copy->setName('Supplier portal');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->copy->setReadOnly(true);
		$this->copy->setFederatedSource('alice@cloud.city.example');
		$this->copy->setKey('OLD-CIPHER-FOR-BOB');
		$this->copy->setTrashedAt(new DateTime('2026-10-04T10:00:00Z'));

		$this->outbound = new FederatedShare();
		$this->outbound->setId(self::SHARE_ID);
		$this->outbound->setSourceSecretId('src');
		$this->outbound->setOwnerId('alice');
		$this->outbound->setRecipientCloudId('bob@cloud.partner.example');
		$this->outbound->setPartnerId('p-cloud.partner.example');
		$this->outbound->setRecipientCertFingerprint(str_repeat('a', 64));
		$this->outbound->setKey('NEW-CIPHER-FOR-BOB');
		$this->outbound->setSharedSecretHash(hash('sha256', self::SHARED_SECRET));
		$this->outbound->setStatus(FederatedShare::STATUS_DECLINED);
		$this->outbound->setNotifyAttempts(0);
	}

	private function auditTrail(): FederatedShareAuditTrail {
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->audit[] = $event;
			}
		);

		return new FederatedShareAuditTrail($dispatcher);
	}

	private function crypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => substr($c, 4, -1));

		return $crypto;
	}

	private function partners(): FederationPartnerService {
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$mapper = $this->partnerMapper([
			$this->partner('cloud.city.example', true, true),
			$this->partner('cloud.partner.example', true, true),
			$this->partner('cloud.other.example', true, true),
		]);
		$mapper->method('findById')->willReturnCallback(fn (string $id) => $this->partner(substr($id, 2), true, true));

		return new FederationPartnerService($mapper, $this->createMock(IClientService::class), $config);
	}

	/**
	 * Alice's Nextcloud receiving a notification: her registered provider
	 * answers, and "share not found" becomes a 400 as in
	 * cloud_federation_api's RequestHandlerController.
	 */
	private function ocmToAlice(): ICloudFederationProviderManager {
		$ocm = $this->createMock(ICloudFederationProviderManager::class);
		$ocm->method('sendCloudNotification')->willReturnCallback(
			function (string $remote, FakeCloudFederationNotification $notification): IResponse {
				$this->assertSame('cloud.city.example', $remote);
				$this->sent[] = $notification;
				if ($this->aliceDown === true) {
					throw new OCMProviderException('cURL error 7: connection refused');
				}

				$message = $notification->getMessage();
				$status = 201;
				try {
					$this->alicesProvider()->notificationReceived($message['notificationType'], $message['providerId'], $message['notification']);
				} catch (ShareNotFound) {
					$status = 400;
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				return $response;
			}
		);

		return $ocm;
	}

	/**
	 * Alice's instance serving the pull: the current ciphertext while her
	 * share is active, else "share not found".
	 */
	private function pullFromAlice(): IOCMDiscoveryService {
		$ocm = $this->createMock(IOCMDiscoveryService::class);
		$ocm->method('requestRemoteOcmEndpoint')->willReturnCallback(
			function (): IResponse {
				$this->pulls++;
				$live = $this->outbound !== null && $this->outbound->getStatus() === FederatedShare::STATUS_ACTIVE;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($live ? 200 : 404);
				$response->method('getBody')->willReturn(
					$live ? json_encode([
						'name' => 'Supplier portal (new)',
						'key' => $this->outbound->getKey(),
						'recipientCloudId' => 'bob@cloud.here.example',
					]) : ''
				);
				return $response;
			}
		);

		return $ocm;
	}

	private function bobsDeclines(): FederatedCopyDeclineService {
		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());

		$inbound = $this->createMock(FederatedInboundMapper::class);
		$inbound->method('findBySecretId')->willReturnCallback(
			fn (string $secretId): array => ($this->inbound->getSecretId() === $secretId) ? [$this->inbound] : []
		);
		$inbound->method('update')->willReturnArgument(0);

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($id === $this->copy->getId()) ? $this->copy : throw new DoesNotExistException('none')
		);
		$secrets->method('update')->willReturnArgument(0);

		$crypto = $this->crypto();
		$partnerMapper = $this->partnerMapper([$this->partner('cloud.city.example', true, true)]);
		$partnerMapper->method('findById')->willReturn($this->partner('cloud.city.example', true, true));

		return new FederatedCopyDeclineService(
			inboundMapper: $inbound,
			crypto: $crypto,
			providerManager: $this->ocmToAlice(),
			factory: $factory,
			cloudIdManager: $this->cloudIdManager(),
			audit: $this->auditTrail(),
			copies: new FederatedCopyService(
				puller: new FederatedSharePuller($partnerMapper, $this->pullFromAlice(), $crypto, $this->cloudIdManager()),
				secretMapper: $secrets,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				typeService: $this->createMock(SecretTypeService::class),
			),
		);
	}

	/**
	 * Bob's trash, with the real decline service behind it.
	 */
	private function bobsTrash(Secret $secret): SecretTrashService {
		$secrets = $this->createMock(SecretService::class);
		$secrets->method('findOwned')->willReturn($secret);

		return new SecretTrashService(
			mapper: $this->createMock(SecretMapper::class),
			secretService: $secrets,
			sharingRevoker: $this->createMock(SecretSharingRevoker::class),
			auditService: $this->createMock(AuditService::class),
			logger: $this->createMock(LoggerInterface::class),
			federatedDeclines: $this->bobsDeclines(),
		);
	}

	private function signedBy(): IOCMDiscoveryService {
		$ocm = $this->createMock(IOCMDiscoveryService::class);
		$ocm->method('getIncomingSignedRequest')->willReturnCallback(
			function () {
				if ($this->signer === null) {
					return null;
				}
				$signed = $this->createMock(IIncomingSignedRequest::class);
				$signed->method('getOrigin')->willReturn($this->signer);
				return $signed;
			}
		);

		return $ocm;
	}

	/**
	 * Alice's OCM provider (the owner's side).
	 */
	private function alicesProvider(): KeepiqSecretFederationProvider {
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('findById')->willReturnCallback(
			fn (string $id): FederatedShare => ($this->outbound !== null && $id === self::SHARE_ID) ? $this->outbound : throw new DoesNotExistException('none')
		);
		$shares->method('findBySourceSecret')->willReturnCallback(
			fn (string $sourceId): array => array_values(array_filter(
				array_merge($this->outbound === null ? [] : [$this->outbound], $this->otherOutbound),
				static fn (FederatedShare $row): bool => $row->getSourceSecretId() === $sourceId
			))
		);
		$shares->method('update')->willReturnArgument(0);

		$changes = $this->createMock(FederatedRemoteChangeService::class);
		$changes->method('senderOf')->willReturn('');
		$changes->expects($this->never())->method('handle');

		return new KeepiqSecretFederationProvider(
			$this->createMock(FederatedShareReceiver::class),
			$changes,
			new FederatedDeclineReceiver(
				shareMapper: $shares,
				partners: $this->partners(),
				ocmDiscovery: $this->signedBy(),
				audit: $this->auditTrail(),
			),
		);
	}

	/**
	 * The audit entries carry identifiers only.
	 */
	private function assertIdentifiersOnly(): void {
		$this->assertNotSame([], $this->audit);
		foreach ($this->audit as $event) {
			$entry = json_encode([$event->getObjectId(), $event->getObjectName(), $event->getMetadata()]);
			foreach (['NEW-CIPHER-FOR-BOB', 'OLD-CIPHER-FOR-BOB', self::SHARED_SECRET, hash('sha256', self::SHARED_SECRET)] as $secret) {
				$this->assertStringNotContainsString($secret, $entry);
			}
			$this->assertArrayHasKey($event->getEventType(), AuditEventTypes::WHITELIST);
			$this->assertSame([], array_diff(array_keys($event->getMetadata()), AuditEventTypes::WHITELIST[$event->getEventType()]));
		}
	}

	/**
	 * The one notification Bob's instance sent.
	 *
	 * @return array<string,mixed>
	 */
	private function theAcceptSent(): array {
		$this->assertCount(1, $this->sent);
		$message = $this->sent[0]->getMessage();
		$this->assertSame('SHARE_ACCEPTED', $message['notificationType']);
		$this->assertSame('keepiq-secret', $message['resourceType']);
		$this->assertSame(self::SHARE_ID, $message['providerId']);
		$this->assertSame(self::SHARE_ID, $message['notification']['providerId']);
		// The secret itself: Alice's instance holds only its hash.
		$this->assertSame(self::SHARED_SECRET, $message['notification']['sharedSecret']);
		$this->assertStringNotContainsString('CIPHER', json_encode($message));

		return $message;
	}

	public function testRestoringADeclinedCopyTakesTheShareBackAndPullsTheCurrentValue(): void {
		$result = $this->bobsTrash($this->copy)->restore('copy-1', 'bob');

		$this->assertSame('resumed', $result['federatedShare']);
		$this->assertSame($this->copy, $result['secret']);
		$this->assertNull($this->copy->getTrashedAt());
		$this->theAcceptSent();

		// Bob's share is accepted again and the copy carries the current value.
		$this->assertSame(FederatedInbound::STATUS_ACCEPTED, $this->inbound->getStatus());
		$this->assertSame('copy-1', $this->inbound->getSecretId());
		$this->assertSame(1, $this->pulls);
		$this->assertSame('NEW-CIPHER-FOR-BOB', $this->copy->getKey());
		$this->assertSame('Supplier portal (new)', $this->copy->getName());
		$this->assertTrue($this->copy->getReadOnly());

		// Alice's share left "declined" and gets her changes again.
		$this->assertSame(FederatedShare::STATUS_ACTIVE, $this->outbound->getStatus());

		$types = array_map(static fn (AuditEvent $event): string => $event->getEventType(), $this->audit);
		$this->assertContains(AuditEventTypes::FEDERATED_SHARE_RECIPIENT_RESUMED, $types);
		$this->assertContains(AuditEventTypes::FEDERATED_SHARE_ACCEPTED, $types);
		$this->assertIdentifiersOnly();
	}

	public function testTheOwnersShareResumesWithNoRetriesLeftOver(): void {
		$this->outbound->setPendingNotification('SHARE_UPDATED');
		$this->outbound->setNotifyAttempts(3);
		$this->outbound->setNextNotifyAt(new DateTime('+5 minutes'));

		$result = $this->alicesProvider()->notificationReceived(
			'SHARE_ACCEPTED',
			self::SHARE_ID,
			['sharedSecret' => self::SHARED_SECRET, 'providerId' => self::SHARE_ID],
		);

		$this->assertSame([], $result);
		$this->assertSame(FederatedShare::STATUS_ACTIVE, $this->outbound->getStatus());
		$this->assertNull($this->outbound->getPendingNotification());
		$this->assertSame(0, $this->outbound->getNotifyAttempts());
		$this->assertNull($this->outbound->getNextNotifyAt());
		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_RECIPIENT_RESUMED, $this->audit[0]->getEventType());
		$this->assertNull($this->audit[0]->getActorId());
		$this->assertIdentifiersOnly();
	}

	public function testTheProviderNamesTheRecipientAsTheSignerOfAnAccept(): void {
		$this->assertSame(
			'bob@cloud.partner.example',
			$this->alicesProvider()->getFederationIdFromSharedSecret(self::SHARED_SECRET, ['providerId' => self::SHARE_ID])
		);
	}

	public function testAShareTheOwnerRevokedMeanwhileStaysEnded(): void {
		$this->outbound->setStatus(FederatedShare::STATUS_REVOKED);
		$this->outbound->setPendingNotification('SHARE_UNSHARED');

		$result = $this->bobsTrash($this->copy)->restore('copy-1', 'bob');

		$this->assertSame('ended', $result['federatedShare']);
		$this->theAcceptSent();
		// The copy is back, read-only, as it was: no access is invented.
		$this->assertNull($this->copy->getTrashedAt());
		$this->assertTrue($this->copy->getReadOnly());
		$this->assertSame('OLD-CIPHER-FOR-BOB', $this->copy->getKey());
		$this->assertSame(0, $this->pulls);
		$this->assertSame(FederatedInbound::STATUS_REVOKED, $this->inbound->getStatus());
		$this->assertSame(FederatedShare::STATUS_REVOKED, $this->outbound->getStatus());
		$this->assertSame('SHARE_UNSHARED', $this->outbound->getPendingNotification());
	}

	public function testAShareTheOwnerAlreadyRemovedStaysEnded(): void {
		$this->outbound = null;

		$result = $this->bobsTrash($this->copy)->restore('copy-1', 'bob');

		$this->assertSame('ended', $result['federatedShare']);
		$this->assertSame(0, $this->pulls);
		$this->assertSame('OLD-CIPHER-FOR-BOB', $this->copy->getKey());
		$this->assertSame(FederatedInbound::STATUS_REVOKED, $this->inbound->getStatus());
	}

	public function testARevocationBobAlreadyReceivedEndsItWithoutAsking(): void {
		$this->inbound->setStatus(FederatedInbound::STATUS_REVOKED);

		$result = $this->bobsTrash($this->copy)->restore('copy-1', 'bob');

		$this->assertSame('ended', $result['federatedShare']);
		$this->assertSame([], $this->sent);
		$this->assertSame(0, $this->pulls);
		$this->assertSame(FederatedShare::STATUS_DECLINED, $this->outbound->getStatus());
	}

	public function testAShareTheOwnerSentAgainIsNotRevivedTwice(): void {
		// After the decline Alice shared the secret with Bob once more.
		$again = new FederatedShare();
		$again->setId('6d1f6c1e-0000-4000-8000-000000000002');
		$again->setSourceSecretId('src');
		$again->setRecipientCloudId('bob@cloud.partner.example');
		$again->setStatus(FederatedShare::STATUS_ACTIVE);
		$this->otherOutbound = [$again];

		$result = $this->bobsTrash($this->copy)->restore('copy-1', 'bob');

		$this->assertSame('ended', $result['federatedShare']);
		$this->assertSame(FederatedShare::STATUS_DECLINED, $this->outbound->getStatus());
		$this->assertSame(0, $this->pulls);
	}

	public function testAnOwnerWhoCannotBeReachedLeavesTheShareDeclined(): void {
		$this->aliceDown = true;

		$result = $this->bobsTrash($this->copy)->restore('copy-1', 'bob');

		$this->assertSame('unreachable', $result['federatedShare']);
		$this->assertNull($this->copy->getTrashedAt());
		$this->assertSame(FederatedInbound::STATUS_DECLINED, $this->inbound->getStatus());
		$this->assertSame(0, $this->pulls);
		$this->assertSame('OLD-CIPHER-FOR-BOB', $this->copy->getKey());
	}

	public function testRestoringAnOrdinarySecretTellsNoOne(): void {
		$own = new Secret();
		$own->setId('copy-1');
		$own->setOwnerType('user');
		$own->setOwnerId('bob');
		$own->setReadOnly(false);
		$own->setTrashedAt(new DateTime());

		$result = $this->bobsTrash($own)->restore('copy-1', 'bob');

		$this->assertNull($result['federatedShare']);
		$this->assertSame([], $this->sent);
		$this->assertSame(FederatedInbound::STATUS_DECLINED, $this->inbound->getStatus());
	}

	public function testTrashKeepsTheLinkToTheCopyAndPurgeDropsIt(): void {
		$this->inbound->setStatus(FederatedInbound::STATUS_ACCEPTED);
		$this->copy->setTrashedAt(null);
		$this->aliceDown = true;
		$trash = $this->bobsTrash($this->copy);

		$trash->trash('copy-1', 'bob');
		$this->assertSame(FederatedInbound::STATUS_DECLINED, $this->inbound->getStatus());
		$this->assertSame('copy-1', $this->inbound->getSecretId());

		$trash->purge('copy-1', 'bob');
		$this->assertNull($this->inbound->getSecretId());
		$this->assertSame(FederatedInbound::STATUS_DECLINED, $this->inbound->getStatus());
	}

	/**
	 * @return array<string,array{0:string|null,1:string}>
	 */
	public static function refusedAccepts(): array {
		return [
			'unsigned' => [null, self::SHARED_SECRET],
			'another partner' => ['cloud.other.example', self::SHARED_SECRET],
			'the hash instead of the secret' => ['cloud.partner.example', hash('sha256', self::SHARED_SECRET)],
			'wrong secret' => ['cloud.partner.example', 'guess'],
		];
	}

	/**
	 * @dataProvider refusedAccepts
	 */
	public function testARefusedAcceptChangesNothing(?string $signer, string $secret): void {
		$this->signer = $signer;

		try {
			$this->alicesProvider()->notificationReceived('SHARE_ACCEPTED', self::SHARE_ID, ['sharedSecret' => $secret, 'providerId' => self::SHARE_ID]);
			$this->fail('the accept was applied');
		} catch (ShareNotFound) {
			// The one answer for every refusal.
		}

		$this->assertSame(FederatedShare::STATUS_DECLINED, $this->outbound->getStatus());
		$this->assertSame([], $this->audit);
	}

	public function testAnAcceptForALiveShareChangesNothing(): void {
		$this->outbound->setStatus(FederatedShare::STATUS_ACTIVE);

		$this->alicesProvider()->notificationReceived('SHARE_ACCEPTED', self::SHARE_ID, ['sharedSecret' => self::SHARED_SECRET, 'providerId' => self::SHARE_ID]);

		$this->assertSame(FederatedShare::STATUS_ACTIVE, $this->outbound->getStatus());
		$this->assertSame([], $this->audit);
	}
}
