<?php

/**
 * Unit tests for Keepiq's own shell controllers and admin section (ADR-006):
 * health, metrics, preferences and the settings section.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\HealthController;
use OCA\Keepiq\Controller\MetricsController;
use OCA\Keepiq\Controller\PreferencesController;
use OCA\Keepiq\Sections\SettingsSection;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITempManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for HealthController, MetricsController, PreferencesController and
 * SettingsSection.
 */
final class AppShellControllersTest extends TestCase {
	/**
	 * A database whose query builder runs, or throws on executeQuery().
	 *
	 * @param \Throwable|null $failure Thrown by executeQuery() when set
	 * @param string          $count   What fetchOne() answers
	 * @param array           $params  Receives createNamedParameter() values
	 *
	 * @return IDBConnection
	 */
	private function db(?\Throwable $failure = null, string $count = '0', array &$params = []): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'where', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$params): string {
			$params[] = $value;
			return ':p';
		});
		$expr = $this->createMock(IExpressionBuilder::class);
		$expr->method('eq')->willReturn('eq');
		$qb->method('expr')->willReturn($expr);
		$func = $this->createMock(IFunctionBuilder::class);
		$func->method('count')->willReturn($this->createMock(IQueryFunction::class));
		$qb->method('func')->willReturn($func);

		$result = $this->createMock(IResult::class);
		$result->method('fetchOne')->willReturn($count);
		if ($failure !== null) {
			$qb->method('executeQuery')->willThrowException($failure);
		} else {
			$qb->method('executeQuery')->willReturn($result);
		}

		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}//end db()

	/**
	 * An app manager reporting Keepiq's version.
	 *
	 * @return IAppManager
	 */
	private function appManager(): IAppManager {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppVersion')->with('keepiq')->willReturn('0.3.7');
		return $appManager;
	}//end appManager()

	/**
	 * A health controller over the given database and temp directory.
	 *
	 * @param IDBConnection $db      The database
	 * @param string        $tempDir The temp base directory
	 *
	 * @return HealthController
	 */
	private function health(IDBConnection $db, string $tempDir): HealthController {
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willReturn($tempDir);
		return new HealthController(
			$this->createMock(IRequest::class),
			$db,
			$this->appManager(),
			$temp,
			$this->createMock(LoggerInterface::class)
		);
	}//end health()

	/**
	 * Healthy: 200, status ok, and only the four keys.
	 *
	 * @return void
	 */
	public function testHealthIsOkWithOnlyTheFourKeys(): void {
		$response = $this->health(db: $this->db(), tempDir: sys_get_temp_dir())->index();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame(
			['status' => 'ok', 'app' => 'keepiq', 'version' => '0.3.7', 'checks' => ['database' => 'ok', 'filesystem' => 'ok']],
			$response->getData()
		);
	}//end testHealthIsOkWithOnlyTheFourKeys()

	/**
	 * A non-writable temp directory degrades, but stays 200.
	 *
	 * @return void
	 */
	public function testHealthDegradesOnTheFilesystem(): void {
		$response = $this->health(db: $this->db(), tempDir: '/nonexistent-keepiq-health-dir')->index();
		$data = $response->getData();

		self::assertSame(Http::STATUS_OK, $response->getStatus());
		self::assertSame('degraded', $data['status']);
		self::assertSame('ok', $data['checks']['database']);
		self::assertStringStartsWith('failed:', $data['checks']['filesystem']);
	}//end testHealthDegradesOnTheFilesystem()

	/**
	 * A failing database is 503 with the exception class only, never its message.
	 *
	 * @return void
	 */
	public function testHealthErrorsOnTheDatabaseWithoutLeakingTheMessage(): void {
		$db = $this->db(failure: new \RuntimeException('SECRET-DSN password=CHANGE_ME'));
		$response = $this->health(db: $db, tempDir: sys_get_temp_dir())->index();
		$data = $response->getData();

		self::assertSame(Http::STATUS_SERVICE_UNAVAILABLE, $response->getStatus());
		self::assertSame('error', $data['status']);
		self::assertSame('failed: RuntimeException', $data['checks']['database']);
		self::assertStringNotContainsString('SECRET', json_encode($data));
	}//end testHealthErrorsOnTheDatabaseWithoutLeakingTheMessage()

	/**
	 * Metrics: the three series, the active-only count and the content type.
	 *
	 * @return void
	 */
	public function testMetricsReportTheActiveSuiteCount(): void {
		$params = [];
		$config = $this->createMock(IConfig::class);
		$config->method('getSystemValueString')->with('version', 'unknown')->willReturn('35.0.0.1');
		$controller = new MetricsController(
			$this->createMock(IRequest::class),
			$this->db(count: '4', params: $params),
			$this->appManager(),
			$config
		);

		$response = $controller->index();
		$body = $response->render();

		// getHeaders() resolves server services; read the header as set.
		$headers = (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
		self::assertSame(MetricsController::CONTENT_TYPE, $headers['Content-Type']);
		self::assertStringContainsString('keepiq_info{version="0.3.7",php_version="' . PHP_VERSION . '",nextcloud_version="35.0.0.1"} 1' . "\n", $body);
		self::assertStringContainsString("# TYPE keepiq_up gauge\nkeepiq_up 1\n", $body);
		self::assertStringContainsString("# HELP keepiq_suites_total Total number of active encryption suites\n# TYPE keepiq_suites_total gauge\nkeepiq_suites_total 4\n", $body);
		self::assertSame(['active'], $params, 'the count filters on status = active');
	}//end testMetricsReportTheActiveSuiteCount()

	/**
	 * A preferences controller over an in-memory user-value store.
	 *
	 * @param array<string,array<string,string>> $store  uid => key => value
	 * @param string|null                        $userId The logged-in user
	 *
	 * @return PreferencesController
	 */
	private function preferences(array &$store, ?string $userId): PreferencesController {
		$config = $this->createMock(IConfig::class);
		$config->method('getUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, mixed $default = '') use (&$store) {
				self::assertSame('keepiq', $app);
				return $store[$uid][$key] ?? $default;
			}
		);
		$config->method('setUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key, string $value) use (&$store): void {
				self::assertSame('keepiq', $app);
				$store[$uid][$key] = $value;
			}
		);
		$config->method('deleteUserValue')->willReturnCallback(
			function (string $uid, string $app, string $key) use (&$store): void {
				unset($store[$uid][$key]);
			}
		);

		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}

		$session->method('getUser')->willReturn($user);
		return new PreferencesController($this->createMock(IRequest::class), $config, $session);
	}//end preferences()

	/**
	 * The walkthrough preference round-trips under the AppHost storage key.
	 *
	 * @return void
	 */
	public function testThePreferenceRoundTripsUnderTheLegacyKey(): void {
		$store = [];
		$alice = $this->preferences(store: $store, userId: 'alice');

		self::assertSame(['value' => '0.3.7'], $alice->setPreference(key: 'walkthrough_completed_version', value: '0.3.7')->getData());
		self::assertSame(['value' => '0.3.7'], $alice->getPreference(key: 'walkthrough_completed_version')->getData());
		self::assertSame(['alice' => ['pref_walkthroughcompletedversion' => '0.3.7']], $store);
	}//end testThePreferenceRoundTripsUnderTheLegacyKey()

	/**
	 * Preferences are per user.
	 *
	 * @return void
	 */
	public function testPreferencesArePerUser(): void {
		$store = ['alice' => ['pref_walkthroughcompletedversion' => '0.3.7']];

		self::assertSame(['value' => null], $this->preferences(store: $store, userId: 'bob')->getPreference(key: 'walkthrough_completed_version')->getData());
	}//end testPreferencesArePerUser()

	/**
	 * An empty PUT deletes and answers null; an invalid key is 400 and touches nothing.
	 *
	 * @return void
	 */
	public function testEmptyPutDeletesAndAnInvalidKeyIsRefused(): void {
		$store = ['alice' => ['pref_tour' => 'done']];
		$alice = $this->preferences(store: $store, userId: 'alice');

		self::assertSame(['value' => null], $alice->setPreference(key: 'tour', value: '')->getData());
		self::assertSame(['alice' => []], $store);

		$refused = $alice->getPreference(key: ' !');
		self::assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
		self::assertSame(Http::STATUS_BAD_REQUEST, $alice->setPreference(key: '___', value: 'x')->getStatus());
		self::assertSame(['alice' => []], $store, 'an invalid key writes nothing');
	}//end testEmptyPutDeletesAndAnInvalidKeyIsRefused()

	/**
	 * Without a session the preferences answer 401.
	 *
	 * @return void
	 */
	public function testPreferencesNeedASession(): void {
		$store = [];

		self::assertSame(Http::STATUS_UNAUTHORIZED, $this->preferences(store: $store, userId: null)->getPreference(key: 'tour')->getStatus());
	}//end testPreferencesNeedASession()

	/**
	 * The section keeps the id, name, priority and icon of the AppHost section.
	 *
	 * @return void
	 */
	public function testTheSettingsSectionKeepsItsPlace(): void {
		$urls = $this->createMock(IURLGenerator::class);
		$urls->method('imagePath')->with('keepiq', 'app-dark.svg')->willReturn('/apps/keepiq/img/app-dark.svg');
		$section = new SettingsSection($urls);

		self::assertSame('keepiq', $section->getID());
		self::assertSame('Keepiq', $section->getName());
		self::assertSame(75, $section->getPriority());
		self::assertSame('/apps/keepiq/img/app-dark.svg', $section->getIcon());
	}//end testTheSettingsSectionKeepsItsPlace()
}//end class
