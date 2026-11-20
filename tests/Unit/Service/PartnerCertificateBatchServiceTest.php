<?php

/**
 * Unit tests for PartnerCertificateBatchService.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use InvalidArgumentException;
use OCA\Keepiq\Db\FederatedShare;
use OCA\Keepiq\Db\FederatedShareMapper;
use OCA\Keepiq\Kernel\Contracts\FederatedCertificateServiceInterface;
use OCA\Keepiq\Service\PartnerCertificateBatchService;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The batch answers only for the caller's own active partner shares, asks each recipient once, and refuses per
 * share with the single route's status and reason.
 *
 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#requirement-the-owner-looks-up-their-partner-shares-certificates-in-one-request
 */
#[CoversClass(PartnerCertificateBatchService::class)]
class PartnerCertificateBatchServiceTest extends TestCase {
	/** @var FederatedShareMapper&MockObject */
	private FederatedShareMapper $mapper;

	/** @var FederatedCertificateServiceInterface&MockObject */
	private FederatedCertificateServiceInterface $certificates;

	/** @var array<string,FederatedShare> */
	private array $rows = [];

	/**
	 * Fresh doubles over the rows in $this->rows.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->mapper = $this->createMock(FederatedShareMapper::class);
		$this->mapper->method('findById')->willReturnCallback(function (string $id): FederatedShare {
			if (isset($this->rows[$id]) === false) {
				throw new DoesNotExistException('none');
			}

			return $this->rows[$id];
		});
		$this->certificates = $this->createMock(FederatedCertificateServiceInterface::class);
	}//end setUp()

	/**
	 * Add a partner share row.
	 *
	 * @param string $id The share id
	 * @param string $owner The owner
	 * @param string $cloudId The recipient
	 * @param string $status The status
	 *
	 * @return void
	 */
	private function row(string $id, string $owner, string $cloudId, string $status = FederatedShare::STATUS_ACTIVE): void {
		$share = new FederatedShare();
		$share->setId($id);
		$share->setOwnerId($owner);
		$share->setRecipientCloudId($cloudId);
		$share->setStatus($status);
		$this->rows[$id] = $share;
	}//end row()

	/**
	 * The service under test.
	 *
	 * @return PartnerCertificateBatchService
	 */
	private function service(): PartnerCertificateBatchService {
		return new PartnerCertificateBatchService(shares: $this->mapper, certificates: $this->certificates);
	}//end service()

	/**
	 * A certificate answer for a cloud id.
	 *
	 * @param string $cloudId The recipient
	 *
	 * @return array<string,mixed>
	 */
	private static function answer(string $cloudId): array {
		return [
			'cloudId'                => $cloudId,
			'certificate'            => 'PEM '.$cloudId,
			'chain'                  => ['INT', 'ROOT'],
			'partnerRootFingerprint' => 'aa',
			'valueFields'            => [],
		];
	}//end answer()

	/**
	 * Every own active share gets its recipient's certificate, under its share id.
	 *
	 * @return void
	 */
	public function testOwnActiveSharesGetTheirCertificates(): void {
		$this->row('s1', 'alice', 'bob@a.example');
		$this->row('s2', 'alice', 'carol@b.example');
		$this->certificates->method('lookup')->willReturnCallback(
			static fn (string $cloudId, string $userId): array => self::answer($cloudId)
		);

		$result = $this->service()->lookup(shareIds: ['s1', 's2'], userId: 'alice');

		$this->assertSame(self::answer('bob@a.example'), $result['certificates']['s1']);
		$this->assertSame(self::answer('carol@b.example'), $result['certificates']['s2']);
		$this->assertSame([], $result['refused']);
	}//end testOwnActiveSharesGetTheirCertificates()

