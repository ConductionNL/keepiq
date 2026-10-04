<?php

/**
 * A recipient who deletes the read-only copy they accepted declines the
 * share, and the owner's instance learns it at once
 * (sharing-federated-recipients task 4.4, decision of 4 Oct 2026).
 *
 * Bob's side runs through SecretTrashService, the service behind
 * DELETE /api/v1/secrets/{id} and DELETE /api/v1/secrets/{id}/purge; Alice's
 * side through the registered OCM provider, as Nextcloud calls it.
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-deletes-his-copy
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
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Security\Signature\IIncomingSignedRequest;
use OCP\Share\Exceptions\ShareNotFound;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedCopyDeclineTest extends TestCase {
	use FederationFixtures;

	private const SHARE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const SHARED_SECRET = 'the-shared-secret';

	private FederatedInbound $inbound;

	private Secret $copy;

	private FederatedShare $outbound;

	/** @var FakeCloudFederationNotification[] */
	private array $sent = [];

	/** @var string[] */
	private array $sentTo = [];

	/** @var AuditEvent[] */
	private array $audit = [];

	private int $pulls = 0;

	/** @var string|null The verified signer of the incoming request */
	private ?string $signer = 'cloud.city.example';

	protected function setUp(): void {
		$this->inbound = new FederatedInbound();
		$this->inbound->setId('in-1');
		$this->inbound->setRecipientUid('bob');
		$this->inbound->setSenderCloudId('alice@cloud.city.example');
		$this->inbound->setPartnerId('p-cloud.city.example');
		$this->inbound->setRemoteShareId(self::SHARE_ID);
		$this->inbound->setName('Supplier portal');
		$this->inbound->setSharedSecretEnc('enc(' . self::SHARED_SECRET . ')');
		$this->inbound->setSecretId('copy-1');
		$this->inbound->setStatus(FederatedInbound::STATUS_ACCEPTED);

		$this->copy = new Secret();
		$this->copy->setId('copy-1');
		$this->copy->setName('Supplier portal');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->copy->setReadOnly(true);
		$this->copy->setFederatedSource('alice@cloud.city.example');
		$this->copy->setKey('CIPHER-KEY-FOR-BOB');

		$this->outbound = new FederatedShare();
		$this->outbound->setId(self::SHARE_ID);
		$this->outbound->setSourceSecretId('src');
		$this->outbound->setOwnerId('alice');
		$this->outbound->setRecipientCloudId('bob@cloud.partner.example');
		$this->outbound->setPartnerId('p-cloud.partner.example');
		$this->outbound->setRecipientCertFingerprint(str_repeat('a', 64));
		$this->outbound->setKey('CIPHER-KEY-FOR-BOB');
		$this->outbound->setSharedSecretHash(hash('sha256', self::SHARED_SECRET));
		$this->outbound->setStatus(FederatedShare::STATUS_ACTIVE);
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

	private function ocmSender(): ICloudFederationProviderManager {
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

		return $ocm;
	}

	private function crypto(): ICrypto {
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => substr($c, 4, -1));

		return $crypto;
	}

	private function inboundMapper(): FederatedInboundMapper {
		$mapper = $this->createMock(FederatedInboundMapper::class);
		$mapper->method('findBySecretId')->willReturnCallback(
			fn (string $secretId): array => ($this->inbound->getSecretId() === $secretId) ? [$this->inbound] : []
		);
		$mapper->method('findByRemoteShareId')->willReturnCallback(
			fn (string $remoteId): array => ($remoteId === self::SHARE_ID) ? [$this->inbound] : []
		);
		$mapper->method('update')->willReturnArgument(0);

		return $mapper;
	}

	private function declines(): FederatedCopyDeclineService {
		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());

		return new FederatedCopyDeclineService(
			inboundMapper: $this->inboundMapper(),
			crypto: $this->crypto(),
			providerManager: $this->ocmSender(),
			factory: $factory,
			cloudIdManager: $this->cloudIdManager(),
			audit: $this->auditTrail(),
		);
	}

	/**
	 * Bob's trash, with the real decline service behind it.
	 */
	private function bobsTrash(Secret $secret): SecretTrashService {
		$secrets = $this->createMock(SecretService::class);
		$secrets->method('findOwned')->willReturn($secret);
		$audit = $this->createMock(AuditService::class);

		return new SecretTrashService(
			mapper: $this->createMock(SecretMapper::class),
			secretService: $secrets,
			sharingRevoker: $this->createMock(SecretSharingRevoker::class),
			auditService: $audit,
			logger: $this->createMock(LoggerInterface::class),
			federatedDeclines: $this->declines(),
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
		$ocm->method('requestRemoteOcmEndpoint')->willReturnCallback(
			function (): IResponse {
				$this->pulls++;
				return $this->createMock(IResponse::class);
			}
		);

		return $ocm;
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
	 * Bob's OCM provider (the receiving side).
	 */
	private function bobsProvider(): KeepiqSecretFederationProvider {
		$ocm = $this->signedBy();
		$crypto = $this->crypto();
		$partnerMapper = $this->partnerMapper([$this->partner('cloud.city.example', true, true)]);
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($id === $this->copy->getId()) ? $this->copy : throw new DoesNotExistException('none')
		);
		$changes = new FederatedRemoteChangeService(
			inboundMapper: $this->inboundMapper(),
			partners: $this->partners(),
			ocmDiscovery: $ocm,
			crypto: $crypto,
			copies: new FederatedCopyService(
				puller: new FederatedSharePuller($partnerMapper, $ocm, $crypto, $this->cloudIdManager()),
				secretMapper: $secrets,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				typeService: $this->createMock(SecretTypeService::class),
			),
			audit: $this->auditTrail(),
			declines: $this->declines(),
		);

		return new KeepiqSecretFederationProvider($this->createMock(FederatedShareReceiver::class), $changes);
	}

	/**
	 * Alice's OCM provider (the owner's side).
	 */
	private function alicesProvider(): KeepiqSecretFederationProvider {
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('findById')->willReturnCallback(
			fn (string $id): FederatedShare => ($id === self::SHARE_ID) ? $this->outbound : throw new DoesNotExistException('none')
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
			foreach (['CIPHER-KEY-FOR-BOB', self::SHARED_SECRET, hash('sha256', self::SHARED_SECRET)] as $secret) {
				$this->assertStringNotContainsString($secret, $entry);
			}
			$this->assertSame([], array_diff(array_keys($event->getMetadata()), AuditEventTypes::WHITELIST[$event->getEventType()]));
		}
	}

	private function assertOneDeclineSent(): void {
		$this->assertCount(1, $this->sent);
		$message = $this->sent[0]->getMessage();
		$this->assertSame('SHARE_DECLINED', $message['notificationType']);
		$this->assertSame('keepiq-secret', $message['resourceType']);
		$this->assertSame(self::SHARE_ID, $message['providerId']);
		$this->assertSame(self::SHARE_ID, $message['notification']['providerId']);
		// The secret itself: Alice's instance holds only its hash.
		$this->assertSame(self::SHARED_SECRET, $message['notification']['sharedSecret']);
		$this->assertSame('cloud.city.example', $this->sentTo[0]);
		$this->assertStringNotContainsString('CIPHER-KEY-FOR-BOB', json_encode($message));
	}

	public function testTrashingAnAcceptedCopyDeclinesTheShareAndTellsTheOwner(): void {
		$this->bobsTrash($this->copy)->trash('copy-1', 'bob');

		$this->assertSame(FederatedInbound::STATUS_DECLINED, $this->inbound->getStatus());
		$this->assertNull($this->inbound->getSecretId());
		$this->assertOneDeclineSent();
		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_DECLINED, $this->audit[0]->getEventType());
		$this->assertSame('bob', $this->audit[0]->getActorId());
		$this->assertIdentifiersOnly();
	}

	public function testPurgingACopyThatIsStillAcceptedTellsTheOwner(): void {
		// Trashed before decline existed: the share is still accepted.
		$this->copy->setTrashedAt(new DateTime('2026-10-01T00:00:00Z'));

		$this->bobsTrash($this->copy)->purge('copy-1', 'bob');

		$this->assertSame(FederatedInbound::STATUS_DECLINED, $this->inbound->getStatus());
		$this->assertOneDeclineSent();
	}

	public function testTrashingAnOrdinarySecretTellsNoOne(): void {
		$own = new Secret();
		$own->setId('copy-1');
		$own->setOwnerType('user');
		$own->setOwnerId('bob');
		$own->setReadOnly(false);

		$this->bobsTrash($own)->trash('copy-1', 'bob');

		$this->assertSame(FederatedInbound::STATUS_ACCEPTED, $this->inbound->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testAnotherUsersShareIsLeftAlone(): void {
		$this->inbound->setRecipientUid('carol');

		$this->bobsTrash($this->copy)->trash('copy-1', 'bob');

		$this->assertSame(FederatedInbound::STATUS_ACCEPTED, $this->inbound->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testAnUpdateForADeclinedShareRepeatsTheDeclineAndPullsNothing(): void {
		$this->inbound->setStatus(FederatedInbound::STATUS_DECLINED);
		$this->inbound->setSecretId(null);

		$this->bobsProvider()->notificationReceived(
			'SHARE_UPDATED',
			self::SHARE_ID,
			['sharedSecret' => hash('sha256', self::SHARED_SECRET), 'providerId' => self::SHARE_ID],
		);

		$this->assertSame(0, $this->pulls);
		$this->assertOneDeclineSent();
	}

	public function testTheOwnersShareShowsDeclinedAndStopsRetrying(): void {
		// A change that did not arrive yet is waiting for its retry.
		$this->outbound->setPendingNotification('SHARE_UPDATED');
		$this->outbound->setNotifyAttempts(2);
		$this->outbound->setNextNotifyAt(new DateTime('+5 minutes'));
		$this->signer = 'cloud.partner.example';

		$result = $this->alicesProvider()->notificationReceived(
			'SHARE_DECLINED',
			self::SHARE_ID,
			['sharedSecret' => self::SHARED_SECRET, 'providerId' => self::SHARE_ID],
		);

		$this->assertSame([], $result);
		$this->assertSame(FederatedShare::STATUS_DECLINED, $this->outbound->getStatus());
		$this->assertNull($this->outbound->getPendingNotification());
		$this->assertNull($this->outbound->getNextNotifyAt());
		$this->assertSame(AuditEventTypes::FEDERATED_SHARE_RECIPIENT_DECLINED, $this->audit[0]->getEventType());
		$this->assertNull($this->audit[0]->getActorId());
		$this->assertSame(
			['federatedShareId' => self::SHARE_ID, 'recipientCloudId' => 'bob@cloud.partner.example', 'partnerId' => 'p-cloud.partner.example'],
			$this->audit[0]->getMetadata()
		);
		$this->assertIdentifiersOnly();
	}

	public function testTheProviderNamesTheRecipientAsTheSignerOfADecline(): void {
		$provider = $this->alicesProvider();

		$this->assertSame('bob@cloud.partner.example', $provider->getFederationIdFromSharedSecret(self::SHARED_SECRET, ['providerId' => self::SHARE_ID]));
		// The hash Alice keeps is not the secret.
		$this->assertSame('', $provider->getFederationIdFromSharedSecret(hash('sha256', self::SHARED_SECRET), ['providerId' => self::SHARE_ID]));
		$this->assertSame('', $provider->getFederationIdFromSharedSecret(self::SHARED_SECRET, ['providerId' => 'other']));
	}

	public function testARevocationOnItsWayStaysARevocation(): void {
		$this->outbound->setStatus(FederatedShare::STATUS_REVOKED);
		$this->outbound->setPendingNotification('SHARE_UNSHARED');
		$this->signer = 'cloud.partner.example';

		$this->alicesProvider()->notificationReceived('SHARE_DECLINED', self::SHARE_ID, ['sharedSecret' => self::SHARED_SECRET, 'providerId' => self::SHARE_ID]);

		$this->assertSame(FederatedShare::STATUS_REVOKED, $this->outbound->getStatus());
		$this->assertSame('SHARE_UNSHARED', $this->outbound->getPendingNotification());
	}

	/**
	 * @return array<string,array{0:string|null,1:string,2:string}>
	 */
	public static function refusedDeclines(): array {
		return [
			'unsigned' => [null, self::SHARED_SECRET, self::SHARE_ID],
			'another partner' => ['cloud.other.example', self::SHARED_SECRET, self::SHARE_ID],
			'a stranger' => ['cloud.evil.example', self::SHARED_SECRET, self::SHARE_ID],
			'the hash instead of the secret' => ['cloud.partner.example', hash('sha256', self::SHARED_SECRET), self::SHARE_ID],
			'wrong secret' => ['cloud.partner.example', 'guess', self::SHARE_ID],
			'unknown share' => ['cloud.partner.example', self::SHARED_SECRET, '6d1f6c1e-0000-4000-8000-0000000000ff'],
		];
	}

	/**
	 * @dataProvider refusedDeclines
	 */
	public function testARefusedDeclineChangesNothing(?string $signer, string $secret, string $shareId): void {
		$this->signer = $signer;

		try {
			$this->alicesProvider()->notificationReceived('SHARE_DECLINED', $shareId, ['sharedSecret' => $secret, 'providerId' => $shareId]);
			$this->fail('the decline was applied');
		} catch (ShareNotFound) {
			// The one answer for every refusal.
		}

		$this->assertSame(FederatedShare::STATUS_ACTIVE, $this->outbound->getStatus());
		$this->assertSame([], $this->audit);
	}
}
