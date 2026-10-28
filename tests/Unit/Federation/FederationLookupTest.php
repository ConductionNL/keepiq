<?php

/**
 * Keepiq Federation Lookup Test
 *
 * The owner-facing certificate lookup (keepiq#789, task 2.2), through
 * FederationController and the real FederatedCertificateService and
 * FederationPartnerService. Nextcloud's IOCMDiscoveryService is doubled: the
 * test asserts the exact OCM call (capability, partner, subpath, payload,
 * method) and that nothing is sent to an instance that is no outbound
 * partner.
 *
 * Also the OCM discovery listener: the `keepiq` capability is advertised
 * only while a partner exists.
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
 * @spec openspec/specs/federated-sharing/spec.md#requirement-certificate-lookup-is-signed-allowlisted-and-verified-in-the-browser
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use OCA\Keepiq\Controller\FederationController;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Listener\FederationOcmDiscoveryListener;
use OCA\Keepiq\Service\FederatedCertificateService;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\ShareService;
use OCP\AppFramework\Http;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\OCM\Events\LocalOCMDiscoveryEvent;
use OCP\OCM\IOCMDiscoveryService;
use OCP\OCM\IOCMProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/FederationFixtures.php';

class FederationLookupTest extends TestCase {
	use FederationFixtures;

	/**
	 * Skip on a Nextcloud without the OCM APIs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->skipWithoutFederation();
	}//end setUp()

	private IOCMDiscoveryService&MockObject $ocm;

	private LoggerInterface&MockObject $logger;

	private FederationPartnerMapper&MockObject $partnerTable;

	/** @var array<int,?string> The `valueFields` of every partner row written */
	private array $partnerUpdates = [];

	/** @var list<AuditEvent> The audit events dispatched */
	private array $audits = [];

	/**
	 * The controller over the given partners.
	 *
	 * @param array $partners The partner rows
	 *
	 * @return FederationController
	 */
	private function controller(array $partners): FederationController {
		$this->ocm = $this->createMock(IOCMDiscoveryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);
		$this->partnerTable = $this->partnerMapper($partners);
		$this->partnerTable->method('update')->willReturnCallback(
			function (FederationPartner $partner): FederationPartner {
				$this->partnerUpdates[] = $partner->getValueFields();
				return $partner;
			}
		);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(
			function (AuditEvent $event): void {
				$this->audits[] = $event;
			}
		);
		$service = new FederatedCertificateService(
			new FederationPartnerService($this->partnerTable, $this->createMock(IClientService::class), $config),
			new FederationRootService($this->caMapper()),
			$this->createMock(ShareService::class),
			$this->cloudIdManager(),
			$this->createMock(IUserManager::class),
			$config,
			$this->ocm,
			$this->logger,
			$this->partnerTable,
			$dispatcher,
		);
		$session = $this->createMock(IUserSession::class);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session->method('getUser')->willReturn($user);

		return new FederationController($this->createMock(IRequest::class), $service, $session);
	}

	/**
	 * A partner's HTTP answer.
	 *
	 * @param int $status The status
	 * @param mixed $body The JSON body
	 *
	 * @return IResponse
	 */
	private function response(int $status, mixed $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getStatusCode')->willReturn($status);
		$response->method('getBody')->willReturn(json_encode($body));

		return $response;
	}

	public function testAsksTheOutboundPartnerOverOcmAndReturnsThePinnedRoot(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->expects($this->once())
			->method('requestRemoteOcmEndpoint')
			->with(
				'keepiq',
				'https://cloud.partner.example',
				'keepiq/recipient-certificate',
				// `sender` names the owner: Nextcloud 35 derives the signer's
				// origin for an RFC 9421 signature from it (OCMRequestController).
				['cloudId' => 'bob@cloud.partner.example', 'sender' => 'alice@cloud.here.example'],
				'post',
			)
			->willReturn($this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 'ROOT']]));

		$response = $controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'cloudId' => 'bob@cloud.partner.example',
				'certificate' => 'BOB',
				'chain' => ['INT', 'ROOT'],
				'partnerRootFingerprint' => str_repeat('ab', 32),
				'valueFields' => [],
			],
			$response->getData()
		);
	}

	/**
	 * The lookup records the value fields the partner advertised, so a share it cannot hold is refused later,
	 * and names them to the browser, which picks the payload for that partner. Unknown names are dropped.
	 */
	public function testRecordsTheValueFieldsAPartnerAdvertises(): void {
		$partner = $this->partner('cloud.partner.example', true, false);
		$controller = $this->controller([$partner]);
		$this->ocm->method('requestRemoteOcmEndpoint')->willReturn(
			$this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 'ROOT'], 'valueFields' => ['passkeys', 'future', 'totp']])
		);

		$response = $controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(['cloudId', 'certificate', 'chain', 'partnerRootFingerprint', 'valueFields'], array_keys($response->getData()));
		$this->assertSame(['totp', 'passkeys'], $response->getData()['valueFields']);
		$this->assertSame('["totp","passkeys"]', $partner->getValueFields());
		$this->assertSame(['["totp","passkeys"]'], $this->partnerUpdates);

		// The same advertisement again writes nothing.
		$controller->recipientCertificate('bob@cloud.partner.example');
		$this->assertCount(1, $this->partnerUpdates);
	}

	/**
	 * An older Keepiq advertises nothing: a partner that did before is recorded as holding none of the new fields,
	 * and one that never did is not written at all.
	 */
	public function testAPartnerThatAdvertisesNothingIsRecordedAsOld(): void {
		$downgraded = $this->partner('cloud.partner.example', true, false);
		$downgraded->setValueFields('["totp","passkeys"]');
		$controller = $this->controller([$downgraded]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturn($this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 'ROOT']]));

		$response = $controller->recipientCertificate('bob@cloud.partner.example');
		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame([], $response->getData()['valueFields']);
		$this->assertNull($downgraded->getValueFields());
		$this->assertSame([null], $this->partnerUpdates);

		$this->partnerUpdates = [];
		$old = $this->partner('cloud.partner.example', true, false);
		$controller = $this->controller([$old]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturn($this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 'ROOT']]));

		$controller->recipientCertificate('bob@cloud.partner.example');
		$this->assertNull($old->getValueFields());
		$this->assertSame([], $this->partnerUpdates);
	}

	/**
	 * A partner that stops advertising the fields it stored is audited once, by field name, and logged as a
	 * warning; the same answer again changes nothing and audits nothing. The admin list shows what is recorded.
	 */
	public function testAChangeOfAPartnersValueFieldsIsAuditedOnce(): void {
		$partner = $this->partner('cloud.partner.example', true, false);
		$partner->setValueFields('["totp","passkeys"]');
		$controller = $this->controller([$partner]);
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('no longer advertises'), $this->callback(
				static fn (array $context): bool => $context['fields'] === 'totp, passkeys'
			));
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturn($this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 'ROOT']]));

		$controller->recipientCertificate('bob@cloud.partner.example');
		$controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertCount(1, $this->audits);
		$this->assertSame(AuditEventTypes::FEDERATION_PARTNER_VALUE_FIELDS_CHANGED, $this->audits[0]->getEventType());
		$this->assertSame(
			['partnerId' => $partner->getId(), 'from' => ['totp', 'passkeys'], 'to' => []],
			$this->audits[0]->getMetadata()
		);
		$this->assertNull($partner->jsonSerialize()['valueFields']);

		$partner->setValueFields('["totp","passkeys"]');
		$this->assertSame(['totp', 'passkeys'], $partner->jsonSerialize()['valueFields']);
	}

	public function testAFailedLookupRecordsNothing(): void {
		$partner = $this->partner('cloud.partner.example', true, false);
		$partner->setValueFields('["totp","passkeys"]');
		$controller = $this->controller([$partner]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturn($this->response(404, ['message' => 'Unknown recipient']));

		$controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertSame('["totp","passkeys"]', $partner->getValueFields());
		$this->assertSame([], $this->partnerUpdates);
	}

	public function testSendsNothingToAnInstanceThatIsNoPartner(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$response = $controller->recipientCertificate('bob@cloud.stranger.example');

		$this->assertSame(Http::STATUS_FORBIDDEN, $response->getStatus());
		$this->assertSame(['message' => 'not_a_partner'], $response->getData());
	}

	public function testSendsNothingToAPartnerWithoutOutbound(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', false, true)]);
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$this->assertSame(
			Http::STATUS_FORBIDDEN,
			$controller->recipientCertificate('bob@cloud.partner.example')->getStatus()
		);
	}

	public function testReportsAnUnknownRecipientOnThePartnersRefusal(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturn($this->response(404, ['message' => 'Unknown recipient']));

		$response = $controller->recipientCertificate('bob@cloud.partner.example');

		$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
		$this->assertSame(['message' => 'unknown_recipient'], $response->getData());
	}

	public function testReportsAnUnreachablePartnerOnAFailureOrAMalformedAnswer(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturnOnConsecutiveCalls(
				$this->throwException(new RuntimeException('timeout')),
				$this->response(200, ['certificate' => 7]),
			);

		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->recipientCertificate('bob@cloud.partner.example')->getStatus());
		$this->assertSame(Http::STATUS_BAD_GATEWAY, $controller->recipientCertificate('bob@cloud.partner.example')->getStatus());
	}

	public function testRefusesAChainOtherThanAnIntermediateAndARoot(): void {
		// Keepiq serves exactly its intermediate and root; any other chain never reaches the browser.
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->method('requestRemoteOcmEndpoint')
			->willReturnOnConsecutiveCalls(
				$this->response(200, ['certificate' => 'BOB', 'chain' => ['LEAF', 'INT', 'ROOT']]),
				$this->response(200, ['certificate' => 'BOB', 'chain' => ['ROOT']]),
				$this->response(200, ['certificate' => 'BOB', 'chain' => ['INT', 7]]),
				$this->response(200, ['certificate' => 'BOB', 'chain' => ['int' => 'INT', 'root' => 'ROOT']]),
			);
		$this->logger->expects($this->exactly(4))->method('warning')
			->with($this->stringContains('https://cloud.partner.example'));

		for ($i = 0; $i < 4; $i++) {
			$response = $controller->recipientCertificate('bob@cloud.partner.example');
			$this->assertSame(Http::STATUS_BAD_GATEWAY, $response->getStatus());
			$this->assertSame(['message' => 'bad_chain'], $response->getData());
		}
	}

	public function testAnInvalidCloudIdIsAnUnknownRecipient(): void {
		$controller = $this->controller([$this->partner('cloud.partner.example', true, false)]);
		$this->ocm->expects($this->never())->method('requestRemoteOcmEndpoint');

		$this->assertSame(Http::STATUS_NOT_FOUND, $controller->recipientCertificate('not-a-cloud-id')->getStatus());
	}

	public function testRefusesWithoutASession(): void {
		$controller = $this->controller([]);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn(null);
		$anonymous = new FederationController(
			$this->createMock(IRequest::class),
			$this->createMock(FederatedCertificateService::class),
			$session
		);

		$this->assertSame(Http::STATUS_UNAUTHORIZED, $anonymous->recipientCertificate('bob@cloud.partner.example')->getStatus());
	}

	/**
	 * No partner, no federation: the share dialog is told it may offer a
	 * federated recipient only while an outbound partner exists.
	 */
	public function testTheDialogIsOfferedFederationOnlyWithAnOutboundPartner(): void {
		$this->assertFalse($this->controller([])->status()->getData()['outbound']);
		$this->assertFalse($this->controller([$this->partner('cloud.partner.example', false, true)])->status()->getData()['outbound']);
		$this->assertTrue($this->controller([$this->partner('cloud.partner.example', true, false)])->status()->getData()['outbound']);
	}

	public function testAdvertisesTheKeepiqCapabilityOnlyWhileAPartnerExists(): void {
		$config = $this->createMock(IConfig::class);
		$withPartner = new FederationOcmDiscoveryListener(
			new FederationPartnerService(
				$this->partnerMapper([$this->partner('cloud.partner.example', true, true)]),
				$this->createMock(IClientService::class),
				$config
			)
		);
		$withoutPartner = new FederationOcmDiscoveryListener(
			new FederationPartnerService($this->partnerMapper([]), $this->createMock(IClientService::class), $config)
		);

		$advertising = $this->createMock(IOCMProvider::class);
		$advertising->expects($this->once())->method('setCapabilities')->with(['keepiq']);
		$withPartner->handle(new LocalOCMDiscoveryEvent($advertising));

		$silent = $this->createMock(IOCMProvider::class);
		$silent->expects($this->never())->method('setCapabilities');
		$withoutPartner->handle(new LocalOCMDiscoveryEvent($silent));
	}
}
