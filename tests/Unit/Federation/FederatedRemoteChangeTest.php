<?php

/**
 * Bob's instance applies Alice's update and revocation, only from Alice's
 * partner with the share's own secret (sharing-federated-recipients tasks
 * 4.1 to 4.3).
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-owner-updates-reach-the-remote-copy-and-revocation-removes-it
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Federation\KeepiqSecretFederationProvider;
use OCA\Keepiq\Service\FederatedCopyService;
use OCA\Keepiq\Service\FederatedRemoteChangeService;
use OCA\Keepiq\Service\FederatedShareAuditTrail;
use OCA\Keepiq\Service\FederatedSharePuller;
use OCA\Keepiq\Service\FederatedShareReceiver;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Security\Signature\IIncomingSignedRequest;
use OCP\Share\Exceptions\ShareNotFound;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';

class FederatedRemoteChangeTest extends TestCase {
	use FederationFixtures;

	private const REMOTE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const SHARED_SECRET = 'the-shared-secret';

	private FederatedInbound $row;

	private ?Secret $copy;

	/** @var AuditEvent[] */
	private array $audit = [];

	private int $pulls = 0;

	private IOCMDiscoveryService&MockObject $ocm;

	/** @var array<int,string|null> The OCM addresses the signature check was asked with */
	private array $askedWith = [];

	protected function setUp(): void {
		$this->row = new FederatedInbound();
		$this->row->setId('in-1');
		$this->row->setRecipientUid('bob');
		$this->row->setSenderCloudId('alice@cloud.city.example');
		$this->row->setPartnerId('p-cloud.city.example');
		$this->row->setRemoteShareId(self::REMOTE_ID);
		$this->row->setName('Supplier portal');
		$this->row->setSharedSecretEnc('enc(' . self::SHARED_SECRET . ')');
		$this->row->setSecretId('copy-1');
		$this->row->setStatus(FederatedInbound::STATUS_ACCEPTED);

		$this->copy = new Secret();
		$this->copy->setId('copy-1');
		$this->copy->setName('Supplier portal');
		$this->copy->setOwnerType('user');
		$this->copy->setOwnerId('bob');
		$this->copy->setReadOnly(true);
		$this->copy->setKey('OLD-CIPHER-KEY-FOR-BOB');
		$this->copy->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
	}

	/**
	 * Bob's provider; the request is signed by $signer (null: unsigned).
	 *
	 * @param string|null $signer The signer origin
	 *
	 * @return KeepiqSecretFederationProvider
	 */
	private function bob(?string $signer): KeepiqSecretFederationProvider {
		$this->ocm = $this->createMock(IOCMDiscoveryService::class);
		$signed = null;
		if ($signer !== null) {
			$signed = $this->createMock(IIncomingSignedRequest::class);
			$signed->method('getOrigin')->willReturn($signer);
		}
		$this->ocm->method('getIncomingSignedRequest')->willReturnCallback(
			function (?string $address = null) use ($signed) {
				$this->askedWith[] = $address;
				return $signed;
			}
		);
		$this->ocm->method('requestRemoteOcmEndpoint')->willReturnCallback(
			function (): IResponse {
				$this->pulls++;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn(200);
				$response->method('getBody')->willReturn(json_encode([
					'recipientCloudId' => 'bob@cloud.here.example',
					'name' => 'Supplier portal (new)',
					'key' => 'NEW-CIPHER-KEY-FOR-BOB',
					'login' => 'NEW-CIPHER-LOGIN',
				]));
				return $response;
			}
		);

		$inbound = $this->createMock(FederatedInboundMapper::class);
		$inbound->method('findByRemoteShareId')->willReturnCallback(
			fn (string $remoteId): array => ($remoteId === self::REMOTE_ID) ? [$this->row] : []
		);
		$inbound->method('update')->willReturnArgument(0);

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($this->copy !== null && $id === $this->copy->getId()) ? $this->copy : throw new DoesNotExistException('none')
		);
		$secrets->method('update')->willReturnArgument(0);
		$secrets->method('delete')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->copy = null;
				return $secret;
			}
		);

		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => substr($c, 4, -1));
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$partnerMapper = $this->partnerMapper([$this->partner('cloud.city.example', true, true), $this->partner('cloud.other.example', true, true)]);
		$partnerMapper->method('findById')->willReturnCallback(
			fn (string $id) => $this->partner(substr($id, 2), true, true)
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->audit[] = $event;
			}
		);
		$audit = new FederatedShareAuditTrail($dispatcher);

		$changes = new FederatedRemoteChangeService(
			inboundMapper: $inbound,
			partners: new FederationPartnerService($partnerMapper, $this->createMock(IClientService::class), $config),
			ocmDiscovery: $this->ocm,
			crypto: $crypto,
			copies: new FederatedCopyService(
				puller: new FederatedSharePuller($partnerMapper, $this->ocm, $crypto, $this->cloudIdManager()),
				secretMapper: $secrets,
				suiteMapper: $this->createMock(EncryptionSuiteMapper::class),
				typeService: $this->createMock(SecretTypeService::class),
			),
			audit: $audit,
		);

		return new KeepiqSecretFederationProvider($this->createMock(FederatedShareReceiver::class), $changes);
	}

	/**
	 * The audit entries carry identifiers only.
	 */
	private function assertIdentifiersOnly(): void {
		$this->assertNotSame([], $this->audit);
		foreach ($this->audit as $event) {
			$entry = json_encode([$event->getObjectId(), $event->getObjectName(), $event->getMetadata()]);
			foreach (['OLD-CIPHER-KEY-FOR-BOB', 'NEW-CIPHER-KEY-FOR-BOB', 'NEW-CIPHER-LOGIN', self::SHARED_SECRET, hash('sha256', self::SHARED_SECRET)] as $secret) {
				$this->assertStringNotContainsString($secret, $entry);
			}
			$this->assertSame([], array_diff(array_keys($event->getMetadata()), AuditEventTypes::WHITELIST[$event->getEventType()]));
		}
	}

	private function notification(string $secret = self::SHARED_SECRET, string $remoteId = self::REMOTE_ID): array {
		return ['sharedSecret' => hash('sha256', $secret), 'providerId' => $remoteId, 'sender' => 'alice@cloud.city.example', 'message' => 'x'];
	}

	/**
	 * Nextcloud 35 learns whose signature a notification carries only from
	 * the provider: the sender of the share the shared secret belongs to.
	 */
	public function testTheProviderNamesTheSenderOfTheShareTheSecretBelongsTo(): void {
		$provider = $this->bob('cloud.city.example');

		$this->assertInstanceOf(\OCP\Federation\ISignedCloudFederationProvider::class, $provider);
		$this->assertSame('alice@cloud.city.example', $provider->getFederationIdFromSharedSecret(hash('sha256', self::SHARED_SECRET), $this->notification()));
		$this->assertSame('', $provider->getFederationIdFromSharedSecret(hash('sha256', 'guess'), $this->notification('guess')));
		$this->assertSame('', $provider->getFederationIdFromSharedSecret(hash('sha256', self::SHARED_SECRET), ['providerId' => 'other']));
	}

	public function testAnUpdatePullsAgainAndReplacesTheCopy(): void {
		$result = $this->bob('cloud.city.example')->notificationReceived('SHARE_UPDATED', self::REMOTE_ID, $this->notification());

		$this->assertSame([], $result);
		$this->assertSame(['alice@cloud.city.example'], $this->askedWith);
		$this->assertSame(1, $this->pulls);
		$this->assertSame('copy-1', $this->copy->getId());
		$this->assertSame('NEW-CIPHER-KEY-FOR-BOB', $this->copy->getKey());
		$this->assertSame('NEW-CIPHER-LOGIN', $this->copy->getLogin());
		$this->assertSame('Supplier portal (new)', $this->copy->getName());
		$this->assertTrue($this->copy->getReadOnly());
		$this->assertSame(AuditEventTypes::FEDERATED_COPY_UPDATED, $this->audit[0]->getEventType());
		$this->assertIdentifiersOnly();
	}

	public function testARevocationDeletesTheCopy(): void {
		$this->bob('cloud.city.example')->notificationReceived('SHARE_UNSHARED', self::REMOTE_ID, $this->notification());

		$this->assertNull($this->copy);
		$this->assertSame(FederatedInbound::STATUS_REVOKED, $this->row->getStatus());
		$this->assertNull($this->row->getSecretId());
		$this->assertSame(AuditEventTypes::FEDERATED_COPY_REMOVED, $this->audit[0]->getEventType());
		$this->assertSame('copy-1', $this->audit[0]->getMetadata()['copyId']);
		$this->assertIdentifiersOnly();
	}

	public function testAPendingShareIsNotPulledOnUpdate(): void {
		$this->row->setStatus(FederatedInbound::STATUS_PENDING);

		$this->bob('cloud.city.example')->notificationReceived('SHARE_UPDATED', self::REMOTE_ID, $this->notification());

		$this->assertSame(0, $this->pulls);
	}

	/**
	 * @return array<string,array{0:string|null,1:string,2:string}>
	 */
	public static function refusals(): array {
		return [
			'unsigned' => [null, self::SHARED_SECRET, self::REMOTE_ID],
			'another partner' => ['cloud.other.example', self::SHARED_SECRET, self::REMOTE_ID],
			'a stranger' => ['cloud.evil.example', self::SHARED_SECRET, self::REMOTE_ID],
			'wrong secret' => ['cloud.city.example', 'guess', self::REMOTE_ID],
			'unknown share' => ['cloud.city.example', self::SHARED_SECRET, '6d1f6c1e-0000-4000-8000-0000000000ff'],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusedNotificationChangesNothing(?string $signer, string $secret, string $remoteId): void {
		foreach (['SHARE_UPDATED', 'SHARE_UNSHARED'] as $type) {
			try {
				$this->bob($signer)->notificationReceived($type, $remoteId, $this->notification($secret));
				$this->fail('the notification was applied');
			} catch (ShareNotFound) {
				// The one answer for every refusal.
			}
		}

		$this->assertSame(0, $this->pulls);
		$this->assertNotNull($this->copy);
		$this->assertSame('OLD-CIPHER-KEY-FOR-BOB', $this->copy->getKey());
		$this->assertSame(FederatedInbound::STATUS_ACCEPTED, $this->row->getStatus());
		$this->assertSame([], $this->audit);
	}
}
