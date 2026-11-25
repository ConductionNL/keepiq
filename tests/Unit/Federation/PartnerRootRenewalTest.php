<?php

/**
 * Keepiq Partner Root Renewal Test
 *
 * The receiving side of an announced root renewal (partner-root-renewal-announcement D3 to D6): a renewal notice from
 * a listed partner, or an announcement in its lookup answer, only starts the check; the partner's discovery document
 * is read again and every check must pass before anything is stored or anyone is notified. Accepting repeats the
 * checks and goes through the re-pin; reminders go out 7, 5, 3 and 1 day before the 30 days end, and the offer is
 * withdrawn after them.
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
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-an-announced-renewal-is-offered-to-administrators-after-its-signature-checks-out
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use DateTimeZone;
use InvalidArgumentException;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Db\FederationPartnerMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Event\Audit\AuditEventTypes;
use OCA\Keepiq\Exception\PartnerUnreachableException;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Keepiq\Service\FederationPartnerAuditTrail;
use OCA\Keepiq\Service\FederationPartnerService;
use OCA\Keepiq\Service\PartnerRootRenewalNoticeSigner;
use OCA\Keepiq\Service\PartnerRootRenewalNotifier;
use OCA\Keepiq\Service\PartnerRootRenewalOffers;
use OCA\Keepiq\Service\PartnerRootRenewalService;
use OCA\Keepiq\Service\PartnerRootRenewalVerifier;
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IConfig;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\OCM\IOCMDiscoveryService;
use OCP\Security\Signature\IIncomingSignedRequest;
use OCP\Share\Exceptions\ShareNotFound;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/RootRenewalFixtures.php';

class PartnerRootRenewalTest extends TestCase {
	use FederationFixtures;
	use RootRenewalFixtures;

	/** The partner's host. */
	private const HOST = 'cloud.partner.example';

	/** @var mixed The partner's discovery document; a callable is called on each fetch */
	private mixed $document = null;

	/** @var int How often the discovery document was fetched */
	private int $fetches = 0;

	/** @var list<FederationPartner> Partner rows written */
	private array $updated = [];

	/** @var list<array{0:string,1:string,2:array<string,mixed>}> Notifications: subject, recipient, params */
	private array $notified = [];

	/** @var list<Event> Audit events */
	private array $audited = [];

	/** @var list<string> Warnings logged */
	private array $warnings = [];

	/** @var string|null The origin the incoming request is signed by, null when unsigned */
	private ?string $signedBy = self::HOST;

	/** The current time of the service's clock. */
	private DateTime $now;

	/** The partner row. */
	private FederationPartner $partner;

	/** The partner service the renewal service under test re-pins through. */
	private FederationPartnerService $partnerService;

	/** The offers of the renewal service under test, which also run the hourly pass. */
	private PartnerRootRenewalOffers $offers;

	/** @var FederationPartner|null The row as stored now, when it changed since the service read it */
	private ?FederationPartner $stored = null;

	/** Whether the partner row has been removed, so reading it again finds nothing. */
	private bool $removed = false;

	/** Whether writing the partner row fails. */
	private bool $updateFails = false;

	/**
	 * A listed partner that pinned the previous root, and a clock at now.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->skipWithoutFederation();
		if (interface_exists(IIncomingSignedRequest::class) === false) {
			$this->markTestSkipped('Signed OCM requests need Nextcloud 33 or later.');
		}

		// A minute ahead, so a root a test generates later is already valid on this clock.
		$this->now = (new DateTime())->modify('+1 minute');
		$this->partner = $this->partner(self::HOST, true, true);
		$this->partner->setRootFingerprint(self::fingerprintOf(self::root('previous')['pem']));
	}//end setUp()

	/**
	 * The service under test over the one partner.
	 *
	 * @return PartnerRootRenewalService
	 */
	private function service(): PartnerRootRenewalService {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturnCallback(function (): string {
			$document = is_callable($this->document) === true ? ($this->document)() : $this->document;
			return (string)json_encode($document);
		});
		$client = $this->createMock(IClient::class);
		$client->method('get')->willReturnCallback(function () use ($response): IResponse {
			$this->fetches++;
			if ($this->document === 'unreachable') {
				throw new RuntimeException('connection refused');
			}

			return $response;
		});
		$clients = $this->createMock(IClientService::class);
		$clients->method('newClient')->willReturn($client);
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueBool')->willReturn(false);

		$mapper = $this->partnerMapper([$this->partner]);
		$mapper->method('findById')->willReturnCallback(
			fn (string $id): FederationPartner => ($id === $this->partner->getId() && $this->removed === false) ? ($this->stored ?? $this->partner) : throw new DoesNotExistException('none')
		);
		$mapper->method('update')->willReturnCallback(function (FederationPartner $partner): FederationPartner {
			if ($this->updateFails === true) {
				throw new RuntimeException('database gone');
			}

			$this->updated[] = $partner;
			return $partner;
		});
		$mapper->method('findWithOfferedRenewal')->willReturnCallback(
			fn (): array => ($this->partner->getPendingRootFingerprint() !== null) ? [$this->partner] : []
		);

		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (Event $event): void {
			$this->audited[] = $event;
		});

		$notifications = $this->createMock(NotificationServiceInterface::class);
		$notifications->method('notify')->willReturnCallback(
			function (string $subject, string $recipientId, array $params = []): bool {
				$this->notified[] = [$subject, $recipientId, $params];
				return true;
			}
		);
		$admins = [];
		foreach (['admin', 'second-admin'] as $uid) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
			$admins[] = $user;
		}
		$group = $this->createMock(IGroup::class);
		$group->method('getUsers')->willReturn($admins);
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('get')->with('admin')->willReturn($group);

		$ocm = $this->createMock(IOCMDiscoveryService::class);
		$ocm->method('getIncomingSignedRequest')->willReturnCallback(function (): ?IIncomingSignedRequest {
			if ($this->signedBy === null) {
				return null;
			}

			$signed = $this->createMock(IIncomingSignedRequest::class);
			$signed->method('getOrigin')->willReturn($this->signedBy);
			return $signed;
		});

		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(fn (): DateTime => clone $this->now);

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->warnings[] = $message . ' ' . (string)($context['reason'] ?? '');
		});

		$partners = new FederationPartnerService($mapper, $clients, $config, new FederationPartnerAuditTrail($dispatcher));
		$this->partnerService = $partners;
		$this->offers = new PartnerRootRenewalOffers(
			partnerMapper: $mapper,
			verifier: new PartnerRootRenewalVerifier($partners, $this->rootService($this->caMapper()), $time),
			admins: new PartnerRootRenewalNotifier($notifications, $groups, $logger),
			time: $time,
		);

		return new PartnerRootRenewalService(
			notices: new PartnerRootRenewalNoticeSigner($partners, $mapper, $ocm, $this->cloudIdManager()),
			offers: $this->offers,
			partners: $partners,
			logger: $logger,
		);
	}//end service()

	/**
	 * The partner's discovery document: it publishes `$published` as its root and carries `$renewal`.
	 *
	 * @param string $published The name of the root it publishes
	 * @param mixed $renewal Its `rootRenewal`, left out when null
	 *
	 * @return array<string,mixed>
	 */
	private function discoveryOf(string $published, mixed $renewal): array {
		$federation = ['enabled' => true, 'rootFingerprint' => self::fingerprintOf(self::root($published)['pem'])];
		if ($renewal !== null) {
			$federation['rootRenewal'] = $renewal;
		}

		return ['federation' => $federation];
	}//end discoveryOf()

	/**
	 * A time relative to the clock, as the announcement writes it.
	 *
	 * @param string $modifier A DateTime modifier, `-1 day`
	 *
	 * @return string
	 */
	private function at(string $modifier): string {
		return (clone $this->now)->modify($modifier)->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
	}//end at()

	/**
	 * A valid announcement: the pinned previous root signed the new root it publishes, a day ago.
	 *
	 * @return array<string,mixed>
	 */
	private function validDocument(): array {
		return $this->discoveryOf('new', self::announcement('previous', 'new', $this->at('-1 day')));
	}//end validDocument()

	/**
	 * A renewal notice as the partner sends it.
	 *
	 * @param string $sender Whom it names as sender
	 *
	 * @return array<string,string>
	 */
	private function notice(string $sender = 'keepiq@' . self::HOST): array {
		return [
			'sharedSecret' => RootRenewalNoticeDelivery::NOTICE_ID,
			'providerId' => RootRenewalNoticeDelivery::NOTICE_ID,
			'sender' => $sender,
			'message' => RootRenewalNoticeDelivery::ROOT_RENEWED,
		];
	}//end notice()

	/**
	 * Assert that nothing was stored and no one notified.
	 *
	 * @return void
	 */
	private function assertNothingOffered(): void {
		$this->assertNull($this->partner->getPendingRootFingerprint());
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->notified);
		$this->assertSame(self::fingerprintOf(self::root('previous')['pem']), $this->partner->getRootFingerprint());
	}//end assertNothingOffered()

	public function testAValidNoticeOffersTheRenewalToEveryAdmin(): void {
		$this->document = $this->validDocument();

		$this->service()->noticeReceived($this->notice());

		$newFingerprint = self::fingerprintOf(self::root('new')['pem']);
		$this->assertSame($newFingerprint, $this->partner->getPendingRootFingerprint());
		$this->assertSame(
			(new DateTime($this->at('-1 day')))->modify('+30 days')->getTimestamp(),
			$this->partner->getPendingRootWindowEndsAt()?->getTimestamp()
		);
		$this->assertSame([$this->partner], $this->updated);
		$params = ['partner' => 'https://' . self::HOST, 'partner_id' => $this->partner->getId(), 'days_left' => 29];
		$this->assertSame(
			[
				[PartnerRootRenewalOffers::SUBJECT_OFFERED, 'admin', $params],
				[PartnerRootRenewalOffers::SUBJECT_OFFERED, 'second-admin', $params],
			],
			$this->notified
		);
		// The pin moves only when an administrator accepts.
		$this->assertSame(self::fingerprintOf(self::root('previous')['pem']), $this->partner->getRootFingerprint());
		$this->assertSame([], $this->audited);
	}

	public function testAPartnerWithOutboundOffIsOfferedTheRenewalToo(): void {
		$this->partner->setAllowOutbound(false);
		$this->partner->setAllowInbound(false);
		$this->document = $this->validDocument();

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(self::fingerprintOf(self::root('new')['pem']), $this->partner->getPendingRootFingerprint());
	}

	public function testAValidAnnouncementInALookupAnswerOffersTheRenewal(): void {
		$this->document = $this->validDocument();

		$this->service()->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertSame(1, $this->fetches);
		$this->assertSame(self::fingerprintOf(self::root('new')['pem']), $this->partner->getPendingRootFingerprint());
		$this->assertCount(2, $this->notified);
	}

	public function testALookupForARenewalAlreadyOfferedStartsNoCheck(): void {
		$this->document = $this->validDocument();
		$this->partner->offerRenewal(self::fingerprintOf(self::root('new')['pem']), new DateTime(), new DateTime('+29 days'));

		$this->service()->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertSame(0, $this->fetches);
		$this->assertSame([], $this->notified);
		$this->assertSame([], $this->updated);
	}

	public function testALookupAnnouncingThePinnedRootOrNothingStartsNoCheck(): void {
		$this->document = $this->validDocument();
		$service = $this->service();

		$service->announcedInLookup($this->partner, ['newRoot' => self::root('previous')['pem']]);
		$service->announcedInLookup($this->partner, null);
		$service->announcedInLookup($this->partner, ['newRoot' => 'not a certificate']);
		$service->announcedInLookup($this->partner, 'garbage');

		$this->assertSame(0, $this->fetches);
		$this->assertNothingOffered();
	}

	public function testABatchOfLookupsChecksAPartnerOnce(): void {
		$this->document = $this->discoveryOf('new', self::announcement('other', 'new', $this->at('-1 day')));
		$service = $this->service();

		$service->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);
		$service->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertSame(1, $this->fetches);
		$this->assertNothingOffered();
	}

	public function testANewerValidAnnouncementReplacesTheOfferedOne(): void {
		$this->partner->offerRenewal(self::fingerprintOf(self::root('older-offer')['pem']), new DateTime('-5 days'), new DateTime('+20 days'));
		$this->partner->setPendingRootReminded(7);
		$this->document = $this->validDocument();

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(self::fingerprintOf(self::root('new')['pem']), $this->partner->getPendingRootFingerprint());
		$this->assertNull($this->partner->getPendingRootReminded());
		$this->assertCount(2, $this->notified);
	}

	public function testTheSameAnnouncementAgainNotifiesNoOneAgain(): void {
		$this->document = $this->validDocument();
		$this->service()->noticeReceived($this->notice());
		$this->notified = [];
		$this->updated = [];

		$this->service()->noticeReceived($this->notice());

		$this->assertSame([], $this->notified);
		$this->assertSame([], $this->updated);
	}

	/**
	 * An administrator re-pins the partner to the new root by hand while a lookup checks the announcement: the check
	 * verified against the old pin, so the row, read again, gets no offer equal to its pin and no one is notified.
	 *
	 * @return void
	 */
	public function testACheckRacingAManualRepinStoresNothingAndNotifiesNoOne(): void {
		$newFingerprint = self::fingerprintOf(self::root('new')['pem']);
		$valid = $this->validDocument();
		$this->document = function () use ($valid, $newFingerprint): array {
			$this->stored = clone $this->partner;
			$this->stored->setRootFingerprint($newFingerprint);
			return $valid;
		};

		$this->service()->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertSame(1, $this->fetches);
		$this->assertNull($this->stored?->getPendingRootFingerprint());
		$this->assertSame($newFingerprint, $this->stored?->getRootFingerprint());
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->notified);
	}

	/**
	 * A notice and a lookup check the same announcement at the same time: the one that stores second finds the same
	 * offer on the row, read again, and notifies no one a second time.
	 *
	 * @return void
	 */
	public function testACheckFindingTheSameOfferStoredMeanwhileNotifiesNoOneAgain(): void {
		$newFingerprint = self::fingerprintOf(self::root('new')['pem']);
		$renewedAt = $this->at('-1 day');
		$valid = $this->discoveryOf('new', self::announcement('previous', 'new', $renewedAt));
		$this->document = function () use ($valid, $newFingerprint, $renewedAt): array {
			$this->stored = clone $this->partner;
			$this->stored->offerRenewal($newFingerprint, new DateTime(), (new DateTime($renewedAt))->modify('+30 days'));
			return $valid;
		};

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(1, $this->fetches);
		$this->assertSame($newFingerprint, $this->stored?->getPendingRootFingerprint());
		$this->assertNull($this->partner->getPendingRootFingerprint());
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->notified);
	}

	/**
	 * Every check of D3 that can fail, with the discovery document that makes it fail. Each stores nothing and
	 * notifies no one.
	 *
	 * @return array<string,array{0:callable(self):mixed,1:string}>
	 */
	public static function failingChecks(): array {
		return [
			'no announcement' => [static fn (self $t): mixed => $t->discoveryOf('new', null), 'no_announcement'],
			'an announcement without a signature' => [
				static function (self $t): mixed {
					$announcement = self::announcement('previous', 'new', $t->at('-1 day'));
					unset($announcement['signature']);
					return $t->discoveryOf('new', $announcement);
				},
				'no_announcement',
			],
			'the previous root is not the pinned one' => [
				static fn (self $t): mixed => $t->discoveryOf('new', self::announcement('other', 'new', $t->at('-1 day'))),
				'previous_root_not_pinned',
			],
			'signed by another root than the previous one' => [
				static fn (self $t): mixed => $t->discoveryOf('new', self::announcement('previous', 'new', $t->at('-1 day'), 'other')),
				'signature_invalid',
			],
			'a signature that is not base64' => [
				static function (self $t): mixed {
					$announcement = self::announcement('previous', 'new', $t->at('-1 day'));
					$announcement['signature'] = '***';
					return $t->discoveryOf('new', $announcement);
				},
				'signature_invalid',
			],
			'the new root is not the root the document publishes' => [
				static fn (self $t): mixed => $t->discoveryOf('other', self::announcement('previous', 'new', $t->at('-1 day'))),
				'not_the_published_root',
			],
			'the new root is no CA certificate' => [
				static function (self $t): mixed {
					self::root('leaf', 'keepiq_leaf');
					return $t->discoveryOf('leaf', self::announcement('previous', 'leaf', $t->at('-1 day')));
				},
				'not_a_valid_ca',
			],
			'the new fingerprint is the pin' => [
				static fn (self $t): mixed => $t->discoveryOf('previous', self::announcement('previous', 'previous', $t->at('-1 day'))),
				'already_pinned',
			],
			'the renewal time is not ISO 8601 UTC' => [
				static fn (self $t): mixed => $t->discoveryOf('new', self::announcement('previous', 'new', 'yesterday')),
				'renewal_time_invalid',
			],
			'the renewal time is in the future' => [
				static fn (self $t): mixed => $t->discoveryOf('new', self::announcement('previous', 'new', $t->at('+1 hour'))),
				'renewal_in_the_future',
			],
			'the renewal is 30 days old' => [
				static fn (self $t): mixed => $t->discoveryOf('new', self::announcement('previous', 'new', $t->at('-30 days'))),
				'renewal_too_old',
			],
			'federation is off at the partner' => [
				static fn (self $t): mixed => ['federation' => ['enabled' => false, 'rootFingerprint' => self::fingerprintOf(self::root('new')['pem'])]],
				'does not offer Keepiq federation',
			],
		];
	}

	/**
	 * @dataProvider failingChecks
	 *
	 * @param callable(self):mixed $document The partner's discovery document
	 * @param string $reason The check that fails, as logged
	 */
	public function testANoticeFailingACheckStoresNothingAndNotifiesNoOne(callable $document, string $reason): void {
		$this->document = $document($this);

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(1, $this->fetches);
		$this->assertNothingOffered();
		$this->assertStringContainsString($reason, implode("\n", $this->warnings));
	}

	/**
	 * @dataProvider failingChecks
	 *
	 * @param callable(self):mixed $document The partner's discovery document
	 * @param string $reason The check that fails, as logged
	 */
	public function testALookupAnnouncementFailingACheckStoresNothingAndNotifiesNoOne(callable $document, string $reason): void {
		$this->document = $document($this);

		$this->service()->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertNothingOffered();
		$this->assertStringContainsString($reason, implode("\n", $this->warnings));
	}

	public function testANewRootNoLongerValidNowIsRefused(): void {
		self::root('short', 'keepiq_root', 1);
		$this->now = (new DateTime())->modify('+3 days');
		$this->document = $this->discoveryOf('short', self::announcement('previous', 'short', $this->at('-1 day')));

		$this->service()->noticeReceived($this->notice());

		$this->assertNothingOffered();
		$this->assertStringContainsString('not_a_valid_ca', implode("\n", $this->warnings));
	}

	public function testANoticeWhoseCheckCannotReadTheDocumentIsRefusedSoTheSenderRetries(): void {
		$this->document = 'unreachable';

		try {
			$this->service()->noticeReceived($this->notice());
			$this->fail('The notice was taken');
		} catch (PartnerUnreachableException $exception) {
			// Not ShareNotFound: Nextcloud answers this one with 400 without throttling the partner.
			$this->assertNotInstanceOf(ShareNotFound::class, $exception);
		}

		$this->assertSame(1, $this->fetches);
		$this->assertNothingOffered();
		$this->assertStringContainsString('could not be reached', implode("\n", $this->warnings));
	}

	public function testALookupWhoseCheckCannotReadTheDocumentStoresNothingAndGoesOn(): void {
		$this->document = 'unreachable';

		$this->service()->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertNothingOffered();
		$this->assertStringContainsString('could not be reached', implode("\n", $this->warnings));
	}

	/**
	 * @dataProvider failingChecks
	 *
	 * @param callable(self):mixed $document The partner's discovery document
	 * @param string $reason The check that fails, as logged
	 */
	public function testAFailingCheckWithdrawsAnOfferedRenewal(callable $document, string $reason): void {
		$this->partner->offerRenewal(self::fingerprintOf(self::root('older-offer')['pem']), new DateTime('-5 days'), new DateTime('+20 days'));
		$this->partner->setPendingRootReminded(7);
		$this->document = $document($this);

		$this->service()->noticeReceived($this->notice());

		$this->assertNull($this->partner->getPendingRootFingerprint());
		$this->assertNull($this->partner->getPendingRootWindowEndsAt());
		$this->assertNull($this->partner->getPendingRootReminded());
		$this->assertSame([$this->partner], $this->updated);
		$this->assertSame([], $this->notified);
		$this->assertSame(self::fingerprintOf(self::root('previous')['pem']), $this->partner->getRootFingerprint());
		$this->assertStringContainsString($reason, implode("\n", $this->warnings));
	}

	public function testAFailingWithdrawStillLogsTheRefusal(): void {
		$this->partner->offerRenewal(self::fingerprintOf(self::root('older-offer')['pem']), new DateTime('-5 days'), new DateTime('+20 days'));
		$this->document = $this->discoveryOf('new', null);
		$this->updateFails = true;

		try {
			$this->service()->noticeReceived($this->notice());
			$this->fail('The failing withdraw was not raised');
		} catch (RuntimeException $exception) {
			$this->assertSame('database gone', $exception->getMessage());
		}

		$this->assertStringContainsString('no_announcement', implode("\n", $this->warnings));
		$this->assertSame([], $this->notified);
	}

	public function testACheckForAPartnerRemovedMeanwhileStoresNothingAndNotifiesNoOne(): void {
		$valid = $this->validDocument();
		$this->document = function () use ($valid): array {
			$this->removed = true;
			return $valid;
		};

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(1, $this->fetches);
		$this->assertNothingOffered();
	}

	public function testADocumentThatCannotBeReadKeepsAnOfferedRenewal(): void {
		$offered = self::fingerprintOf(self::root('older-offer')['pem']);
		$this->partner->offerRenewal($offered, new DateTime('-5 days'), new DateTime('+20 days'));
		$this->document = 'unreachable';

		$this->service()->announcedInLookup($this->partner, ['newRoot' => self::root('new')['pem']]);

		$this->assertSame($offered, $this->partner->getPendingRootFingerprint());
		$this->assertSame([], $this->updated);
	}

	/**
	 * The certificate inside a PEM.
	 *
	 * @param string $pem A PEM certificate
	 *
	 * @return string Its base64 body
	 */
	private static function bodyOf(string $pem): string {
		return trim((string)preg_replace('/-----[^-]+-----/', '', $pem));
	}

	/**
	 * A PEM block of the given label around a certificate.
	 *
	 * @param string $pem A PEM certificate
	 * @param string $label The block label, `X509 CERTIFICATE`
	 *
	 * @return string
	 */
	private static function labelled(string $pem, string $label): string {
		return "-----BEGIN {$label}-----\n" . self::bodyOf($pem) . "\n-----END {$label}-----\n";
	}

	/**
	 * PEM texts that are not exactly one `CERTIFICATE` block, each built from the named real root and a decoy root.
	 * The second value says whether OpenSSL's PEM reader takes the decoy from it while a regex for the first
	 * `CERTIFICATE` block takes the real root. Every one must be refused.
	 *
	 * @return array<string,array{0:callable(string,string):string,1:bool}>
	 */
	public static function malformedPems(): array {
		$pem = static fn (string $name): string => self::root($name)['pem'];
		return [
			'a legacy X509 CERTIFICATE block before the real one' => [
				static fn (string $real, string $decoy): string => self::labelled($pem($decoy), 'X509 CERTIFICATE') . $pem($real),
				true,
			],
			'a second CERTIFICATE block after the real one' => [
				static fn (string $real, string $decoy): string => $pem($real) . $pem($decoy),
				false,
			],
			'a PUBLIC KEY block before the real one' => [
				static fn (string $real, string $decoy): string => (string)openssl_pkey_get_details(self::root($decoy)['key'])['key'] . $pem($real),
				false,
			],
			'text before the real one' => [static fn (string $real, string $decoy): string => "Renewed root:\n" . $pem($real), false],
			'text after the real one' => [static fn (string $real, string $decoy): string => $pem($real) . "-- end\n", false],
			'a TRUSTED CERTIFICATE block before the real one' => [
				static fn (string $real, string $decoy): string => self::labelled($pem($decoy), 'TRUSTED CERTIFICATE') . $pem($real),
				false,
			],
			'the real one as a TRUSTED CERTIFICATE block' => [
				static fn (string $real, string $decoy): string => self::labelled($pem($real), 'TRUSTED CERTIFICATE'),
				false,
			],
			'base64 that does not decode' => [
				static fn (string $real, string $decoy): string => (string)preg_replace('/-----\nMII/', "-----\nMI=I", $pem($real), 1),
				false,
			],
			'bytes after the certificate inside the block' => [
				static fn (string $real, string $decoy): string => "-----BEGIN CERTIFICATE-----\n"
					. chunk_split(base64_encode(base64_decode(self::bodyOf($pem($real))) . "\0\0"), 64, "\n")
					. "-----END CERTIFICATE-----\n",
				false,
			],
			'an empty string' => [static fn (string $real, string $decoy): string => '', false],
		];
	}

	/**
	 * @dataProvider malformedPems
	 *
	 * @param callable(string,string):string $mangle Builds the announced previous root
	 * @param bool $readsTheDecoy Whether OpenSSL reads the decoy from it
	 */
	public function testAMalformedPreviousRootIsRefused(callable $mangle, bool $readsTheDecoy): void {
		// Signed by the key OpenSSL would take from the text, so only the parsing stands in the way.
		$signer = $readsTheDecoy === true ? 'attacker' : 'previous';
		$announcement = self::announcement('previous', 'new', $this->at('-1 day'), $signer);
		$announcement['previousRoot'] = $mangle('previous', 'attacker');
		$this->document = $this->discoveryOf('new', $announcement);

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(1, $this->fetches);
		$this->assertNothingOffered();
		$this->assertStringContainsString('previous_root_malformed', implode("\n", $this->warnings));
	}

	/**
	 * @dataProvider malformedPems
	 *
	 * @param callable(string,string):string $mangle Builds the announced new root
	 * @param bool $readsTheDecoy Whether OpenSSL reads the decoy from it
	 */
	public function testAMalformedNewRootIsRefused(callable $mangle, bool $readsTheDecoy): void {
		// The real root is signed and published. Where OpenSSL reads the decoy, the real one is a leaf, so only the
		// decoy in front of it would pass the CA check.
		self::root('leaf', 'keepiq_leaf');
		$real = $readsTheDecoy === true ? 'leaf' : 'new';
		$announcement = self::announcement('previous', $real, $this->at('-1 day'));
		$announcement['newRoot'] = $mangle($real, $readsTheDecoy === true ? 'new' : 'attacker');
		$this->document = $this->discoveryOf($real, $announcement);

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(1, $this->fetches);
		$this->assertNothingOffered();
		$this->assertStringContainsString('new_root_malformed', implode("\n", $this->warnings));
	}

	public function testAWellFormedPemWithCrlfLineEndsAndSurroundingWhitespaceIsAccepted(): void {
		$announcement = self::announcement('previous', 'new', $this->at('-1 day'));
		$announcement['previousRoot'] = "\n  " . str_replace("\n", "\r\n", self::root('previous')['pem']) . "\n\n";
		$this->document = $this->discoveryOf('new', $announcement);

		$this->service()->noticeReceived($this->notice());

		$this->assertSame(self::fingerprintOf(self::root('new')['pem']), $this->partner->getPendingRootFingerprint());
	}

	/**
	 * Notices that no listed partner signed: each is ignored and logged, and nothing is read, stored or notified.
	 *
	 * @return array<string,array{0:string,1:string|null}>
	 */
	public static function strangerNotices(): array {
		return [
			'a sender that is no partner' => ['keepiq@cloud.stranger.example', 'cloud.stranger.example'],
			'no sender' => ['', self::HOST],
			'an unsigned notice' => ['keepiq@' . self::HOST, null],
			'signed by another host than the partner it names' => ['keepiq@' . self::HOST, 'cloud.stranger.example'],
		];
	}

	/**
	 * @dataProvider strangerNotices
	 *
	 * @param string $sender The sender the notice names
	 * @param string|null $signedBy Who signed it
	 */
	public function testANoticeNoListedPartnerSignedIsIgnoredAndLogged(string $sender, ?string $signedBy): void {
		$this->signedBy = $signedBy;
		$this->document = $this->validDocument();

		try {
			$this->service()->noticeReceived($this->notice(sender: $sender));
			$this->fail('The notice was not refused');
		} catch (ShareNotFound) {
		}

		$this->assertSame(0, $this->fetches);
		$this->assertNothingOffered();
		$this->assertStringContainsString('ignored a root renewal notice', implode("\n", $this->warnings));
	}

	public function testTheNoticeMustBeSignedByTheListedPartnerItNames(): void {
		$service = $this->service();

		$this->assertSame('keepiq@' . self::HOST, $service->noticeIdentity($this->notice()));
		$this->assertSame('', $service->noticeIdentity($this->notice(sender: 'keepiq@cloud.stranger.example')));
		$this->assertSame('', $service->noticeIdentity(['sender' => ['not', 'a', 'string']]));
		$this->assertStringContainsString('ignored a root renewal notice', implode("\n", $this->warnings));
	}

	/**
	 * Offer the valid renewal through a notice, then clear what that recorded.
	 *
	 * @return PartnerRootRenewalService
	 */
	private function offered(): PartnerRootRenewalService {
		$this->document = $this->validDocument();
		$service = $this->service();
		$service->noticeReceived($this->notice());
		$this->fetches = 0;
		$this->updated = [];
		$this->notified = [];

		return $service;
	}

	public function testAcceptingTheOfferedRenewalRepinsAndWritesTheAuditEntry(): void {
		$service = $this->offered();
		$newFingerprint = self::fingerprintOf(self::root('new')['pem']);
		$pinned = $this->partner->getRootFingerprint();

		$result = $service->accept($this->partner->getId(), strtoupper($newFingerprint), 'admin');

		$this->assertTrue($result['changed']);
		$this->assertSame($newFingerprint, $this->partner->getRootFingerprint());
		$this->assertNull($this->partner->getPendingRootFingerprint());
		// Read once for the checks, once by the re-pin.
		$this->assertSame(2, $this->fetches);
		$this->assertCount(1, $this->audited);
		$this->assertInstanceOf(AuditEvent::class, $this->audited[0]);
		$this->assertSame(AuditEventTypes::FEDERATION_PARTNER_REPINNED, $this->audited[0]->getEventType());
		$this->assertSame('admin', $this->audited[0]->getActorId());
		$this->assertSame(
			['partnerId' => $this->partner->getId(), 'oldFingerprint' => $pinned, 'newFingerprint' => $newFingerprint],
			$this->audited[0]->getMetadata()
		);
	}

	/**
	 * Accept the offered renewal and return the refusal.
	 *
	 * @param PartnerRootRenewalService $service The service
	 *
	 * @return InvalidArgumentException
	 */
	private function refusedAccept(PartnerRootRenewalService $service): InvalidArgumentException {
		try {
			$service->accept($this->partner->getId(), self::fingerprintOf(self::root('new')['pem']), 'admin');
		} catch (InvalidArgumentException $exception) {
			return $exception;
		}

		$this->fail('The accept was not refused');
	}

	/**
	 * Accepts refused because the announcement no longer passes, with the partner's document at the time of accepting.
	 *
	 * @return array<string,array{0:callable(self):mixed}>
	 */
	public static function failedAnnouncements(): array {
		return [
			'the announcement vanished' => [static fn (self $t): mixed => $t->discoveryOf('new', null)],
			'the announcement is now signed by another root' => [
				static fn (self $t): mixed => $t->discoveryOf('new', self::announcement('previous', 'new', $t->at('-1 day'), 'other')),
			],
		];
	}

	/**
	 * @dataProvider failedAnnouncements
	 *
	 * @param callable(self):mixed $document The partner's discovery document when the administrator accepts
	 */
	public function testAcceptingAnAnnouncementThatNoLongerPassesIsRefusedAndWithdrawsTheOffer(callable $document): void {
		$service = $this->offered();
		$pinned = $this->partner->getRootFingerprint();
		$this->document = $document($this);

		$exception = $this->refusedAccept($service);

		$this->assertSame('The partner no longer announces this renewed certificate', $exception->getMessage());
		$this->assertSame($pinned, $this->partner->getRootFingerprint());
		$this->assertNull($this->partner->getPendingRootFingerprint());
		$this->assertSame([$this->partner], $this->updated);
		$this->assertSame([], $this->notified);
		$this->assertSame([], $this->audited);
	}

	public function testAcceptingWhenAnotherRenewalIsAnnouncedOffersThatOneAndRefuses(): void {
		$service = $this->offered();
		$pinned = $this->partner->getRootFingerprint();
		$this->document = $this->discoveryOf('another', self::announcement('previous', 'another', $this->at('-1 hour')));

		$exception = $this->refusedAccept($service);

		$this->assertSame('The partner no longer announces this renewed certificate', $exception->getMessage());
		$this->assertSame($pinned, $this->partner->getRootFingerprint());
		$this->assertSame(self::fingerprintOf(self::root('another')['pem']), $this->partner->getPendingRootFingerprint());
		$this->assertSame([PartnerRootRenewalOffers::SUBJECT_OFFERED, PartnerRootRenewalOffers::SUBJECT_OFFERED], array_column($this->notified, 0));
		$this->assertSame([], $this->audited);
	}

	public function testAcceptingWhenThePartnerCannotBeReachedIsRefusedAndKeepsTheOffer(): void {
		$service = $this->offered();
		$pinned = $this->partner->getRootFingerprint();
		$this->document = 'unreachable';

		$exception = $this->refusedAccept($service);

		$this->assertInstanceOf(PartnerUnreachableException::class, $exception);
		$this->assertSame('The partner could not be reached', $exception->getMessage());
		$this->assertSame($pinned, $this->partner->getRootFingerprint());
		$this->assertSame(self::fingerprintOf(self::root('new')['pem']), $this->partner->getPendingRootFingerprint());
		$this->assertSame([], $this->updated);
		$this->assertSame([], $this->audited);
	}

	public function testARepinToTheFingerprintAlreadyPinnedWithdrawsAnOfferedRenewal(): void {
		$this->offered();
		$pinned = (string)$this->partner->getRootFingerprint();
		$this->document = $this->discoveryOf('previous', null);

		$result = $this->partnerService->repin($this->partner->getId(), $pinned, 'admin');

		$this->assertFalse($result['changed']);
		$this->assertSame($pinned, $this->partner->getRootFingerprint());
		$this->assertNull($this->partner->getPendingRootFingerprint());
		$this->assertSame([$this->partner], $this->updated);
		$this->assertSame([], $this->audited);
	}

	public function testAcceptingAFingerprintThatIsNotOfferedReadsNothing(): void {
		$service = $this->offered();

		foreach (['', str_repeat('ee', 32)] as $fingerprint) {
			try {
				$service->accept($this->partner->getId(), $fingerprint, 'admin');
				$this->fail('The accept was not refused');
			} catch (InvalidArgumentException $exception) {
				$this->assertStringContainsString('No renewed certificate', $exception->getMessage());
			}
		}

		$this->assertSame(0, $this->fetches);
		$this->assertSame([], $this->audited);
	}

	public function testAcceptingAfterTheWindowEndedIsRefused(): void {
		$service = $this->offered();
		$this->now = (new DateTime($this->at('-1 day')))->modify('+30 days');

		$this->expectException(InvalidArgumentException::class);
		try {
			$service->accept($this->partner->getId(), self::fingerprintOf(self::root('new')['pem']), 'admin');
		} finally {
			$this->assertSame(0, $this->fetches);
			$this->assertSame([], $this->audited);
		}
	}

	public function testAcceptingForAnUnknownPartnerIsNotFound(): void {
		$this->expectException(DoesNotExistException::class);
		$this->service()->accept('p-unknown', str_repeat('cd', 32), 'admin');
	}

	/**
	 * Run the hourly pass at `$daysBeforeEnd` days before the window ends and return the reminders it sent.
	 *
	 * @param DateTime $endsAt The end of the window
	 * @param float $daysBeforeEnd How long before the end the pass runs
	 *
	 * @return list<int> The `days_left` of every reminder, one per admin
	 */
	private function passAt(DateTime $endsAt, float $daysBeforeEnd): array {
		$this->notified = [];
		$now = (clone $endsAt)->modify('-' . (int)round($daysBeforeEnd * 86400) . ' seconds');
		$this->offers->remindAndExpire($now);

		return array_map(
			static function (array $call): int {
				TestCase::assertSame(PartnerRootRenewalOffers::SUBJECT_REMINDER, $call[0]);
				return $call[2]['days_left'];
			},
			$this->notified
		);
	}

	public function testRemindersGoOutSevenFiveThreeAndOneDayBeforeTheEndOnceEach(): void {
		$this->offered();
		$endsAt = $this->partner->getPendingRootWindowEndsAt();
		$this->assertNotNull($endsAt);

		$this->assertSame([], $this->passAt($endsAt, 8));
		$this->assertSame([7, 7], $this->passAt($endsAt, 7));
		$this->assertSame([], $this->passAt($endsAt, 6.5));
		$this->assertSame([], $this->passAt($endsAt, 6));
		$this->assertSame([5, 5], $this->passAt($endsAt, 5));
		$this->assertSame([], $this->passAt($endsAt, 4));
		$this->assertSame([3, 3], $this->passAt($endsAt, 3));
		$this->assertSame([], $this->passAt($endsAt, 2));
		$this->assertSame([1, 1], $this->passAt($endsAt, 1));
		$this->assertSame([], $this->passAt($endsAt, 0.5));
		$this->assertSame(self::fingerprintOf(self::root('new')['pem']), $this->partner->getPendingRootFingerprint());
	}

	public function testAPassAfterMissedDaysSendsOnlyTheReminderDueNow(): void {
		$this->offered();
		$endsAt = $this->partner->getPendingRootWindowEndsAt();
		$this->assertNotNull($endsAt);

		$this->assertSame([3, 3], $this->passAt($endsAt, 3));
		$this->assertSame([], $this->passAt($endsAt, 5));
		$this->assertSame([1, 1], $this->passAt($endsAt, 1));
	}

	public function testAHourlyPassJustAfterOneThatRanShortlyBeforeTheOneDayMarkSendsTheOneDayReminder(): void {
		$this->offered();
		$endsAt = $this->partner->getPendingRootWindowEndsAt();
		$this->assertNotNull($endsAt);
		$this->passAt($endsAt, 3);

		// A minute before one day is left, two days round up: the 3-day reminder, already sent.
		$this->assertSame([], $this->passAt($endsAt, 1 + 60 / 86400));
		$this->assertSame([1, 1], $this->passAt($endsAt, 1 + 60 / 86400 - 1 / 24));
	}

	public function testAnOfferWithSixDaysLeftCountsAsTheSevenDayReminder(): void {
		$this->document = $this->discoveryOf('new', self::announcement('previous', 'new', $this->at('-24 days')));
		$this->service()->noticeReceived($this->notice());
		$this->assertSame([6, 6], array_column(array_column($this->notified, 2), 'days_left'));
		$this->assertSame(7, $this->partner->getPendingRootReminded());
		$endsAt = $this->partner->getPendingRootWindowEndsAt();
		$this->assertNotNull($endsAt);

		$this->assertSame([], $this->passAt($endsAt, 6));
		$this->assertSame([5, 5], $this->passAt($endsAt, 5));
	}

	public function testAnOfferWithMoreThanSevenDaysLeftLeavesEveryReminderToCome(): void {
		$this->offered();

		$this->assertNull($this->partner->getPendingRootReminded());
	}

	public function testTheOfferIsWithdrawnWhenTheThirtyDaysEnd(): void {
		$this->offered();
		$endsAt = $this->partner->getPendingRootWindowEndsAt();
		$this->assertNotNull($endsAt);

		$this->assertSame([], $this->passAt($endsAt, 0));

		$this->assertNull($this->partner->getPendingRootFingerprint());
		$this->assertNull($this->partner->getPendingRootWindowEndsAt());
		$this->assertSame([$this->partner], $this->updated);
		$this->assertNull($this->partner->jsonSerialize()['pendingRenewal']);
		// The manual re-pin stays: the pin itself is untouched.
		$this->assertSame(self::fingerprintOf(self::root('previous')['pem']), $this->partner->getRootFingerprint());
	}
}
