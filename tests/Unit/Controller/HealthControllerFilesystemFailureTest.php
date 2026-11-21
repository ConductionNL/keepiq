<?php

/**
 * Unit tests for the filesystem-failure path of HealthController.
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
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IRequest;
use OCP\ITempManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A throwing temp manager degrades health and names only the exception class.
 */
#[CoversClass(HealthController::class)]
class HealthControllerFilesystemFailureTest extends TestCase {

	/**
	 * A healthy database double.
	 *
	 * @return IDBConnection
	 */
	private function db(): IDBConnection {
		$qb = $this->createMock(IQueryBuilder::class);
		foreach (['select', 'from', 'setMaxResults'] as $fluent) {
			$qb->method($fluent)->willReturnSelf();
		}

		$qb->method('executeQuery')->willReturn($this->createStub(IResult::class));
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturn($qb);
		return $db;
	}//end db()

	/**
	 * A global (un-namespaced) exception is reported by its bare class name, logged as a warning.
	 *
	 * @return void
	 */
	public function testThrowingTempManagerDegradesWithTheClassName(): void {
		$failure = new RuntimeException('/var/secret/path is gone');
		$temp = $this->createMock(ITempManager::class);
		$temp->method('getTempBaseDir')->willThrowException($failure);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())
			->method('warning')
			->with('[HealthController] Filesystem check failed', ['exception' => $failure]);
		$logger->expects($this->never())->method('error');
		$apps = $this->createStub(IAppManager::class);
		$apps->method('getAppVersion')->willReturn('1.2.3');

		$controller = new HealthController(
			request: $this->createStub(IRequest::class),
			db: $this->db(),
			appManager: $apps,
			tempManager: $temp,
			logger: $logger,
		);

		$response = $controller->index();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame(
			[
				'status'  => 'degraded',
				'app'     => 'keepiq',
				'version' => '1.2.3',
				'checks'  => ['database' => 'ok', 'filesystem' => 'failed: RuntimeException'],
			],
			$response->getData()
		);
	}//end testThrowingTempManagerDegradesWithTheClassName()
}//end class
