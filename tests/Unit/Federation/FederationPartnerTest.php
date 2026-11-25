<?php

/**
 * Keepiq Federation Partner Test
 *
 * The partner allowlist (keepiq#789, task 1.3 backend and 1.2): adding a
 * partner pins the root fingerprint the administrator confirmed and refuses
 * one that changed since the preview; partner addresses are https; every
 * change is admin-only and needs a fresh password confirmation; the
 * discovery document publishes this instance's root fingerprint, computed
 * like `openssl x509 -fingerprint -sha256`.
 *
 * @category Tests
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
 * @spec openspec/specs/federated-sharing/spec.md#requirement-administrators-approve-and-pin-partner-instances
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use InvalidArgumentException;
use OCA\Keepiq\Controller\DiscoveryController;
use OCA\Keepiq\Controller\FederationPartnerController;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Event\Audit\AuditMetadataPolicy;
use OCA\Keepiq\Service\FederationPartnerAuditTrail;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

require_once __DIR__ . '/FederationFixtures.php';

class FederationPartnerTest extends TestCase {
	use FederationFixtures;

	/**
	 * Skip on a Nextcloud without the OCM APIs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->skipWithoutFederation();
	}//end setUp()

	/** @var array<int,string> URLs the fake HTTP client was asked for */
	private array $fetched = [];

	/**
	 * A partner service whose HTTP client answers with this discovery document.
	 *
	 * @param mixed $document The partner's discovery document
	 * @param array $partners Existing partner rows
	 * @param bool $allowLocal Nextcloud's allow_local_remote_servers
	 * @param FederationPartnerMapper|null $mapper The partner table, instead of one over $partners
	 *
	 * @return FederationPartnerService
	 */
	private function service(
		mixed $document,
		array $partners = [],
		bool $allowLocal = false,
		?FederationPartnerMapper $mapper = null,
	): FederationPartnerService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn(json_encode($document));
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function (string $url) use ($response): IResponse {
			$this->fetched[] = $url;
			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn($allowLocal);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->audited[] = $event;
		});

		return new FederationPartnerService(
			$mapper ?? $this->partnerMapper($partners),
			$clients,
			$config,
			new FederationPartnerAuditTrail($dispatcher)
		);
	}

	/** @var array<int,Event> Events the partner service dispatched */
	private array $audited = [];

	/**
	 * A discovery document with a federation block.
	 *
	 * @param string $fingerprint The root fingerprint
	 * @param bool $enabled Whether federation is on
	 *
	 * @return array<string,mixed>
	 */
	private function discovery(string $fingerprint, bool $enabled = true): array {
		return ['apiVersion' => 1, 'federation' => ['enabled' => $enabled, 'rootFingerprint' => $fingerprint]];
	}

	public function testPreviewReadsTheRootFingerprintFromThePartnersDiscovery(): void {
		$fingerprint = str_repeat('cd', 32);

		$preview = $this->service($this->discovery($fingerprint))->preview('HTTPS://Cloud.Partner.Example/nc/');

		$this->assertSame(
			['baseUrl' => 'https://cloud.partner.example/nc', 'host' => 'cloud.partner.example', 'rootFingerprint' => $fingerprint],
			$preview
		);
		$this->assertSame(
			['https://cloud.partner.example/nc/index.php/apps/keepiq' . DiscoveryController::CANONICAL_DISCOVERY_PATH],
			$this->fetched
		);
	}

	public function testAddPinsTheConfirmedFingerprintWithBothDirections(): void {
		$fingerprint = str_repeat('cd', 32);

		$partner = $this->service($this->discovery($fingerprint))
			->add('https://cloud.partner.example', strtoupper($fingerprint), true, false, 'admin');

		$this->assertSame('cloud.partner.example', $partner->getHost());
		$this->assertSame($fingerprint, $partner->getRootFingerprint());
		$this->assertTrue($partner->getAllowOutbound());
		$this->assertFalse($partner->getAllowInbound());
		$this->assertSame('admin', $partner->getAddedBy());
	}

	public function testAddRefusesAFingerprintThatChangedSinceThePreview(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('not the one you confirmed');

		$this->service($this->discovery(str_repeat('ee', 32)))
			->add('https://cloud.partner.example', str_repeat('cd', 32), true, true, 'admin');
	}

	public function testAddRefusesAPartnerThatIsAlreadyThere(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('already added');

		$this->service($this->discovery(str_repeat('cd', 32)), [$this->partner('cloud.partner.example', true, true)])
			->add('https://cloud.partner.example', str_repeat('cd', 32), true, true, 'admin');
	}

	/**
	 * Partner documents that do not offer federation.
	 *
	 * @return array<string,array{0:mixed}>
	 */
	public static function noFederation(): array {
		return [
			'no federation block' => [['apiVersion' => 1]],
			'federation off' => [['federation' => ['enabled' => false, 'rootFingerprint' => str_repeat('cd', 32)]]],
			'malformed fingerprint' => [['federation' => ['enabled' => true, 'rootFingerprint' => 'cd:cd']]],
			'not json' => ['<html>'],
		];
	}

	/**
	 * @dataProvider noFederation
	 *
	 * @param mixed $document The partner document
	 */
	public function testPreviewRefusesAPartnerWithoutFederation(mixed $document): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service($document)->preview('https://cloud.partner.example');
	}

	/**
	 * Addresses a partner may not have.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function badAddresses(): array {
		return [
			'plain http' => ['http://cloud.partner.example'],
			'no scheme' => ['cloud.partner.example'],
			'credentials' => ['https://user:pass@cloud.partner.example'],
			'query' => ['https://cloud.partner.example/?x=1'],
			'other scheme' => ['ftp://cloud.partner.example'],
		];
	}

	/**
	 * @dataProvider badAddresses
	 *
	 * @param string $url The address
	 */
	public function testRefusesAnAddressThatIsNotPlainHttps(string $url): void {
		$this->expectException(InvalidArgumentException::class);

		$this->service($this->discovery(str_repeat('cd', 32)))->normaliseBaseUrl($url);
	}

	public function testAllowsHttpOnlyWhereNextcloudAllowsLocalRemoteServers(): void {
		$this->assertSame(
			'http://keepiq-b:8080',
			$this->service([], [], allowLocal: true)->normaliseBaseUrl('http://keepiq-b:8080/')
		);
	}

	public function testHostOfMatchesNextcloudsSignerIdentity(): void {
		$service = $this->service([]);

		$this->assertSame('cloud.partner.example', $service->hostOf('https://Cloud.Partner.Example/nc'));
		$this->assertSame('cloud.partner.example:8443', $service->hostOf('https://cloud.partner.example:8443'));
		$this->assertSame('cloud.partner.example', $service->hostOf('cloud.partner.example'));
		$this->assertNull($service->hostOf(''));
	}

	public function testRootFingerprintIsSha256OverTheDerLikeOpenssl(): void {
		$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
		$csr = openssl_csr_new(['commonName' => 'Keepiq Root'], $key, ['digest_alg' => 'sha256']);
		openssl_x509_export(openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']), $pem);

		$root = $this->rootService($this->caMapper());

		$this->assertSame(openssl_x509_fingerprint($pem, 'sha256'), $root->fingerprint($pem));
		$this->assertSame(self::ROOT_FINGERPRINT, $root->localRootFingerprint());
		$this->assertSame([self::INTERMEDIATE_PEM, self::ROOT_PEM], $root->localChain());
		$this->assertNull($root->fingerprint('not a certificate'));
		$this->assertNull(($this->rootService($this->caMapper(false)))->localRootFingerprint());
	}

	public function testTheDiscoveryDocumentPublishesTheRootFingerprint(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/apps/keepiq/x');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$discovery = new DiscoveryController(
			$this->createMock(IRequest::class),
			$urls,
			null,
			null,
			$this->rootService($this->caMapper()),
		);

		$this->assertSame(
			['enabled' => true, 'rootFingerprint' => self::ROOT_FINGERPRINT],
			$discovery->document()->getData()['federation']
		);
	}

	/**
	 * After a root renewal the discovery document announces it under `federation.rootRenewal`: both roots as PEM,
	 * the renewal time in ISO 8601 UTC and the previous root's signature. A root never renewed announces nothing.
	 *
	 * @return void
	 */
	public function testTheDiscoveryDocumentAnnouncesARootRenewal(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/apps/keepiq/x');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$request = $this->createMock(IRequest::class);
		$document = static fn (FederationRootService $root): array => (new DiscoveryController(
			$request,
			$urls,
			null,
			null,
			$root,
		))->document()->getData()['federation'];

		$this->assertSame(
			['enabled' => true, 'rootFingerprint' => self::ROOT_FINGERPRINT, 'rootRenewal' => self::RENEWAL],
			$document($this->rootService($this->renewedCaMapper()))
		);
		$this->assertArrayNotHasKey('rootRenewal', $document($this->rootService($this->caMapper())));
	}

	/**
	 * The announcement is published until 30 days after the renewal: in its last second it is there, from the 30th
	 * day on the discovery document carries only the fingerprint.
	 *
	 * @return void
	 */
	public function testTheDiscoveryDocumentStopsAnnouncingTheRenewalThirtyDaysAfterIt(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('linkToRoute')->willReturn('/index.php/apps/keepiq/x');
		$urls->method('getAbsoluteURL')->willReturnArgument(0);
		$request = $this->createMock(IRequest::class);
		$document = static fn (FederationRootService $root): array => (new DiscoveryController(
			$request,
			$urls,
			null,
			null,
			$root,
		))->document()->getData()['federation'];

		$this->assertSame(self::RENEWAL, $this->rootService($this->renewedCaMapper(), '2026-12-20T09:59:59Z')->localRootRenewal());
		$this->assertNull($this->rootService($this->renewedCaMapper(), '2026-12-20T10:00:00Z')->localRootRenewal());
		$this->assertSame(
			['enabled' => true, 'rootFingerprint' => self::ROOT_FINGERPRINT],
			$document($this->rootService($this->renewedCaMapper(), '2026-12-20T10:00:00Z'))
		);
	}

	/**
	 * A root renewed before roots carried a signature, or whose predecessor row is gone, announces nothing.
	 *
	 * @return void
	 */
	public function testARenewalWithoutSignatureOrPredecessorIsNotAnnounced(): void {
		$mapper = $this->renewedCaMapper();
		$mapper->findRoot()->setPredecessorSignature(null);
		$this->assertNull(($this->rootService($mapper))->localRootRenewal());

		$orphan = $this->createMock(\OCA\Keepiq\Db\CACertificateMapper::class);
		$root = new \OCA\Keepiq\Db\CACertificate();
		$root->setId('root-new');
		$root->setCertificate(self::ROOT_PEM);
		$root->setCreatedAt(new \DateTime());
		$root->setPredecessorSignature('c2lnbmF0dXJl');
		$orphan->method('findRoot')->willReturn($root);
		$orphan->method('findPredecessorOf')->willReturn(null);
		$this->assertNull(($this->rootService($orphan))->localRootRenewal());
		$this->assertNull(($this->rootService($this->caMapper(false)))->localRootRenewal());
	}

	/** @var array<int,FederationPartner> Partner rows written through update() */
	private array $updated = [];

	/**
	 * A partner service over one stored partner, whose discovery document is the given one.
	 *
	 * @param FederationPartner $stored The stored partner
	 * @param mixed $document The partner's discovery document now
	 *
	 * @return FederationPartnerService
	 */
	private function repinService(FederationPartner $stored, mixed $document): FederationPartnerService {
		// As read from the table: no field changed yet.
		$stored->resetUpdatedFields();
		$mapper = $this->partnerMapper([$stored]);
		$mapper->method('findById')->willReturnCallback(
			static fn (string $id): FederationPartner => ($id === $stored->getId()) ? $stored : throw new DoesNotExistException('none')
		);
		// As QBMapper::update, a row without changed fields is not written.
		$mapper->method('update')->willReturnCallback(
			function (FederationPartner $partner): FederationPartner {
				if ($partner->getUpdatedFields() !== []) {
					$this->updated[] = $partner;
					$partner->resetUpdatedFields();
				}

				return $partner;
			}
		);

		return $this->service($document, mapper: $mapper);
	}

	public function testRepinStoresTheRenewedRootAndKeepsTheDirections(): void {
		$stored = $this->partner('cloud.partner.example', true, false);
		$renewed = str_repeat('cd', 32);

		$result = $this->repinService($stored, $this->discovery($renewed))
			->repin($stored->getId(), strtoupper($renewed), 'admin');

		$this->assertTrue($result['changed']);
		$this->assertSame($renewed, $result['partner']->getRootFingerprint());
		$this->assertTrue($result['partner']->getAllowOutbound());
		$this->assertFalse($result['partner']->getAllowInbound());
		$this->assertSame([$stored], $this->updated);
		$this->assertSame(
			['https://cloud.partner.example/index.php/apps/keepiq' . DiscoveryController::CANONICAL_DISCOVERY_PATH],
			$this->fetched
		);
	}

	public function testAStoredRepinIsAuditedWithThePartnerBothFingerprintsAndTheAdmin(): void {
		$stored = $this->partner('cloud.partner.example', true, false);
		$pinned = $stored->getRootFingerprint();
		$renewed = str_repeat('cd', 32);

		$this->repinService($stored, $this->discovery($renewed))->repin($stored->getId(), $renewed, 'admin');

		$this->assertCount(1, $this->audited);
		$event = $this->audited[0];
		$this->assertInstanceOf(AuditEvent::class, $event);
		$this->assertSame(AuditEventTypes::FEDERATION_PARTNER_REPINNED, $event->getEventType());
		$this->assertSame(AuditEvent::ACTOR_USER, $event->getActorType());
		$this->assertSame('admin', $event->getActorId());
		$this->assertSame('federation_partner', $event->getObjectType());
		$this->assertSame($stored->getId(), $event->getObjectId());
		$this->assertSame(
			['partnerId' => $stored->getId(), 'oldFingerprint' => $pinned, 'newFingerprint' => $renewed],
			$event->getMetadata()
		);
		// Every key survives the audit whitelist.
		$this->assertSame(
			array_keys($event->getMetadata()),
			AuditMetadataPolicy::WHITELIST[AuditEventTypes::FEDERATION_PARTNER_REPINNED]
		);
	}

	public function testRepinStoresNothingWhileThePartnerPublishesThePinnedRoot(): void {
		$stored = $this->partner('cloud.partner.example', true, true);
		$pinned = $stored->getRootFingerprint();

		$result = $this->repinService($stored, $this->discovery($pinned))->repin($stored->getId(), $pinned, 'admin');

		$this->assertFalse($result['changed']);
		$this->assertSame($pinned, $result['partner']->getRootFingerprint());
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->audited);
	}

	/**
	 * Re-pins that are refused, each with the partner's document now and the fingerprint the admin confirmed.
	 *
	 * @return array<string,array{0:string,1:mixed,2:string,3:string}>
	 */
	public static function refusedRepins(): array {
		$pinned = str_repeat('ab', 32);
		$renewed = str_repeat('cd', 32);
		$federation = static fn (string $fingerprint): array => ['federation' => ['enabled' => true, 'rootFingerprint' => $fingerprint]];

		return [
			'published root changed after the preview' => ['https://cloud.partner.example', $federation(str_repeat('ee', 32)), $renewed, 'not the one you confirmed'],
			'pinned root still published, another confirmed' => ['https://cloud.partner.example', $federation($pinned), $renewed, 'not the one you confirmed'],
			'nothing confirmed' => ['https://cloud.partner.example', $federation($renewed), '', 'not the one you confirmed'],
			'stored address and pinned host disagree' => ['https://cloud.elsewhere.example', $federation($renewed), $renewed, 'does not match its pinned host'],
			'federation turned off' => ['https://cloud.partner.example', ['federation' => ['enabled' => false, 'rootFingerprint' => $renewed]], $renewed, 'does not offer Keepiq federation'],
			'no discovery document' => ['https://cloud.partner.example', '<html>', $renewed, 'does not offer Keepiq federation'],
		];
	}

	/**
	 * @dataProvider refusedRepins
	 *
	 * @param string $baseUrl The stored partner address
	 * @param mixed $document The partner's discovery document now
	 * @param string $confirmed The fingerprint the administrator confirmed
	 * @param string $refusal Part of the refusal's message
	 */
	public function testARefusedRepinKeepsThePin(string $baseUrl, mixed $document, string $confirmed, string $refusal): void {
		$stored = $this->partner('cloud.partner.example', true, true);
		$stored->setBaseUrl($baseUrl);
		$pinned = $stored->getRootFingerprint();

		try {
			$this->repinService($stored, $document)->repin($stored->getId(), $confirmed, 'admin');
			$this->fail('The re-pin was not refused');
		} catch (InvalidArgumentException $exception) {
			$this->assertStringContainsString($refusal, $exception->getMessage());
		}

		$this->assertSame($pinned, $stored->getRootFingerprint());
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->audited);
	}

	public function testRepinOfAnUnknownPartnerReadsNothing(): void {
		$stored = $this->partner('cloud.partner.example', true, true);

		try {
			$this->repinService($stored, $this->discovery(str_repeat('cd', 32)))
				->repin('p-unknown', str_repeat('cd', 32), 'admin');
			$this->fail('The re-pin was not refused');
		} catch (DoesNotExistException) {
		}

		$this->assertSame([], $this->fetched);
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->audited);
	}

	public function testRequireOutboundPartnerNamesWhyAHostIsNoOutboundPartner(): void {
		$service = $this->service([], [
			$this->partner('cloud.out.example', true, false),
			$this->partner('cloud.in.example', false, true),
		]);

		$this->assertSame('cloud.out.example', $service->requireOutboundPartner('https://cloud.out.example')->getHost());
		foreach (['cloud.in.example' => 'outbound_disabled', 'cloud.stranger.example' => 'not_a_partner', '' => 'not_a_partner'] as $remote => $reason) {
			try {
				$service->requireOutboundPartner((string)$remote);
				$this->fail("$remote was not refused");
			} catch (InvalidArgumentException $exception) {
				$this->assertSame($reason, $exception->getMessage(), (string)$remote);
			}

			// The other callers keep their answer: no partner to share to.
			$this->assertNull($service->outboundPartnerForRemote((string)$remote), (string)$remote);
		}
	}

	public function testEveryPartnerChangeIsAdminOnlyAndNeedsAPasswordConfirmation(): void {
		foreach (['index', 'preview', 'create', 'update', 'repin', 'destroy'] as $method) {
			$reflection = new ReflectionMethod(FederationPartnerController::class, $method);
			$this->assertNotEmpty($reflection->getAttributes(AuthorizedAdminSetting::class), "$method is not admin-guarded");
		}

		foreach (['create', 'update', 'repin', 'destroy'] as $method) {
			$reflection = new ReflectionMethod(FederationPartnerController::class, $method);
			$this->assertNotEmpty(
				$reflection->getAttributes(PasswordConfirmationRequired::class),
				"$method does not need a password confirmation"
			);
		}
	}

	public function testBelowNextcloud33NoPartnerCanBeAdded(): void {
		$root = $this->createMock(FederationRootService::class);
		$root->method('isSupported')->willReturn(false);
		$partners = $this->createMock(FederationPartnerService::class);
		$partners->expects($this->never())->method('add');
		$partners->method('all')->willReturn([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($this->createMock(IUser::class));
		$controller = new FederationPartnerController(
			$this->createMock(IRequest::class),
			$partners,
			$root,
			$session,
			$this->createMock(\OCA\Keepiq\Service\FederationPartnerChanges::class)
		);

		$this->assertSame(Http::STATUS_CONFLICT, $controller->create('https://cloud.partner.example', str_repeat('cd', 32), true, true)->getStatus());
		$this->assertSame(Http::STATUS_CONFLICT, $controller->preview('https://cloud.partner.example')->getStatus());
		$this->assertFalse($controller->index()->getData()['supported']);
	}

	public function testThePartnerRowSerialisesWithoutSurprises(): void {
		$row = $this->partner('cloud.partner.example', true, false);

		$this->assertSame(
			['id', 'baseUrl', 'host', 'rootFingerprint', 'allowOutbound', 'allowInbound', 'addedBy', 'addedAt', 'valueFields', 'pendingRenewal'],
			array_keys($row->jsonSerialize())
		);
		$this->assertNull($row->jsonSerialize()['pendingRenewal']);
		$this->assertInstanceOf(FederationPartner::class, $row);
	}

	/**
	 * An offered renewal serialises with its fingerprint and window while the window is open, and not after it,
	 * and never with the renewal notice state of this instance's own renewals.
	 *
	 * @return void
	 */
	public function testAnOfferedRenewalSerialisesOnlyWhileItsWindowIsOpen(): void {
		$row = $this->partner('cloud.partner.example', true, false);
		$row->setRenewalNoticeAttempts(2);
		$row->offerRenewal(str_repeat('cd', 32), new \DateTime('-1 day'), new \DateTime('+29 days'));

		$offered = $row->jsonSerialize()['pendingRenewal'];
		$this->assertSame(['fingerprint', 'verifiedAt', 'windowEndsAt'], array_keys($offered));
		$this->assertSame(str_repeat('cd', 32), $offered['fingerprint']);
		$this->assertArrayNotHasKey('renewalNoticeAttempts', $row->jsonSerialize());

		$row->setPendingRootWindowEndsAt(new \DateTime('-1 second'));
		$this->assertNull($row->jsonSerialize()['pendingRenewal']);
	}

	/**
	 * Every re-pin withdraws the offered renewal, also one to the fingerprint already pinned; that one stores the
	 * withdrawal only and writes no audit entry.
	 *
	 * @return void
	 */
	public function testEveryRepinWithdrawsTheOfferedRenewal(): void {
		$stored = $this->partner('cloud.partner.example', true, false);
		$pinned = $stored->getRootFingerprint();
		$renewed = str_repeat('cd', 32);
		$stored->offerRenewal($renewed, new \DateTime(), new \DateTime('+20 days'));

		$result = $this->repinService($stored, $this->discovery($pinned))->repin($stored->getId(), $pinned, 'admin');
		$this->assertFalse($result['changed']);
		$this->assertSame($pinned, $stored->getRootFingerprint());
		$this->assertNull($stored->getPendingRootFingerprint());
		$this->assertSame([$stored], $this->updated);
		$this->assertSame([], $this->audited);

		$this->updated = [];
		$stored->offerRenewal($renewed, new \DateTime(), new \DateTime('+20 days'));
		$this->repinService($stored, $this->discovery($renewed))->repin($stored->getId(), $renewed, 'admin');
		$this->assertNull($stored->getPendingRootFingerprint());
		$this->assertNull($stored->getPendingRootWindowEndsAt());
		$this->assertSame([$stored], $this->updated);
	}
}
