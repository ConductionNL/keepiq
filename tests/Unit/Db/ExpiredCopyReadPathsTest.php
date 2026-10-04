<?php

/**
 * An expired recipient copy is served on no read path, against a real database.
 *
 * The list, the extension match, unified search and the offline manifest
 * filter expired copies in SQL (`SecretMapper::excludeAccessExpired`), so a
 * mocked query builder would pass just as happily without the filter. These
 * tests write real rows one second past their end and read them back through
 * each path's own entry point (sharing-use-only-and-expiring-shares task 5.1).
 *
 * Like the other database-backed tests here, they skip on a bare checkout and
 * run against an installed instance.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/expiring-shares/spec.md#requirement-the-server-stops-serving-an-expired-copy-at-its-end-date
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use OCA\Keepiq\Controller\ExtensionController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Search\SecretSearchProvider;
use OCA\Keepiq\Service\AdminSettingsService;
use OCA\Keepiq\Service\OfflineManifestService;
use OCA\Keepiq\Service\SecretService;
use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Search\ISearchQuery;
use PHPUnit\Framework\TestCase;

/**
 * Every recipient read path leaves out a copy one second past its end.
 */
class ExpiredCopyReadPathsTest extends TestCase {
	/**
	 * The live database connection.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * The real secret mapper.
	 *
	 * @var SecretMapper|null
	 */
	private ?SecretMapper $mapper = null;

	/**
	 * The recipient this test invents; no account is created for it.
	 *
	 * @var string
	 */
	private string $uid = '';

	/**
	 * A word every row of this test carries in its name and host.
	 *
	 * @var string
	 */
	private string $marker = '';

	/**
	 * The copy whose access ended one second ago.
	 *
	 * @var string
	 */
	private string $expiredId = '';

	/**
	 * The copy whose access ends tomorrow: the positive control.
	 *
	 * @var string
	 */
	private string $runningId = '';

	/**
	 * A copy without an end date: the second positive control.
	 *
	 * @var string
	 */
	private string $openId = '';

	/**
	 * Write the three copies and an active suite for the recipient, or skip.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		if (class_exists(\OC::class) === false || \OC::$server === null) {
			$this->markTestSkipped(message: 'needs a bootstrapped Nextcloud with a database');
		}

		$this->db = \OC::$server->get(IDBConnection::class);
		if ($this->db->tableExists('keepiq_secrets') === false) {
			$this->markTestSkipped(message: 'keepiq migrations have not run on this instance');
		}

		$this->mapper = \OC::$server->get(SecretMapper::class);
		$this->marker = 'kqexp' . bin2hex(random_bytes(4));
		$this->uid = $this->marker . '-bob';
		$suiteId = $this->uuid();

		$suite = $this->db->getQueryBuilder();
		$suite->insert('keepiq_enc_suites')->values(
			[
				'id' => $suite->createNamedParameter($suiteId),
				'owner_type' => $suite->createNamedParameter('user'),
				'owner_id' => $suite->createNamedParameter($this->uid),
				'status' => $suite->createNamedParameter('active'),
				'created_at' => $suite->createNamedParameter(new DateTime(), 'datetime'),
			]
		)->executeStatement();

		$this->expiredId = $this->insertCopy(suiteId: $suiteId, label: 'expired', end: new DateTime('-1 second'));
		$this->runningId = $this->insertCopy(suiteId: $suiteId, label: 'running', end: new DateTime('+1 day'));
		$this->openId = $this->insertCopy(suiteId: $suiteId, label: 'open', end: null);
	}//end setUp()

	/**
	 * Remove every row this test wrote, whether it passed or failed.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		if ($this->db !== null && $this->uid !== '') {
			foreach (['keepiq_secrets', 'keepiq_enc_suites'] as $table) {
				$delete = $this->db->getQueryBuilder();
				$delete->delete($table)
					->where($delete->expr()->eq('owner_id', $delete->createNamedParameter($this->uid)));
				$delete->executeStatement();
			}
		}

		parent::tearDown();
	}//end tearDown()

	/**
	 * The vault list (`GET /api/v1/secrets`) and its count.
	 *
	 * @return void
	 */
	public function testTheListLeavesTheExpiredCopyOut(): void {
		$result = \OC::$server->get(SecretService::class)->list($this->uid, null, null, 'asc', 1, 100, null, 'live', false, null);

		$this->assertServedWithoutTheExpiredCopy(ids: array_column($result['items'], 'id'));
		$this->assertSame(2, $result['total']);
	}//end testTheListLeavesTheExpiredCopyOut()

	/**
	 * The web app's own search box (`GET /api/v1/secrets?search=`).
	 *
	 * @return void
	 */
	public function testTheVaultSearchLeavesTheExpiredCopyOut(): void {
		$result = \OC::$server->get(SecretService::class)->search($this->uid, $this->marker, 1, 100);

		$this->assertServedWithoutTheExpiredCopy(ids: array_column($result['items'], 'id'));
	}//end testTheVaultSearchLeavesTheExpiredCopyOut()

