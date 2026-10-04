<?php

/**
 * Keepiq's MCP surface: three metadata-only read tools, an allow-list that
 * fails closed, an exact scannable list, and an audit of every call.
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
use OCA\Keepiq\AppInfo\McpRegistrar;
use OCA\Keepiq\Db\AuditEntry;
use OCA\Keepiq\Db\AuditEntryMapper;
use OCA\Keepiq\Db\EncryptionSuite;
use OCA\Keepiq\Db\EncryptionSuiteMapper;
use OCA\Keepiq\Db\RotationFlag;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Event\Audit\AuditEvent;
use OCA\Keepiq\Mcp\EntryMetadataTools;
use OCA\Keepiq\Mcp\ExpiryReportTools;
use OCA\Keepiq\Mcp\KeepiqScannableServices;
use OCA\Keepiq\Mcp\McpToolContext;
use OCA\Keepiq\Mcp\MetadataAllowList;
use OCA\Keepiq\Mcp\RotationStatusTools;
use OCA\Keepiq\Service\AuditService;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCA\Keepiq\Service\RotationFlagService;
use OCA\Keepiq\Service\SecretService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * The MCP metadata surface.
 *
 * @spec openspec/specs/mcp-metadata-surface/spec.md
 */
class McpSurfaceTest extends TestCase {

	/** @var AuditEvent[] */
	private array $audits = [];

	/**
	 * A tool context for a user, recording audit events.
	 *
	 * @param string|null $uid The session user, or none
	 *
	 * @return McpToolContext
	 */
	private function context(?string $uid = 'alice'): McpToolContext {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}

