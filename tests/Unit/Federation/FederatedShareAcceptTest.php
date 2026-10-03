<?php

/**
 * Accepting a federated share: Bob's server pulls the ciphertext with the
 * shared secret and stores a read-only copy; the sender answers the pull only
 * to Bob's partner with the right secret (sharing-federated-recipients 3.3).
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-bob-accepts-a-shared-login
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\FederatedInboundController;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Listener\FederationOcmRequestListener;
use OCA\Keepiq\Service\FederatedCertificateService;
use OCA\Keepiq\Service\FederatedCopyService;
use OCA\Keepiq\Service\FederatedInboundService;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedSharePuller;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\NotificationService;
use OCA\Keepiq\Service\SecretTypeService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\OCM\Events\OCMEndpointRequestEvent;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';

class FederatedShareAcceptTest extends TestCase {
	use FederationFixtures;

	private const SHARE_ID = '6d1f6c1e-0000-4000-8000-000000000001';
	private const SHARED_SECRET = 'the-shared-secret';

	/** @var array<string,Secret> Secrets inserted on Bob's side */
	private array $stored = [];

	private FederatedInbound $pending;

	private IOCMDiscoveryService&MockObject $ocm;

	protected function setUp(): void {
		$this->pending = new FederatedInbound();
		$this->pending->setId('in-1');
		$this->pending->setRecipientUid('bob');
		$this->pending->setSenderCloudId('alice@cloud.city.example');
		$this->pending->setPartnerId('p-cloud.city.example');
		$this->pending->setRemoteShareId(self::SHARE_ID);
		$this->pending->setName('Supplier portal');
		$this->pending->setSharedSecretEnc('enc(' . self::SHARED_SECRET . ')');
		$this->pending->setStatus(FederatedInbound::STATUS_PENDING);
	}

	/**
	 * Bob's controller on cloud.here.example, which has cloud.city.example
	 * as an inbound partner. The sender's answer is $answer.
	 *
	 * @param string $uid The signed-in user
	 * @param int $status The sender's HTTP status
	 * @param mixed $answer The sender's JSON answer
	 *
	 * @return FederatedInboundController
	 */
	private function bob(string $uid = 'bob', int $status = 200, mixed $answer = null): FederatedInboundController {
		$answer ??= [
			'shareId' => self::SHARE_ID,
			'recipientCloudId' => 'bob@cloud.here.example',
			'name' => 'Supplier portal',
			'url' => 'https://supplier.example',
			'typeId' => 'type-login',
			'key' => 'CIPHER-KEY-FOR-BOB',
			'login' => 'CIPHER-LOGIN-FOR-BOB',
			'additionalFields' => null,
		];
		$this->ocm = $this->createMock(IOCMDiscoveryService::class);
		$this->ocm->method('requestRemoteOcmEndpoint')->willReturnCallback(
			function () use ($status, $answer): IResponse {
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				$response->method('getBody')->willReturn(json_encode($answer));
				return $response;
			}
		);

		$inbound = $this->createMock(FederatedInboundMapper::class);
		$inbound->method('findById')->willReturnCallback(
			fn (string $id): FederatedInbound => ($id === 'in-1') ? $this->pending : throw new DoesNotExistException('none')
		);
		$inbound->method('update')->willReturnArgument(0);
		$inbound->method('findByRecipient')->willReturnCallback(
			fn (string $uid): array => ($uid === 'bob') ? [$this->pending] : []
		);

		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('insert')->willReturnCallback(
			function (Secret $secret): Secret {
				$this->stored[$secret->getId()] = $secret;
				return $secret;
			}
		);
		$suite = new EncryptionSuite();
		$suite->setId('suite-bob');
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		$suites->method('findActiveByOwner')->willReturn($suite);
		$types = $this->createMock(SecretTypeService::class);
		$types->method('resolveTypeForSecret')->willReturnCallback(static fn (?string $id): string => $id ?? 'type-default');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('decrypt')->willReturnCallback(static fn (string $c): string => substr($c, 4, -1));
		$service = new FederatedInboundService(
			inboundMapper: $inbound,
			copies: new FederatedCopyService(
				puller: new FederatedSharePuller(
					partnerMapper: $this->partnerMapperById([$this->partner('cloud.city.example', false, true)]),
					ocmDiscovery: $this->ocm,
					crypto: $crypto,
					cloudIdManager: $this->cloudIdManager(),
				),
				secretMapper: $secrets,
				suiteMapper: $suites,
				typeService: $types,
			),
			audit: new \OCA\Keepiq\Service\FederatedShareAuditTrail(),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederatedInboundController($this->createMock(IRequest::class), $service, $session);
	}

	/**
	 * The partner fixture, also findable by id.
	 *
	 * @param array $partners The partner rows
	 *
	 * @return \OCA\Keepiq\Db\FederationPartnerMapper
	 */
	private function partnerMapperById(array $partners) {
		$mapper = $this->partnerMapper($partners);
		$mapper->method('findById')->willReturnCallback(
			static function (string $id) use ($partners) {
				foreach ($partners as $partner) {
					if ($partner->getId() === $id) {
						return $partner;
					}
				}
				throw new DoesNotExistException('none');
			}
		);
		return $mapper;
	}

	public function testBobAcceptsAndHisServerStoresAReadOnlyCopy(): void {
		$controller = $this->bob();
		$this->ocm->expects($this->once())->method('requestRemoteOcmEndpoint')->with(
			'keepiq',
			'https://cloud.city.example',
			'keepiq/shares/' . self::SHARE_ID,
			['sharedSecret' => self::SHARED_SECRET, 'sender' => 'bob@cloud.here.example'],
			'post',
		);

		$response = $controller->accept('in-1');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('accepted', $response->getData()['status']);
		$this->assertCount(1, $this->stored);
		$copy = array_values($this->stored)[0];
		$this->assertSame($copy->getId(), $this->pending->getSecretId());
		$this->assertTrue($copy->getReadOnly());
		$this->assertSame('alice@cloud.city.example', $copy->getFederatedSource());
		$this->assertSame('bob', $copy->getOwnerId());
		$this->assertSame('user', $copy->getOwnerType());
		$this->assertSame('suite-bob', $copy->getEncryptionSuiteId());
		$this->assertSame('type-login', $copy->getTypeId());
		$this->assertSame('Supplier portal', $copy->getName());
		$this->assertSame('https://supplier.example', $copy->getUrl());
		$this->assertSame('CIPHER-KEY-FOR-BOB', $copy->getKey());
		$this->assertSame('CIPHER-LOGIN-FOR-BOB', $copy->getLogin());
	}

	public function testAFailedPullStoresNothingAndLeavesTheSharePending(): void {
		foreach ([[404, ['message' => 'Unknown recipient']], [200, ['key' => 'X', 'recipientCloudId' => 'eve@cloud.here.example']], [200, 'garbage']] as [$status, $answer]) {
			$this->pending->setStatus(FederatedInbound::STATUS_PENDING);
			$response = $this->bob('bob', $status, $answer)->accept('in-1');

			$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
			$this->assertSame('pull_failed', $response->getData()['message']);
			$this->assertSame(FederatedInbound::STATUS_PENDING, $this->pending->getStatus());
		}

		$this->assertSame([], $this->stored);
	}

	public function testOnlyBobCanAcceptOrDeclineHisShare(): void {
		$controller = $this->bob('mallory');
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->accept('in-1')->getStatus());
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->decline('in-1')->getStatus());
		$this->assertSame([], $controller->index()->getData());
		$this->assertSame(FederatedInbound::STATUS_PENDING, $this->pending->getStatus());
	}

	public function testDecliningPullsNothing(): void {
		$controller = $this->bob();
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$this->assertSame('declined', $controller->decline('in-1')->getData()['status']);
		// A declined share cannot be accepted after all.
		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->accept('in-1')->getStatus());
		$this->assertSame([], $this->stored);
	}

	public function testBobListsHisIncomingShares(): void {
		$list = $this->bob()->index()->getData();

		$this->assertSame('alice@cloud.city.example', $list[0]['senderCloudId']);
		$this->assertSame('pending', $list[0]['status']);
		$this->assertArrayNotHasKey('sharedSecretEnc', $list[0]);
	}

	/**
	 * Alice's listener on cloud.city.example, holding share SHARE_ID for
	 * bob@cloud.here.example, whose instance is her outbound partner.
	 *
	 * @param string $status The share's status
	 *
	 * @return FederationOcmRequestListener
	 */
	private function alice(string $status = FederatedShare::STATUS_ACTIVE): FederationOcmRequestListener {
		$row = new FederatedShare();
		$row->setId(self::SHARE_ID);
		$row->setSourceSecretId('src');
		$row->setOwnerId('alice');
		$row->setRecipientCloudId('bob@cloud.here.example');
		$row->setPartnerId('p-cloud.here.example');
		$row->setRecipientCertFingerprint(str_repeat('a', 64));
		$row->setKey('CIPHER-KEY-FOR-BOB');
		$row->setSharedSecretHash(hash('sha256', self::SHARED_SECRET));
		$row->setStatus($status);
		$row->setUpdatedAt(new DateTime('2026-10-04T12:00:00Z'));
		$shares = $this->createMock(FederatedShareMapper::class);
		$shares->method('findById')->willReturnCallback(
			static fn (string $id): FederatedShare => ($id === self::SHARE_ID) ? $row : throw new DoesNotExistException('none')
		);
		$source = new Secret();
		$source->setId('src');
		$source->setName('Supplier portal');
		$source->setKey('ALICE-OWN-CIPHERTEXT');
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturn($source);

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$partners = new FederationPartnerService(
			$this->partnerMapper([$this->partner('cloud.here.example', true, false), $this->partner('cloud.other.example', true, true)]),
			$this->createMock(IClientService::class),
			$config
		);

		$service = new FederatedShareService(
			shareMapper: $shares,
			secretMapper: $secrets,
			partners: $partners,
			root: new FederationRootService($this->caMapper()),
			cloudIdManager: $this->cloudIdManager(),
			messenger: $this->createMock(FederatedShareMessenger::class),
			random: $this->createMock(ISecureRandom::class),
			delivery: $this->createMock(\OCA\Keepiq\Service\FederatedNotificationDelivery::class),
			audit: new \OCA\Keepiq\Service\FederatedShareAuditTrail(),
		);

		return new FederationOcmRequestListener($this->createMock(FederatedCertificateService::class), $service);
	}

	/**
	 * Pull through Alice's listener.
	 *
	 * @param FederationOcmRequestListener $listener Alice's listener
	 * @param string|null $signer The verified signer
	 * @param string $secret The presented shared secret
	 * @param string $shareId The share id in the path
	 *
	 * @return array{0:int,1:mixed}
	 */
	private function pull(FederationOcmRequestListener $listener, ?string $signer, string $secret, string $shareId = self::SHARE_ID): array {
		$event = new OCMEndpointRequestEvent('POST', 'keepiq/shares/' . $shareId, ['sharedSecret' => $secret], $signer);
		$listener->handle($event);
		$response = $event->getResponse();
		$this->assertInstanceOf(JSONResponse::class, $response);

		return [$response->getStatus(), $response->getData()];
	}

	public function testAliceAnswersBobsPartnerWithTheCiphertextOnly(): void {
		[$status, $body] = $this->pull($this->alice(), 'cloud.here.example', self::SHARED_SECRET);

		$this->assertSame(Http::STATUS_OK, $status);
		$this->assertSame('CIPHER-KEY-FOR-BOB', $body['key']);
		$this->assertSame('bob@cloud.here.example', $body['recipientCloudId']);
		$this->assertSame('alice@cloud.here.example', $body['ownerCloudId']);
		$this->assertSame('Supplier portal', $body['name']);
		// Never Alice's own ciphertext, never the secret's hash.
		$this->assertStringNotContainsString('ALICE-OWN-CIPHERTEXT', json_encode($body));
		$this->assertStringNotContainsString(hash('sha256', self::SHARED_SECRET), json_encode($body));
	}

	public function testEveryRefusedPullGetsTheUnknownAnswer(): void {
		[, $unknown] = $this->pull($this->alice(), 'cloud.here.example', self::SHARED_SECRET, '6d1f6c1e-0000-4000-8000-0000000000ff');

		$cases = [
			'wrong shared secret' => [$this->alice(), 'cloud.here.example', 'guess'],
			'another partner as signer' => [$this->alice(), 'cloud.other.example', self::SHARED_SECRET],
			'a stranger as signer' => [$this->alice(), 'cloud.evil.example', self::SHARED_SECRET],
			'unsigned' => [$this->alice(), null, self::SHARED_SECRET],
			'suspended share' => [$this->alice(FederatedShare::STATUS_SUSPENDED), 'cloud.here.example', self::SHARED_SECRET],
		];
		foreach ($cases as $label => [$listener, $signer, $secret]) {
			[$status, $body] = $this->pull($listener, $signer, $secret);
			$this->assertSame(Http::STATUS_NOT_FOUND, $status, $label);
			$this->assertSame($unknown, $body, $label);
		}
	}
}