	/**
	 * The detail read (`GET /api/v1/secrets/{id}`) answers as for an unknown id.
	 *
	 * @return void
	 */
	public function testTheDetailReadAnswersTheExpiredCopyAsUnknown(): void {
		$service = \OC::$server->get(SecretService::class);
		$this->assertSame($this->runningId, $service->get($this->runningId, $this->uid)->getId());

		$this->expectException(NotFoundException::class);
		$service->get($this->expiredId, $this->uid);
	}//end testTheDetailReadAnswersTheExpiredCopyAsUnknown()

	/**
	 * The browser extension's site match (`GET /api/v1/extension/match`).
	 *
	 * @return void
	 */
	public function testTheExtensionMatchLeavesTheExpiredCopyOut(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->uid);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		$controller = new ExtensionController(
			$this->createMock(IRequest::class),
			$this->mapper,
			$session,
			$this->createMock(AdminSettingsService::class),
			$this->createMock(IAppManager::class),
		);
		$data = $controller->match(host: 'https://' . $this->marker . '.example/login')->getData();

		$this->assertServedWithoutTheExpiredCopy(ids: array_column($data['items'], 'id'));
	}//end testTheExtensionMatchLeavesTheExpiredCopyOut()

	/**
	 * Nextcloud's unified search.
	 *
	 * @return void
	 */
	public function testUnifiedSearchLeavesTheExpiredCopyOut(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($this->uid);
		$query = $this->createMock(ISearchQuery::class);
		$query->method('getTerm')->willReturn($this->marker);

		$result = \OC::$server->get(SecretSearchProvider::class)->search($user, $query)->jsonSerialize();
		$ids = array_map(
			fn ($entry): string => $this->idFromLink(link: $entry->jsonSerialize()['resourceUrl']),
			$result['entries']
		);

		$this->assertServedWithoutTheExpiredCopy(ids: $ids);
	}//end testUnifiedSearchLeavesTheExpiredCopyOut()

	/**
	 * The offline snapshot (`GET /api/v1/offline/manifest`).
	 *
	 * @return void
	 */
	public function testTheOfflineManifestLeavesTheExpiredCopyOut(): void {
		$manifest = \OC::$server->get(OfflineManifestService::class)->buildForUser($this->uid);

		$this->assertServedWithoutTheExpiredCopy(ids: array_column($manifest['secrets'], 'id'));
	}//end testTheOfflineManifestLeavesTheExpiredCopyOut()

	/**
	 * Both controls are served and the expired copy is not.
	 *
	 * The controls prove the path CAN return this test's rows, so a missing
	 * expired copy means the filter worked, not that the query matched nothing.
	 *
	 * @param array<int,mixed> $ids The ids the path served
	 *
	 * @return void
	 */
	private function assertServedWithoutTheExpiredCopy(array $ids): void {
		$this->assertContains($this->runningId, $ids, 'the copy that ends tomorrow must be served');
		$this->assertContains($this->openId, $ids, 'the copy without an end must be served');
		$this->assertNotContains($this->expiredId, $ids, 'a copy one second past its end must not be served');
	}//end assertServedWithoutTheExpiredCopy()

	/**
	 * Write one recipient copy.
	 *
	 * @param string        $suiteId The recipient's suite
	 * @param string        $label   A word for the name
	 * @param DateTime|null $end     The end of access, or null for none
	 *
	 * @return string The new id
	 */
	private function insertCopy(string $suiteId, string $label, ?DateTime $end): string {
		$copy = new Secret();
		$copy->setId($this->uuid());
		$copy->setName($this->marker . ' ' . $label);
		$copy->setUrl('https://' . $this->marker . '.example');
		$copy->setTypeId($this->uuid());
		$copy->setKey('ciphertext');
		$copy->setEncryptionSuiteId($suiteId);
		$copy->setOwnerType('user');
		$copy->setOwnerId($this->uid);
		$copy->setCreatedAt(new DateTime());
		$copy->setUpdatedAt(new DateTime());
		$copy->setAccessExpiresAt($end);
		$this->mapper->insert($copy);

		return $copy->getId();
	}//end insertCopy()

	/**
	 * The secret id at the end of a unified-search link.
	 *
	 * @param string $link The result's resource URL
	 *
	 * @return string
	 */
	private function idFromLink(string $link): string {
		$parts = explode('/', rtrim($link, '/'));

		return (string)end($parts);
	}//end idFromLink()

	/**
	 * A random version 4 UUID.
	 *
	 * @return string
	 */
	private function uuid(): string {
		$bytes = random_bytes(16);
		$bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
		$bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

		return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
	}//end uuid()
}//end class
