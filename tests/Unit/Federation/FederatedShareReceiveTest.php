<?php

/**
 * Receiving a federated share: only a signed inbound partner delivers, and
 * only to a user who opted in (sharing-federated-recipients 3.2).
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
 * @spec openspec/changes/sharing-federated-recipients/specs/federated-sharing/spec.md#scenario-a-non-partner-cannot-deliver
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCA\Keepiq\AppInfo\FederationEventRegistrar;
use OCA\Keepiq\Db\FederatedInbound;
use OCA\Keepiq\Db\FederatedInboundMapper;
use OCA\Keepiq\Federation\KeepiqSecretFederationProvider;
use OCA\Keepiq\Service\FederatedShareReceiver;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\NotificationService;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Federation\Exceptions\ProviderCouldNotAddShareException;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IUserManager;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\ICrypto;
use OCP\Security\Signature\Exceptions\IncomingRequestException;
use OCP\Security\Signature\IIncomingSignedRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class FederatedShareReceiveTest extends TestCase {
	use FederationFixtures;

	/** @var array<string,FederatedInbound> */
	private array $rows = [];

	/** @var array<string,string> userId => receive preference */
	private array $optIns = ['bob' => '1', 'carol' => '0'];

	private NotificationService&MockObject $notifications;

	/** @var array<int,string|null> The OCM addresses the signature check was asked with */
	private array $askedWith = [];

	/**
	 * The provider over the given partners, with the request signed by $signer
	 * (null: unsigned; 'bad': a signature that does not verify).
	 *
	 * @param array $partners The partner rows
	 * @param string|null $signer The signer origin
	 *
	 * @return KeepiqSecretFederationProvider
	 */
	private function provider(array $partners, ?string $signer): KeepiqSecretFederationProvider {
		$mapper = $this->createMock(FederatedInboundMapper::class);
		$mapper->method('insert')->willReturnCallback(
			function (FederatedInbound $row): FederatedInbound {
				$this->rows[$row->getId()] = $row;
				return $row;
			}
		);
		$mapper->method('findByRemote')->willReturnCallback(
			function (string $partnerId, string $remoteShareId): FederatedInbound {
				foreach ($this->rows as $row) {
					if ($row->getPartnerId() === $partnerId && $row->getRemoteShareId() === $remoteShareId) {
						return $row;
					}
				}
				throw new DoesNotExistException('none');
			}
		);

		$ocm = $this->createMock(IOCMDiscoveryService::class);
		if ($signer === 'bad') {
			$ocm->method('getIncomingSignedRequest')->willThrowException(new IncomingRequestException('Invalid signature'));
		} else if ($signer === null) {
			$ocm->method('getIncomingSignedRequest')->willReturn(null);
		} else {
			$signed = $this->createMock(IIncomingSignedRequest::class);
			$signed->method('getOrigin')->willReturn($signer);
			$ocm->method('getIncomingSignedRequest')->willReturnCallback(
				function (?string $address = null) use ($signed): IIncomingSignedRequest {
					$this->askedWith[] = $address;
					return $signed;
				}
			);
		}

		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$config->method('getUserValue')->willReturnCallback(
			fn (string $user, string $app, string $key, $default) => $this->optIns[$user] ?? $default
		);
		$users = $this->createMock(IUserManager::class);
		$users->method('userExists')->willReturnCallback(static fn (string $uid): bool => in_array($uid, ['bob', 'carol'], true));
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('encrypt')->willReturnCallback(static fn (string $plain): string => 'enc(' . $plain . ')');
		$this->notifications = $this->createMock(NotificationService::class);

		$service = new FederatedShareReceiver(
			inboundMapper: $mapper,
			partners: new FederationPartnerService($this->partnerMapper($partners), $this->createMock(IClientService::class), $config),
			ocmDiscovery: $ocm,
			cloudIdManager: $this->cloudIdManager(),
			userManager: $users,
			config: $config,
			crypto: $crypto,
			notifications: $this->notifications,
		);

		return new KeepiqSecretFederationProvider($service);
	}

	/**
	 * An OCM share as Nextcloud's addShare() hands it over: shareWith is the
	 * local uid by then.
	 *
	 * @param array<string,string> $overrides Fields to change
	 *
	 * @return FakeCloudFederationShare
	 */
	private function share(array $overrides = []): FakeCloudFederationShare {
		$share = new FakeCloudFederationShare();
		$share->setShareWith($overrides['shareWith'] ?? 'bob');
		$share->setResourceName('Supplier portal');
		$share->setProviderId($overrides['providerId'] ?? '6d1f6c1e-0000-4000-8000-000000000001');
		$share->setOwner($overrides['owner'] ?? 'alice@cloud.city.example');
		$share->setShareType($overrides['shareType'] ?? 'user');
		$share->setResourceType($overrides['resourceType'] ?? 'keepiq-secret');
		$share->setProtocol(['name' => 'keepiq', 'options' => ['sharedSecret' => 'the-shared-secret']]);
		return $share;
	}

	public function testAnInboundPartnerDeliversAPendingShareAndBobIsTold(): void {
		$provider = $this->provider([$this->partner('cloud.city.example', false, true)], 'cloud.city.example');
		$this->notifications->expects($this->once())->method('notify')->with(
			'federated_share_received',
			'bob',
			['shared_by' => 'alice@cloud.city.example', 'secret_name' => 'Supplier portal'],
		);

		$id = $provider->shareReceived($this->share());

		// Nextcloud 35 can verify an RFC 9421 signature only when told whose
		// it is: the owner's OCM address.
		$this->assertSame(['alice@cloud.city.example'], $this->askedWith);

		$row = $this->rows[$id];
		$this->assertSame(FederatedInbound::STATUS_PENDING, $row->getStatus());
		$this->assertSame('bob', $row->getRecipientUid());
		$this->assertSame('alice@cloud.city.example', $row->getSenderCloudId());
		$this->assertSame('p-cloud.city.example', $row->getPartnerId());
		$this->assertSame('6d1f6c1e-0000-4000-8000-000000000001', $row->getRemoteShareId());
		$this->assertSame('Supplier portal', $row->getName());
		$this->assertNull($row->getSecretId());
		// The shared secret is kept for the pull, but never in the clear.
		$this->assertSame('enc(the-shared-secret)', $row->getSharedSecretEnc());
		$this->assertArrayNotHasKey('sharedSecretEnc', $row->jsonSerialize());
	}

	/**
	 * Every case where Bob's server must store nothing.
	 *
	 * @return array<string,array{0:array<int,array{0:string,1:bool,2:bool}>,1:string|null,2:array<string,string>}>
	 */
	public static function refusals(): array {
		$inbound = [['cloud.city.example', false, true]];
		return [
			'not a partner' => [[], 'cloud.city.example', []],
			'partner without inbound' => [[['cloud.city.example', true, false]], 'cloud.city.example', []],
			'unsigned' => [$inbound, null, []],
			'badly signed' => [$inbound, 'bad', []],
			'owner on another instance than the signer' => [$inbound, 'cloud.city.example', ['owner' => 'mallory@cloud.evil.example']],
			'user who did not opt in' => [$inbound, 'cloud.city.example', ['shareWith' => 'carol']],
			'unknown user' => [$inbound, 'cloud.city.example', ['shareWith' => 'nobody']],
			'other resource type' => [$inbound, 'cloud.city.example', ['resourceType' => 'file']],
			'group share' => [$inbound, 'cloud.city.example', ['shareType' => 'group']],
		];
	}

	/**
	 * @dataProvider refusals
	 */
	public function testARefusedShareStoresNothingAndTellsNoOne(array $partners, ?string $signer, array $overrides): void {
		$rows = array_map(fn (array $p) => $this->partner(...$p), $partners);
		$provider = $this->provider($rows, $signer);
		$this->notifications->expects($this->never())->method('notify');

		try {
			$provider->shareReceived($this->share($overrides));
			$this->fail('the share was accepted');
		} catch (ProviderCouldNotAddShareException $exception) {
			// One answer, whatever failed.
			$this->assertSame('Share refused', $exception->getMessage());
			$this->assertSame(403, $exception->getCode());
		}

		$this->assertSame([], $this->rows);
	}

	public function testTheSameShareIsNotStoredTwice(): void {
		$provider = $this->provider([$this->partner('cloud.city.example', false, true)], 'cloud.city.example');
		$provider->shareReceived($this->share());

		$this->expectException(ProviderCouldNotAddShareException::class);
		$provider->shareReceived($this->share());
	}

	public function testTheProviderHandlesKeepiqSecretsForUsers(): void {
		$provider = $this->provider([], null);

		$this->assertSame('keepiq-secret', $provider->getShareType());
		$this->assertSame(['user'], $provider->getSupportedShareTypes());
	}

	public function testTheProviderIsRegisteredAtBoot(): void {
		$manager = $this->createMock(ICloudFederationProviderManager::class);
		$manager->expects($this->once())->method('addCloudFederationProvider')->with(
			'keepiq-secret',
			'Keepiq secret',
			$this->isType('callable'),
		);
		$context = $this->createMock(IBootContext::class);
		$context->expects($this->once())->method('injectFn')->willReturnCallback(
			static fn (callable $fn) => $fn($manager)
		);

		(new FederationEventRegistrar())->boot($context);
	}
}
