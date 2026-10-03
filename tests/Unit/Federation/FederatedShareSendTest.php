<?php

/**
 * Sending a federated share: the ciphertext stays on the sending server and
 * the OCM share announces it without it (sharing-federated-recipients 3.1).
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#requirement-federated-shares-carry-only-browser-made-ciphertext
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Controller\FederatedShareController;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\FederatedShareMessenger;
use OCA\Keepiq\Service\FederatedShareService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedShareSendTest extends TestCase {
	use FederationFixtures;

	private const CIPHER_KEY = 'CIPHER-KEY-FOR-BOB';
	private const CIPHER_LOGIN = 'CIPHER-LOGIN-FOR-BOB';
	private const CIPHER_EXTRA = 'CIPHER-EXTRA-FOR-BOB';
	private const FINGERPRINT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private Secret $source;

	/** @var array<string,FederatedShare> */
	private array $rows = [];

	/** @var FakeCloudFederationShare[] */
	private array $sent = [];

	private int $deliveryStatus = 201;

	private ICloudFederationProviderManager&MockObject $ocm;

	protected function setUp(): void {
		$this->source = new Secret();
		$this->source->setId('src');
		$this->source->setName('Supplier portal');
		$this->source->setUrl('https://supplier.example');
		$this->source->setOwnerType('user');
		$this->source->setOwnerId('alice');
		$this->source->setKey('ALICE-OWN-CIPHERTEXT');
		$this->source->setUpdatedAt(new DateTime('2026-10-01T00:00:00Z'));
	}

	/**
	 * The controller over the given partners, as Alice unless told otherwise.
	 *
	 * @param array $partners The partner rows
	 * @param string $uid The signed-in user
	 *
	 * @return FederatedShareController
	 */
	private function controller(array $partners, string $uid = 'alice'): FederatedShareController {
		$secrets = $this->createMock(SecretMapper::class);
		$secrets->method('findById')->willReturnCallback(
			fn (string $id): Secret => ($id === 'src') ? $this->source : throw new DoesNotExistException('none')
		);

		$mapper = $this->createMock(FederatedShareMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				$this->rows[$row->getId()] = $row;
				return $row;
			}
		);
		$mapper->method('delete')->willReturnCallback(
			function (FederatedShare $row): FederatedShare {
				unset($this->rows[$row->getId()]);
				return $row;
			}
		);
		$mapper->method('findBySourceSecret')->willReturnCallback(fn (): array => array_values($this->rows));

		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationShare')->willReturnCallback(
			static function (...$args): FakeCloudFederationShare {
				[$shareWith, $name, $description, $providerId, $owner, $ownerName, $sharedBy, $sharedByName, $secret, $type, $resourceType] = $args;
				$share = new FakeCloudFederationShare();
				$share->setShareWith($shareWith);
				$share->setResourceName($name);
				$share->setDescription($description);
				$share->setProviderId($providerId);
				$share->setOwner($owner);
				$share->setOwnerDisplayName($ownerName);
				$share->setSharedBy($sharedBy);
				$share->setSharedByDisplayName($sharedByName);
				$share->setShareType($type);
				$share->setResourceType($resourceType);
				$share->setProtocol(['name' => 'webdav', 'options' => ['sharedSecret' => $secret]]);
				return $share;
			}
		);

		$this->ocm = $this->createMock(ICloudFederationProviderManager::class);
		$this->ocm->method('sendCloudShare')->willReturnCallback(
			function (FakeCloudFederationShare $share): IResponse {
				$this->sent[] = $share;
				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($this->deliveryStatus);
				return $response;
			}
		);

		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('s', 64));

		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturn('Alice Adams');

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);

		$service = new FederatedShareService(
			shareMapper: $mapper,
			secretMapper: $secrets,
			partners: new FederationPartnerService($this->partnerMapper($partners), $this->createMock(IClientService::class), $config),
			root: new FederationRootService($this->caMapper()),
			cloudIdManager: $this->cloudIdManager(),
			messenger: new FederatedShareMessenger(
				providerManager: $this->ocm,
				factory: $factory,
				cloudIdManager: $this->cloudIdManager(),
				userManager: $users,
			),
			random: $random,
			delivery: $this->createMock(\OCA\Keepiq\Service\FederatedNotificationDelivery::class),
			audit: new \OCA\Keepiq\Service\FederatedShareAuditTrail(),
		);

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new FederatedShareController($this->createMock(IRequest::class), $service, $session);
	}

	/**
	 * Alice shares with bob@cloud.partner.example.
	 *
	 * @param FederatedShareController $controller The controller
	 * @param string $cloudId The recipient
	 *
	 * @return \OCP\AppFramework\Http\JSONResponse
	 */
	private function share(FederatedShareController $controller, string $cloudId = 'bob@cloud.partner.example') {
		return $controller->create('src', $cloudId, self::FINGERPRINT, self::CIPHER_KEY, self::CIPHER_LOGIN, self::CIPHER_EXTRA);
	}

	public function testTheOcmShareAnnouncesTheShareWithoutTheCiphertext(): void {
		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_CREATED, $response->getStatus());
		$this->assertCount(1, $this->sent);
		$ocm = $this->sent[0]->getShare();

		// Nothing the owner's browser encrypted travels in the announcement,
		// nor the owner's own ciphertext.
		$wire = json_encode($ocm);
		foreach ([self::CIPHER_KEY, self::CIPHER_LOGIN, self::CIPHER_EXTRA, 'ALICE-OWN-CIPHERTEXT'] as $cipher) {
			$this->assertStringNotContainsString($cipher, $wire);
		}

		$row = array_values($this->rows)[0];
		$this->assertSame('keepiq-secret', $ocm['resourceType']);
		$this->assertSame('user', $ocm['shareType']);
		$this->assertSame('bob@cloud.partner.example', $ocm['shareWith']);
		$this->assertSame('alice@cloud.here.example', $ocm['owner']);
		$this->assertSame('Supplier portal', $ocm['name']);
		$this->assertSame($row->getId(), $ocm['providerId']);

		// The shared secret travels; only its hash is kept here.
		$secret = $this->sent[0]->getShareSecret();
		$this->assertSame(64, strlen($secret));
		$this->assertSame(hash('sha256', $secret), $row->getSharedSecretHash());

		// The ciphertext is stored for the pull.
		$this->assertSame(self::CIPHER_KEY, $row->getKey());
		$this->assertSame(self::CIPHER_LOGIN, $row->getLogin());
		$this->assertSame(self::CIPHER_EXTRA, $row->getAdditionalFields());
		$this->assertSame(self::FINGERPRINT, $row->getRecipientCertFingerprint());
		$this->assertSame('p-cloud.partner.example', $row->getPartnerId());

		// And the owner's answer carries none of it either.
		$this->assertArrayNotHasKey('key', $response->getData());
		$this->assertArrayNotHasKey('sharedSecretHash', $response->getData());
	}

	public function testNothingIsSentToAnInstanceThatIsNoOutboundPartner(): void {
		$this->ocm = $this->createMock(ICloudFederationProviderManager::class);
		$cases = [
			'stranger' => [[], 'bob@cloud.stranger.example'],
			'inbound only' => [[$this->partner('cloud.partner.example', false, true)], 'bob@cloud.partner.example'],
		];
		foreach ($cases as $label => [$partners, $cloudId]) {
			$response = $this->share($this->controller($partners), $cloudId);
			$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus(), $label);
			$this->assertSame('not_a_partner', $response->getData()['message'], $label);
		}

		$this->assertSame([], $this->sent);
		$this->assertSame([], $this->rows);
	}

	public function testOnlyTheOwnerMayShare(): void {
		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)], 'mallory'));

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testAReadOnlyCopyIsNotSharedOnward(): void {
		$this->source->setReadOnly(true);

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame([], $this->sent);
	}

	public function testTheSameRecipientIsNotSharedWithTwice(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->share($controller);

		$response = $this->share($controller);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $response->getStatus());
		$this->assertSame('already_shared', $response->getData()['message']);
		$this->assertCount(1, $this->sent);
	}

	public function testAMalformedRequestIsRefusedBeforeAnythingIsStored(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);

		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create('src', 'bob@cloud.partner.example', 'not-a-fingerprint', self::CIPHER_KEY)->getStatus());
		$this->assertSame(Http::STATUS_BAD_REQUEST, $controller->create('src', 'bob@cloud.partner.example', self::FINGERPRINT, '')->getStatus());
		$this->assertSame([], $this->rows);
	}

	public function testARefusedDeliveryKeepsNothing(): void {
		$this->deliveryStatus = 400;

		$response = $this->share($this->controller([$this->partner('cloud.partner.example', true, false)]));

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
		$this->assertSame('delivery_failed', $response->getData()['message']);
		$this->assertSame([], $this->rows);
	}

	public function testTheOwnerListsTheSharesOfASecret(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->share($controller);

		$list = $controller->index('src');

		$this->assertSame(Http::STATUS_OK, $list->getStatus());
		$this->assertSame('bob@cloud.partner.example', $list->getData()[0]['recipientCloudId']);
		$this->assertSame(Http::STATUS_NOT_FOUND, $this->controller([], 'mallory')->index('src')->getStatus());
	}
}