		$session->method('getUser')->willReturn($user);
		$dispatcher = $this->createMock(IEventDispatcher::class);
		$dispatcher->method('dispatchTyped')->willReturnCallback(function (object $e): void {
			$this->audits[] = $e;
		});
		return new McpToolContext(userSession: $session, dispatcher: $dispatcher);
	}//end context()

	/**
	 * Every key of every row is on the allow-list of its type.
	 *
	 * @param array<int,array<string,mixed>> $rows The rows
	 * @param list<string> $allowed The allow-list
	 *
	 * @return bool
	 */
	private static function onlyAllowed(array $rows, array $allowed): bool {
		foreach ($rows as $row) {
			if (array_diff(array_keys($row), $allowed) !== []) {
				return false;
			}
		}

		return true;
	}//end onlyAllowed()

	/**
	 * 3.1: projection strips ciphertext, the suite id and unknown keys; the
	 * check that guards it fails on a poisoned allow-list.
	 *
	 * @return void
	 */
	public function testAllowListStripsSecretMaterial(): void {
		$row = [
			'id' => 's1', 'name' => 'staging db', 'url' => 'https://db', 'typeId' => 't', 'folderId' => null,
			'key' => 'CIPHER-KEY', 'login' => 'CIPHER-LOGIN', 'additionalFields' => 'CIPHER-EXTRA',
			'encryptionSuiteId' => 'suite-1', 'brandNewColumn' => 'x', 'nested' => ['id' => 1],
		];
		$projected = (new MetadataAllowList())->project(row: $row, type: 'entry');
		$this->assertSame(['id', 'name', 'url', 'typeId', 'folderId'], array_keys($projected));
		$this->assertTrue(self::onlyAllowed([$projected], MetadataAllowList::KEYS['entry']));

		// Positive control: a list poisoned with a ciphertext key lets the
		// check pass something it must refuse, so the check is not vacuous.
		$this->assertFalse(self::onlyAllowed([$row], MetadataAllowList::KEYS['entry']));
		foreach (MetadataAllowList::KEYS as $type => $keys) {
			foreach (['key', 'login', 'additionalFields', 'encryptionSuiteId', 'value', 'password', 'privateKey'] as $banned) {
				$this->assertNotContains($banned, $keys, $type . ' allows ' . $banned);
			}
		}

		$this->expectException(InvalidArgumentException::class);
		(new MetadataAllowList())->project(row: [], type: 'unknown');
	}//end testAllowListStripsSecretMaterial()

	/**
	 * 3.2: #[McpTool] sits on exactly the three read methods of the three
	 * facades, each read-only with scope read, and the scannable list names
	 * exactly those classes.
	 *
	 * @return void
	 */
	public function testScannableSurfaceIsExactlyThreeReadTools(): void {
		$found = [];
		$root = __DIR__ . '/../../../lib';
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
			if ($file->getExtension() !== 'php') {
				continue;
			}

			$source = (string)file_get_contents($file->getPathname());
			if (str_contains($source, 'McpTool(') === false) {
				continue;
			}

			$relative = substr($file->getPathname(), strlen($root) + 1, -4);
			$class = 'OCA\\Keepiq\\' . str_replace('/', '\\', $relative);
			foreach ((new ReflectionClass($class))->getMethods() as $method) {
				foreach ($method->getAttributes(McpTool::class) as $attribute) {
					$tool = $attribute->newInstance();
					$found[$class . '::' . $method->getName()] = $tool;
				}
			}
		}//end foreach

		ksort($found);
		$this->assertSame(
			[
				EntryMetadataTools::class . '::listEntries',
				ExpiryReportTools::class . '::expiryReport',
				RotationStatusTools::class . '::rotationStatus',
			],
			array_keys($found)
		);
		foreach ($found as $where => $tool) {
			$this->assertTrue($tool->readOnlyHint, $where);
			$this->assertFalse($tool->destructiveHint, $where);
			$this->assertSame('read', $tool->scope, $where);
			$this->assertDoesNotMatchRegularExpression('/\.(create|update|delete)$/', (string)$tool->name);
		}

		$this->assertSame(['listEntries', 'expiryReport', 'rotationStatus'], array_map(static fn ($t) => $t->name, array_values($found)));
		$this->assertSame(
			[EntryMetadataTools::class, ExpiryReportTools::class, RotationStatusTools::class],
			(new KeepiqScannableServices())->getScannableServiceClasses()
		);
	}//end testScannableSurfaceIsExactlyThreeReadTools()

	/**
	 * 1.5: the alias is registered with OpenRegister present, and not without.
	 *
	 * @return void
	 */
	public function testAliasOnlyWithOpenRegister(): void {
		$context = $this->createMock(IRegistrationContext::class);
		$context->expects($this->once())->method('registerServiceAlias')
			->with('OCA\\OpenRegister\\Mcp\\IMcpScannableServices::keepiq', KeepiqScannableServices::class);
		$this->assertTrue((new McpRegistrar(openRegisterPresent: static fn (): bool => true))->register($context));

		$absent = $this->createMock(IRegistrationContext::class);
		$absent->expects($this->never())->method('registerServiceAlias');
		$this->assertFalse((new McpRegistrar(openRegisterPresent: static fn (): bool => false))->register($absent));
	}//end testAliasOnlyWithOpenRegister()

	/**
	 * Entry tool over a SecretService that answers per user.
	 *
	 * @param bool $hasSuite Whether the user has an active suite
	 *
	 * @return EntryMetadataTools
	 */
	private function entryTools(bool $hasSuite = true): EntryMetadataTools {
		$vaults = [
			'alice' => [['id' => 'a1', 'name' => 'staging db', 'folderId' => 'f1', 'typeId' => 'db', 'key' => 'CIPHER-A', 'login' => 'CIPHER-L', 'encryptionSuiteId' => 'sa']],
			'bob' => [['id' => 'b1', 'name' => 'bob staging', 'folderId' => null, 'typeId' => 'db', 'key' => 'CIPHER-B']],
		];
		$secrets = $this->createMock(SecretService::class);
		$secrets->method('list')->willReturnCallback(static fn (string $userId) => ['items' => $vaults[$userId] ?? [], 'total' => 0, 'page' => 1, 'limit' => 200]);
		$secrets->method('search')->willReturnCallback(
			static fn (string $userId, string $term) => ['items' => array_values(array_filter($vaults[$userId] ?? [], static fn ($r) => str_contains($r['name'], $term))), 'total' => 0, 'page' => 1, 'limit' => 200]
		);
		$suites = $this->createMock(EncryptionSuiteMapper::class);
		if ($hasSuite === true) {
			$suites->method('findActiveByOwner')->willReturn(new EncryptionSuite());
		} else {
			$suites->method('findActiveByOwner')->willThrowException(new DoesNotExistException('none'));
		}

		return new EntryMetadataTools(secretService: $secrets, suiteMapper: $suites, context: $this->context());
	}//end entryTools()

	/**
	 * 3.3: the caller sees their own entries as metadata, never another
	 * user's, and the result carries no ciphertext or suite id.
	 *
	 * @return void
	 */
	public function testListEntriesIsMetadataOfTheSessionUserOnly(): void {
		$result = $this->entryTools()->listEntries(query: 'staging');
		$this->assertSame([['id' => 'a1', 'name' => 'staging db', 'typeId' => 'db', 'folderId' => 'f1']], $result['entries']);
		$flat = (string)json_encode($result);
		foreach (['CIPHER', 'encryptionSuiteId', 'b1', 'bob'] as $absent) {
			$this->assertStringNotContainsString($absent, $flat);
		}

		$this->assertSame(['folderId', 'typeId', 'query'], array_map(static fn ($p) => $p->getName(), (new \ReflectionMethod(EntryMetadataTools::class, 'listEntries'))->getParameters()), 'no user parameter');
		$this->assertCount(1, $this->entryTools()->listEntries()['entries']);
		$this->assertSame([], $this->entryTools()->listEntries(folderId: 'other', query: 'staging')['entries']);
	}//end testListEntriesIsMetadataOfTheSessionUserOnly()

	/**
	 * 3.3: a vault without an active suite answers an empty list.
	 *
	 * @return void
	 */
	public function testNoSuiteIsAnEmptyList(): void {
		$this->assertSame(['entries' => [], 'total' => 0], $this->entryTools(hasSuite: false)->listEntries());
	}//end testNoSuiteIsAnEmptyList()

	/**
	 * Expiry tool at a fixed now, with certificates at 90, 30, 7 and -1 days
	 * and two secrets with expiry dates.
	 *
	 * @return ExpiryReportTools
	 */
	private function expiryTools(): ExpiryReportTools {
		$now = new DateTime('2026-10-02T12:00:00+00:00');
		$at = static fn (int $days): string => (clone $now)->modify(($days >= 0 ? '+' : '') . $days . ' days')->format('c');
		$stored = [];
		foreach (['c90' => 90, 'c30' => 30, 'c7' => 7, 'cexp' => -1] as $id => $days) {
			$stored[] = [
				'kind' => 'stored_secret', 'id' => $id, 'name' => 'cert ' . $id, 'metadataSource' => 'client_parsed', 'expiresAt' => null,
				'metadata' => ['subject' => 'CN=' . $id, 'issuer' => 'CN=CA', 'serial' => '01', 'fingerprintSha256' => 'ab', 'notAfter' => $at($days), 'pem' => '-----BEGIN CERTIFICATE-----'],
			];
		}

		$certificates = $this->createMock(CertificateLifecycleService::class);
		$certificates->method('inventory')->with('alice', false)->willReturn(['stored' => $stored, 'suites' => [['kind' => 'suite']], 'ca' => []]);
		$secrets = [];
		foreach (['s5' => 5, 's60' => 60] as $id => $days) {
			$s = new Secret();
			$s->setId($id);
			$s->setName('secret ' . $id);
			$s->setKey('CIPHER-' . $id);
			$s->setExpiresAt(new DateTime($at($days)));
			$secrets[] = $s;
		}

		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findByOwner')->willReturn($secrets);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturnCallback(static fn () => clone $now);
		return new ExpiryReportTools(certificates: $certificates, secretMapper: $mapper, time: $time, context: $this->context());
	}//end expiryTools()

	/**
	 * 3.3: the 90/30/7 thresholds, expired entries flagged, no PEM or value.
	 *
	 * @return void
	 */
	public function testExpiryReportThresholds(): void {
		$ids = static fn (array $rows): array => array_column($rows, 'id');
		$tools = $this->expiryTools();
		$this->assertSame(['c7', 'cexp'], $ids($tools->expiryReport(withinDays: 7)['certificates']));
		$this->assertSame(['c30', 'c7', 'cexp'], $ids($tools->expiryReport(withinDays: 30)['certificates']));
		$report = $tools->expiryReport(withinDays: 90);
		$this->assertSame(['c90', 'c30', 'c7', 'cexp'], $ids($report['certificates']));
		$this->assertSame(['s5', 's60'], $ids($report['secrets']));
		$this->assertSame(['s5'], $ids($tools->expiryReport(withinDays: 30)['secrets']));

		$expired = $report['certificates'][3];
		$this->assertTrue($expired['expired']);
		$this->assertSame(-1, $expired['daysRemaining']);
		$this->assertSame(['id', 'name', 'subject', 'issuer', 'serial', 'notAfter', 'daysRemaining', 'expired', 'fingerprintSha256'], array_keys($report['certificates'][0]));
		$flat = (string)json_encode($report);
		$this->assertStringNotContainsString('BEGIN CERTIFICATE', $flat);
		$this->assertStringNotContainsString('CIPHER', $flat);
		$this->assertStringNotContainsString('suite', $flat);
	}//end testExpiryReportThresholds()

	/**
	 * 3.3: a window outside 0..365 is refused before anything is read.
	 *
	 * @return void
	 */
	public function testExpiryWindowBounds(): void {
		foreach ([-1, 366] as $days) {
			try {
				$this->expiryTools()->expiryReport(withinDays: $days);
				$this->fail('accepted withinDays ' . $days);
			} catch (InvalidArgumentException) {
				$this->assertSame([], $this->audits);
			}
		}
	}//end testExpiryWindowBounds()

	/**
	 * 3.3: rotation status lists open flags and counts, and changes nothing.
	 *
	 * @return void
	 */
	public function testRotationStatusChangesNothing(): void {
		$flags = [];
		foreach ([['s1', 'policy_expiry'], ['s2', 'suite_compromise'], ['s3', 'user_flagged']] as [$secretId, $reason]) {
			$f = new RotationFlag();
			$f->setSecretId($secretId);
			$f->setReason($reason);
			$f->setStatus('open');
			$f->setFlaggedAt(new DateTime('2026-09-01T00:00:00+00:00'));
			$flags[] = $f;
		}

		$service = $this->createMock(RotationFlagService::class);
		$service->method('openFlags')->with('alice')->willReturn($flags);
		$service->expects($this->never())->method('markRotated');
		$service->expects($this->never())->method('dismiss');
		$service->expects($this->never())->method('flag');
		$mapper = $this->createMock(SecretMapper::class);
		$mapper->method('findById')->willReturnCallback(static function (string $id): Secret {
			$s = new Secret();
			$s->setName('entry ' . $id);
			$s->setKey('CIPHER');
			return $s;
		});

		$result = (new RotationStatusTools(flags: $service, secretMapper: $mapper, context: $this->context()))->rotationStatus();
		$this->assertSame(['open' => 3, 'overdue' => 1, 'compromised' => 1], $result['counts']);
		$this->assertSame(['id' => 's1', 'name' => 'entry s1', 'reason' => 'policy_expiry', 'status' => 'open', 'flaggedAt' => '2026-09-01T00:00:00+00:00', 'keyUpdatedAtAtFlag' => null], $result['flags'][0]);
		$this->assertStringNotContainsString('CIPHER', (string)json_encode($result));
	}//end testRotationStatusChangesNothing()

	/**
	 * 3.5: every call is audited as mcp for the principal, with the tool and a
	 * count only, and the audit trail stores exactly that.
	 *
	 * @return void
	 */
	public function testCallsAreAuditedAsAgentReads(): void {
		$this->entryTools()->listEntries(query: 'staging');
		$this->assertCount(1, $this->audits);
		$event = $this->audits[0];
		$this->assertSame('mcp', $event->getActorType());
		$this->assertSame('alice', $event->getActorId());
		$this->assertSame(['tool' => 'listEntries', 'resultCount' => 1], $event->getMetadata());

		$mapper = $this->createMock(AuditEntryMapper::class);
		$mapper->method('insert')->willReturnArgument(0);
		$entry = (new AuditService(mapper: $mapper))->record($event);
		$this->assertInstanceOf(AuditEntry::class, $entry);
		$this->assertSame('mcp', $entry->getActorType());
		$this->assertSame('{"tool":"listEntries","resultCount":1}', $entry->getMetadata());
		$this->assertStringNotContainsString('staging', (string)json_encode([$entry->getObjectName(), $entry->getMetadata()]));
	}//end testCallsAreAuditedAsAgentReads()

	/**
	 * Without a session user the tools refuse.
	 *
	 * @return void
	 */
	public function testNoSessionUserIsRefused(): void {
		$this->expectException(\RuntimeException::class);
		$this->context(uid: null)->userId();
	}//end testNoSessionUserIsRefused()
}//end class
