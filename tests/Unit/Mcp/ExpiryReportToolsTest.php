<?php

/**
 * Unit tests for the keepiq.expiryReport MCP tool.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Mcp
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

namespace OCA\Keepiq\Tests\Unit\Mcp;

use DateTime;
use InvalidArgumentException;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Mcp\ExpiryReportTools;
use OCA\Keepiq\Mcp\McpToolContext;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * ExpiryReportTools: window bounds, date fallbacks, de-duplication and day arithmetic.
 *
 * @covers \OCA\Keepiq\Mcp\ExpiryReportTools
 * @uses   \OCA\Keepiq\Mcp\McpToolContext
 * @uses   \OCA\Keepiq\Mcp\MetadataAllowList
 * @uses   \OCA\Keepiq\Event\Audit\AuditEventFactory
 * @uses   \OCA\Keepiq\Event\Audit\AuditEvent
 * @uses   \OCA\Keepiq\Db\Secret
 */
class ExpiryReportToolsTest extends TestCase {

	private const NOW = '2026-10-02T12:00:00+00:00';

	/** @var list<AuditEvent> */
	private array $audits = [];

	/**
	 * The tool over the given certificate rows and secrets, for alice, at NOW.
	 *
	 * @param list<array<string,mixed>> $stored Certificate inventory rows
	 * @param list<Secret> $secrets Live secrets
	 *
	 * @return ExpiryReportTools
	 */
	private function tools(array $stored, array $secrets = []): ExpiryReportTools {
		$certificates = $this->createMock(CertificateLifecycleService::class);
		$certificates->method('inventory')->with('alice', false)->willReturn(['stored' => $stored, 'suites' => [], 'ca' => []]);
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findByOwner')
			->with('user', 'alice', null, null, 'asc', 1000, 0, null, SecretMapper::STATE_LIVE)
			->willReturn($secrets);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => new DateTime(self::NOW));

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('alice');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $event): void {
			$this->audits[] = $event;
		});

		return new ExpiryReportTools(
			certificates: $certificates,
			secretMapper: $mapper,
			time: $time,
			context: new McpToolContext(userSession: $session, dispatcher: $dispatcher)
		);
	}//end tools()

	/**
	 * A live secret expiring at the given moment.
	 *
	 * @param string $id The id
	 * @param string|null $expiresAt ISO 8601, or none
	 *
	 * @return Secret
	 */
	private static function secret(string $id, ?string $expiresAt): Secret {
		$secret = new Secret();
		$secret->setId($id);
		$secret->setName('secret ' . $id);
		$secret->setExpiresAt($expiresAt === null ? null : new DateTime($expiresAt));
		return $secret;
	}//end secret()

	/**
	 * Both ends of 0..365 are accepted.
	 *
	 * @return void
	 */
	public function testWindowEndsAreAccepted(): void {
		$tools = $this->tools(stored: []);
		$this->assertSame(['withinDays' => 0, 'certificates' => [], 'secrets' => []], $tools->expiryReport(withinDays: 0));
		$this->assertSame(365, $tools->expiryReport(withinDays: 365)['withinDays']);
		$this->assertSame(30, $tools->expiryReport()['withinDays']);
	}//end testWindowEndsAreAccepted()

	/**
	 * A window above 365 is refused with the bounds in the message.
	 *
	 * @return void
	 */
	public function testWindowAboveBoundIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('withinDays must be between 0 and 365');
		$this->tools(stored: [])->expiryReport(withinDays: 400);
	}//end testWindowAboveBoundIsRefused()

	/**
	 * Without metadata.notAfter the row's expiresAt is used; an unparseable or missing date is left out.
	 *
	 * @return void
	 */
	public function testCertificateDateFallbackAndUnparseableDates(): void {
		$stored = [
			['id' => 'fallback', 'name' => 'c1', 'expiresAt' => '2026-10-05T12:00:00+00:00', 'metadata' => ['subject' => 'CN=a']],
			['id' => 'garbage', 'name' => 'c2', 'metadata' => ['notAfter' => 'next tuesday']],
			['id' => 'empty', 'name' => 'c3', 'metadata' => ['notAfter' => '']],
			['id' => 'none', 'name' => 'c4'],
		];

		$certificates = $this->tools(stored: $stored)->expiryReport(withinDays: 30)['certificates'];

		$this->assertSame(
			[[
				'id' => 'fallback',
				'name' => 'c1',
				'subject' => 'CN=a',
				'issuer' => null,
				'serial' => null,
				'notAfter' => '2026-10-05T12:00:00+00:00',
				'daysRemaining' => 3,
				'expired' => false,
				'fingerprintSha256' => null,
			]],
			$certificates
		);
	}//end testCertificateDateFallbackAndUnparseableDates()

	/**
	 * Days remaining round down: 36 hours ahead is 1, 12 hours past is -1.
	 *
	 * @return void
	 */
	public function testDaysRemainingRoundsDown(): void {
		$stored = [
			['id' => 'soon', 'name' => 's', 'metadata' => ['notAfter' => '2026-10-04T00:00:00+00:00']],
			['id' => 'past', 'name' => 'p', 'metadata' => ['notAfter' => '2026-10-02T00:00:00+00:00']],
		];

		$rows = $this->tools(stored: $stored)->expiryReport(withinDays: 7)['certificates'];

		$this->assertSame([1, -1], array_column($rows, 'daysRemaining'));
		$this->assertSame([false, true], array_column($rows, 'expired'));
	}//end testDaysRemainingRoundsDown()

	/**
	 * Secrets that are stored certificates, have no expiry or lapse after the window are left out.
	 *
	 * @return void
	 */
	public function testSecretsSkipCertificatesUndatedAndLaterOnes(): void {
		$stored = [['id' => 'cert-far', 'name' => 'far', 'metadata' => ['notAfter' => '2027-10-02T12:00:00+00:00']]];
		$secrets = [
			self::secret(id: 'cert-far', expiresAt: '2026-10-03T12:00:00+00:00'),
			self::secret(id: 'undated', expiresAt: null),
			self::secret(id: 'later', expiresAt: '2026-12-01T12:00:00+00:00'),
			self::secret(id: 'gone', expiresAt: '2026-09-30T12:00:00+00:00'),
		];

		$report = $this->tools(stored: $stored, secrets: $secrets)->expiryReport(withinDays: 10);

		$this->assertSame([], $report['certificates']);
		$this->assertSame(
			[['id' => 'gone', 'name' => 'secret gone', 'expiresAt' => '2026-09-30T12:00:00+00:00', 'daysRemaining' => -2, 'expired' => true]],
			$report['secrets']
		);
		$this->assertSame(['tool' => 'expiryReport', 'resultCount' => 1], $this->audits[0]->getMetadata());
	}//end testSecretsSkipCertificatesUndatedAndLaterOnes()

	/**
	 * The audit counts certificates and secrets together.
	 *
	 * @return void
	 */
	public function testAuditCountsBothLists(): void {
		$stored = [['id' => 'c', 'name' => 'c', 'metadata' => ['notAfter' => '2026-10-03T12:00:00+00:00']]];
		$secrets = [self::secret(id: 's', expiresAt: '2026-10-03T12:00:00+00:00')];

		$this->tools(stored: $stored, secrets: $secrets)->expiryReport(withinDays: 5);

		$this->assertCount(1, $this->audits);
		$this->assertSame(['tool' => 'expiryReport', 'resultCount' => 2], $this->audits[0]->getMetadata());
	}//end testAuditCountsBothLists()
}//end class
