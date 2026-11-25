<?php

/**
 * Keepiq Root Renewal Notice Test
 *
 * The renewing side's notice (partner-root-renewal-announcement D2): after a renewal every partner with inbound
 * sharing on gets a signed OCM notification of its own type, tied to no share and carrying no certificate; a failed
 * one is retried on the schedule of share notifications and given up after as many attempts.
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
 * @spec openspec/changes/partner-root-renewal-announcement/specs/federated-sharing/spec.md#requirement-a-renewed-root-is-announced-to-partners
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Federation;

use DateTime;
use OCA\Keepiq\Db\FederationPartner;
use OCA\Keepiq\Service\FederatedNotificationDelivery;
use OCA\Keepiq\Service\FederationRootService;
use OCA\Keepiq\Service\RootRenewalNoticeDelivery;
use OCA\Keepiq\Service\RootRenewalNoticeSender;
use OCP\Federation\ICloudFederationFactory;
use OCP\Federation\ICloudFederationProviderManager;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

require_once __DIR__ . '/FederationFixtures.php';
require_once __DIR__ . '/OcmDoubles.php';

class RootRenewalNoticeTest extends TestCase {
	use FederationFixtures;

	/** @var list<array{0:string,1:array<string,mixed>}> Notices sent: the remote and the OCM message */
	private array $sent = [];

	/** @var array<string,int> Per remote, the status it answers; absent is 201, 0 throws */
	private array $answers = [];

	/** @var list<string> Warnings logged */
	private array $warnings = [];

	/** @var list<FederationPartner> The partner rows */
	private array $partners = [];

	/**
	 * Skip on a Nextcloud without the OCM APIs.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->skipWithoutFederation();
	}//end setUp()

	/**
	 * The delivery over the partner rows.
	 *
	 * @return RootRenewalNoticeDelivery
	 */
	private function delivery(): RootRenewalNoticeDelivery {
		$mapper = $this->partnerMapper($this->partners);
		$mapper->method('update')->willReturnArgument(0);
		$mapper->method('findDueRenewalNotices')->willReturnCallback(
			fn (DateTime $now): array => array_values(
				array_filter(
					$this->partners,
					static fn (FederationPartner $p): bool => $p->getRenewalNoticeNextAt() !== null && $p->getRenewalNoticeNextAt() <= $now
				)
			)
		);
		$factory = $this->createMock(ICloudFederationFactory::class);
		$factory->method('getCloudFederationNotification')->willReturnCallback(static fn () => new FakeCloudFederationNotification());
		$ocm = $this->createMock(ICloudFederationProviderManager::class);
		$ocm->method('sendCloudNotification')->willReturnCallback(
			function (string $remote, FakeCloudFederationNotification $notification): IResponse {
				$this->sent[] = [$remote, $notification->getMessage()];
				$status = ($this->answers[$remote] ?? 201);
				if ($status === 0) {
					throw new RuntimeException('connection refused');
				}

				$response = $this->createMock(IResponse::class);
				$response->method('getStatusCode')->willReturn($status);
				return $response;
			}
		);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message): void {
			$this->warnings[] = $message;
		});

		return new RootRenewalNoticeDelivery(
			partnerMapper: $mapper,
			sender: new RootRenewalNoticeSender(providerManager: $ocm, factory: $factory, cloudIdManager: $this->cloudIdManager()),
			root: $this->rootService($this->caMapper()),
			logger: $logger,
		);
	}//end delivery()

	public function testAnnouncingOnlyQueuesANoticeForEachPartnerWithInboundSharingOn(): void {
		$this->partners = [
			$this->partner('cloud.in.example', false, true),
			$this->partner('cloud.out.example', true, false),
			$this->partner('cloud.both.example', true, true),
		];
		$delivery = $this->delivery();
		$now = new DateTime('2026-11-25T12:00:00Z');

		$this->assertSame(2, $delivery->announce(now: $now));

		// Nothing is sent inside the renewal; each queued notice is due at once.
		$this->assertSame([], $this->sent);
		$this->assertEquals([$now, null, $now], array_map(static fn (FederationPartner $p) => $p->getRenewalNoticeNextAt(), $this->partners));
		$this->assertSame([0, null, 0], array_map(static fn (FederationPartner $p) => $p->getRenewalNoticeAttempts(), $this->partners));

		$this->assertSame(2, $delivery->retryDue(now: $now));

		$this->assertSame(['https://cloud.in.example', 'https://cloud.both.example'], array_column($this->sent, 0));
		foreach ($this->partners as $partner) {
			$this->assertNull($partner->getRenewalNoticeNextAt());
		}
	}

	public function testTheNoticeIsItsOwnTypeTiedToNoShareAndCarriesNoCertificate(): void {
		$this->partners = [$this->partner('cloud.in.example', false, true)];
		$delivery = $this->delivery();
		$now = new DateTime();

		$delivery->announce(now: $now);
		$delivery->retryDue(now: $now);

		$this->assertCount(1, $this->sent);
		$this->assertSame(
			[
				'notificationType' => RootRenewalNoticeDelivery::ROOT_RENEWED,
				'resourceType' => 'keepiq-secret',
				'providerId' => RootRenewalNoticeDelivery::NOTICE_ID,
				'notification' => [
					'sharedSecret' => RootRenewalNoticeDelivery::NOTICE_ID,
					'providerId' => RootRenewalNoticeDelivery::NOTICE_ID,
					'sender' => 'keepiq@cloud.here.example',
					'message' => RootRenewalNoticeDelivery::ROOT_RENEWED,
				],
			],
			$this->sent[0][1]
		);
		$this->assertStringNotContainsString('CERTIFICATE', (string)json_encode($this->sent[0][1]));
	}

	public function testAFailedNoticeIsRetriedWithAGrowingWaitAndThenGivenUp(): void {
		$this->partners = [$this->partner('cloud.in.example', false, true)];
		$this->answers['https://cloud.in.example'] = 0;
		$delivery = $this->delivery();
		$start = new DateTime('2026-11-25T12:00:00Z');

		$this->assertSame(1, $delivery->announce(now: $start));
		$this->assertSame(0, $delivery->retryDue(now: $start));
		$partner = $this->partners[0];
		$this->assertSame(1, $partner->getRenewalNoticeAttempts());
		$this->assertEquals((clone $start)->modify('+60 seconds'), $partner->getRenewalNoticeNextAt());

		// Not due yet: nothing is sent.
		$this->assertSame(0, $delivery->retryDue(now: (clone $start)->modify('+59 seconds')));
		$this->assertCount(1, $this->sent);

		$waits = [];
		$now = clone $start;
		while ($partner->getRenewalNoticeNextAt() !== null) {
			$waits[] = $partner->getRenewalNoticeNextAt()->getTimestamp() - $now->getTimestamp();
			$now = clone $partner->getRenewalNoticeNextAt();
			$delivery->retryDue(now: $now);
		}

		$this->assertSame([60, 120, 240, 480, 960], $waits);
		$this->assertSame(FederatedNotificationDelivery::MAX_ATTEMPTS, $partner->getRenewalNoticeAttempts());
		$this->assertCount(FederatedNotificationDelivery::MAX_ATTEMPTS, $this->sent);
		$this->assertStringContainsString('did not take the root renewal notice', implode("\n", $this->warnings));
	}

	/**
	 * Answers a partner may give that leave the notice to be retried: 400 is what Nextcloud answers when the
	 * partner's check could not read this server's discovery document yet.
	 *
	 * @return array<string,array{0:int}>
	 */
	public static function retriedAnswers(): array {
		return ['400' => [400], '500' => [500], '503' => [503], 'a connection error' => [0]];
	}

	/**
	 * @dataProvider retriedAnswers
	 *
	 * @param int $status The partner's answer to the first send
	 */
	public function testARetryThatGetsThroughClearsTheNotice(int $status): void {
		$this->partners = [$this->partner('cloud.in.example', false, true)];
		$this->answers['https://cloud.in.example'] = $status;
		$delivery = $this->delivery();
		$start = new DateTime('2026-11-25T12:00:00Z');
		$delivery->announce(now: $start);
		$this->assertSame(0, $delivery->retryDue(now: $start));
		$this->assertSame(1, $this->partners[0]->getRenewalNoticeAttempts());

		unset($this->answers['https://cloud.in.example']);
		$this->assertSame(1, $delivery->retryDue(now: (clone $start)->modify('+60 seconds')));

		$this->assertCount(2, $this->sent);
		$this->assertNull($this->partners[0]->getRenewalNoticeNextAt());
		$this->assertNull($this->partners[0]->getRenewalNoticeAttempts());
	}

	public function testAPartnerWhoseInboundWasTurnedOffGetsNoRetry(): void {
		$this->partners = [$this->partner('cloud.in.example', false, true)];
		$this->answers['https://cloud.in.example'] = 503;
		$delivery = $this->delivery();
		$start = new DateTime('2026-11-25T12:00:00Z');
		$delivery->announce(now: $start);
		$delivery->retryDue(now: $start);

		$this->partners[0]->setAllowInbound(false);
		$this->assertSame(0, $delivery->retryDue(now: (clone $start)->modify('+1 hour')));

		$this->assertCount(1, $this->sent);
		$this->assertNull($this->partners[0]->getRenewalNoticeNextAt());
	}
}