	/**
	 * Two shares to one recipient ask the partner once, and a repeated id is one share.
	 *
	 * @return void
	 */
	public function testEachRecipientIsAskedOnce(): void {
		$this->row('s1', 'alice', 'bob@a.example');
		$this->row('s2', 'alice', 'bob@a.example');
		$this->certificates->expects($this->once())
			->method('lookup')
			->with('bob@a.example', 'alice')
			->willReturn(self::answer('bob@a.example'));

		$result = $this->service()->lookup(shareIds: ['s1', 's2', 's1'], userId: 'alice');

		$this->assertSame(['s1', 's2'], array_keys($result['certificates']));
	}//end testEachRecipientIsAskedOnce()

	/**
	 * An unknown id, another user's share and a suspended share are refused alike, and no partner is asked.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-another-users-partner-share-is-not-looked-up
	 */
	public function testOtherSharesAreRefusedAlike(): void {
		$this->row('mallory', 'mallory', 'bob@a.example');
		$this->row('paused', 'alice', 'bob@a.example', FederatedShare::STATUS_SUSPENDED);
		$this->certificates->expects($this->never())->method('lookup');

		$result = $this->service()->lookup(shareIds: ['nope', 'mallory', 'paused'], userId: 'alice');

		$refusal = ['status' => Http::STATUS_NOT_FOUND, 'message' => PartnerCertificateBatchService::UNKNOWN_SHARE];
		$this->assertSame(['nope' => $refusal, 'mallory' => $refusal, 'paused' => $refusal], $result['refused']);
		$this->assertSame([], $result['certificates']);
	}//end testOtherSharesAreRefusedAlike()

	/**
	 * A partner that cannot be reached is refused for its own shares only, with the single route's status.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/sharing-atomic-share-save/specs/federated-sharing/spec.md#scenario-one-unreachable-partner-does-not-fail-the-others
	 */
	public function testOneUnreachablePartnerDoesNotFailTheOthers(): void {
		$this->row('s1', 'alice', 'bob@down.example');
		$this->row('s2', 'alice', 'carol@up.example');
		$this->row('s3', 'alice', 'dave@gone.example');
		$this->certificates->method('lookup')->willReturnCallback(
			static function (string $cloudId): array {
				if ($cloudId === 'bob@down.example') {
					throw new RuntimeException('partner_unreachable');
				}

				if ($cloudId === 'dave@gone.example') {
					throw new InvalidArgumentException('not_a_partner');
				}

				return self::answer($cloudId);
			}
		);

		$result = $this->service()->lookup(shareIds: ['s1', 's2', 's3'], userId: 'alice');

		$this->assertSame(['s2'], array_keys($result['certificates']));
		$this->assertSame(
			[
				's1' => ['status' => Http::STATUS_BAD_GATEWAY, 'message' => 'partner_unreachable'],
				's3' => ['status' => Http::STATUS_FORBIDDEN, 'message' => 'not_a_partner'],
			],
			$result['refused']
		);
	}//end testOneUnreachablePartnerDoesNotFailTheOthers()

	/**
	 * More than 100 distinct ids are refused before any row is read.
	 *
	 * @return void
	 */
	public function testMoreThanTheBoundIsRefused(): void {
		$this->mapper->expects($this->never())->method('findById');
		$ids = array_map(static fn (int $i): string => 'id'.$i, range(1, PartnerCertificateBatchService::MAX_SHARES + 1));

		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('too_many_shares');
		$this->service()->lookup(shareIds: $ids, userId: 'alice');
	}//end testMoreThanTheBoundIsRefused()

	/**
	 * The refusal statuses match the single route.
	 *
	 * @return void
	 */
	public function testRefusalStatus(): void {
		$this->assertSame(Http::STATUS_FORBIDDEN, PartnerCertificateBatchService::refusalStatus(new InvalidArgumentException('not_a_partner')));
		$this->assertSame(Http::STATUS_NOT_FOUND, PartnerCertificateBatchService::refusalStatus(new InvalidArgumentException('unknown_recipient')));
		$this->assertSame(Http::STATUS_CONFLICT, PartnerCertificateBatchService::refusalStatus(new RuntimeException('federation_unavailable')));
		$this->assertSame(Http::STATUS_BAD_GATEWAY, PartnerCertificateBatchService::refusalStatus(new RuntimeException('bad_chain')));
	}//end testRefusalStatus()
}//end class
