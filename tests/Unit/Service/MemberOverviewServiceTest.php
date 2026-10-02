<?php

/**
 * Unit tests for MemberOverviewService (admin-member-overview-and-offboarding §2).
 *
 * @category Tests
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

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\EmergencyContactMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\TeamFolderMemberMapper;
use OCA\Keepiq\Service\MemberOverviewService;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Member overview: statuses, filters, paging and metadata-only rows.
 */
class MemberOverviewServiceTest extends TestCase {

	private IUserManager&MockObject $userManager;

	private EncryptionSuiteMapper&MockObject $suiteMapper;

	private SecretMapper&MockObject $secretMapper;

	private TeamFolderMemberMapper&MockObject $memberMapper;

	private EmergencyContactMapper&MockObject $contactMapper;

	private MemberOverviewService $service;

	/**
	 * Wire the service with mocked mappers.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->userManager = $this->createMock(originalClassName: IUserManager::class);
		$this->suiteMapper = $this->createMock(originalClassName: EncryptionSuiteMapper::class);
		$this->secretMapper = $this->createMock(originalClassName: SecretMapper::class);
		$this->memberMapper = $this->createMock(originalClassName: TeamFolderMemberMapper::class);
		$this->contactMapper = $this->createMock(originalClassName: EmergencyContactMapper::class);

		$this->service = new MemberOverviewService(
			userManager: $this->userManager,
			suiteMapper: $this->suiteMapper,
			secretMapper: $this->secretMapper,
			memberMapper: $this->memberMapper,
			contactMapper: $this->contactMapper,
		);
	}//end setUp()

	/**
	 * Build a user mock.
	 *
	 * @param string $uid The user id
	 * @param bool $enabled Whether the account is enabled
	 *
	 * @return IUser
	 */
	private function user(string $uid, bool $enabled = true): IUser {
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn($uid);
		$user->method('getDisplayName')->willReturn(ucfirst($uid));
		$user->method('isEnabled')->willReturn($enabled);
		return $user;
	}//end user()

	/**
	 * Build an active suite carrying key material, which must never leak.
	 *
	 * @param string $id The suite id
	 * @param string $owner The owner id
	 *
	 * @return EncryptionSuite
	 */
	private function suite(string $id, string $owner): EncryptionSuite {
		$suite = new EncryptionSuite();
		$suite->setId($id);
		$suite->setOwnerType('user');
		$suite->setOwnerId($owner);
		$suite->setCertificate('-----BEGIN CERTIFICATE-----SECRET');
		$suite->setPrivateKey('WRAPPED-PRIVATE-KEY');
		$suite->setCreatedAt(new DateTime('2026-09-01T10:00:00+00:00'));
		return $suite;
	}//end suite()

	/**
	 * Default lookups: alice active, carol compromised, dave revoked, bob none.
	 *
	 * @return void
	 */
	private function seedLookups(): void {
		$this->suiteMapper->method('findActiveByOwners')->willReturnCallback(
			fn (string $type, array $ids): array => in_array('alice', $ids, true)
				? ['alice' => $this->suite(id: 'suite-alice', owner: 'alice')]
				: []
		);
		$this->suiteMapper->method('latestInactiveStatusByOwners')->willReturn(
			['carol' => 'compromised', 'dave' => 'revoked']
		);
		$this->secretMapper->method('countByUserOwners')->willReturn(['alice' => 12]);
		$this->memberMapper->method('countUserMemberships')->willReturn(['alice' => 2]);
		$this->contactMapper->method('grantorsWithContact')->willReturn(['alice']);
	}//end seedLookups()

	/**
	 * Every row carries the documented fields and the right status, and a
	 * page costs ONE suite query through findActiveByOwners() (§2.1).
	 *
	 * @return void
	 */
	public function testOnePageResolvesStatusesWithOneSuiteQuery(): void {
		$this->userManager->method('searchDisplayName')->with('', 51, 0)->willReturn(
			[$this->user('alice'), $this->user('bob'), $this->user('carol'), $this->user('dave', false)]
		);
		$this->seedLookups();
		$this->suiteMapper->expects($this->once())->method('findActiveByOwners')
			->with('user', ['alice', 'bob', 'carol', 'dave']);

		$page = $this->service->list(status: '', search: '', limit: 50, offset: 0);

		$this->assertFalse($page['hasMore']);
		$byUser = array_column($page['results'], null, 'userId');
		$this->assertSame('active', $byUser['alice']['vaultStatus']);
		$this->assertSame('suite-alice', $byUser['alice']['activeSuiteId']);
		$this->assertSame('2026-09-01T10:00:00+00:00', $byUser['alice']['suiteCreatedAt']);
		$this->assertSame(12, $byUser['alice']['secretCount']);
		$this->assertSame(2, $byUser['alice']['teamFolderMemberships']);
		$this->assertTrue($byUser['alice']['hasEmergencyContact']);
		$this->assertSame('none', $byUser['bob']['vaultStatus']);
		$this->assertNull($byUser['bob']['activeSuiteId']);
		$this->assertSame(0, $byUser['bob']['secretCount']);
		$this->assertFalse($byUser['bob']['hasEmergencyContact']);
		$this->assertSame('compromised', $byUser['carol']['vaultStatus']);
		$this->assertSame('revoked', $byUser['dave']['vaultStatus']);
		$this->assertFalse($byUser['dave']['enabled']);
	}//end testOnePageResolvesStatusesWithOneSuiteQuery()

	/**
	 * The status filter keeps only matching rows (scenario "Administrator
	 * sees who has not set up a vault"), and the search reaches the user
	 * manager (scenario "Administrator reads the active suite id").
	 *
	 * @return void
	 */
	public function testStatusFilterAndSearch(): void {
		$this->userManager->method('searchDisplayName')->willReturnCallback(
			fn (string $search, int $limit, int $offset): array => match ($search) {
				'' => ($offset === 0 ? [$this->user('alice'), $this->user('bob')] : []),
				'alice' => [$this->user('alice')],
			}
		);
		$this->seedLookups();

		$none = $this->service->list(status: 'none', search: '', limit: 50, offset: 0);
		$this->assertSame(['bob'], array_column($none['results'], 'userId'));
		$this->assertNull($none['results'][0]['activeSuiteId']);

		$alice = $this->service->list(status: '', search: 'alice', limit: 50, offset: 0);
		$this->assertSame('active', $alice['results'][0]['vaultStatus']);
		$this->assertSame('suite-alice', $alice['results'][0]['activeSuiteId']);
	}//end testStatusFilterAndSearch()

	/**
	 * Paging: one extra user signals hasMore, the page is cut to the limit.
	 *
	 * @return void
	 */
	public function testPagingReportsHasMore(): void {
		$this->userManager->method('searchDisplayName')->with('', 3, 2)->willReturn(
			[$this->user('bob'), $this->user('carol'), $this->user('dave')]
		);
		$this->seedLookups();

		$page = $this->service->list(status: '', search: '', limit: 2, offset: 2);

		$this->assertTrue($page['hasMore']);
		$this->assertSame(['bob', 'carol'], array_column($page['results'], 'userId'));
	}//end testPagingReportsHasMore()

	/**
	 * §2.3: no row carries a certificate, a private key or any ciphertext
	 * field, even though the suite entity behind it holds both.
	 *
	 * @return void
	 */
	public function testRowsCarryNoKeyMaterial(): void {
		$this->userManager->method('searchDisplayName')->willReturn([$this->user('alice'), $this->user('bob')]);
		$this->seedLookups();

		$page = $this->service->list(status: '', search: '', limit: 50, offset: 0);
		$json = (string)json_encode($page);

		foreach ($page['results'] as $row) {
			foreach (['certificate', 'privateKey', 'key', 'login', 'additionalFields', 'name'] as $forbidden) {
				$this->assertArrayNotHasKey($forbidden, $row);
			}
		}

		$this->assertStringNotContainsString('WRAPPED-PRIVATE-KEY', $json);
		$this->assertStringNotContainsString('BEGIN CERTIFICATE', $json);
	}//end testRowsCarryNoKeyMaterial()

	/**
	 * Bad input is refused before any user is read.
	 *
	 * @return void
	 */
	public function testRejectsUnknownStatusAndOutOfRangePage(): void {
		$this->userManager->expects($this->never())->method('searchDisplayName');

		foreach ([['bogus', 50, 0], ['', 0, 0], ['', 201, 0], ['', 50, -1]] as [$status, $limit, $offset]) {
			try {
				$this->service->list(status: $status, search: '', limit: $limit, offset: $offset);
				$this->fail('Expected a refusal for ' . json_encode([$status, $limit, $offset]));
			} catch (InvalidArgumentException) {
				$this->addToAssertionCount(1);
			}
		}
	}//end testRejectsUnknownStatusAndOutOfRangePage()
}//end class
