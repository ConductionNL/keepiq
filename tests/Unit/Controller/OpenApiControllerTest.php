<?php
/**
 * The OpenAPI document is served from the app root with the installed
 * version and a cross-origin header; a missing or broken document is a 404.
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

use OCA\Keepiq\Controller\OpenApiController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Tests the OpenAPI controller.
 *
 * @covers \OCA\Keepiq\Controller\OpenApiController
 */
class OpenApiControllerTest extends TestCase {

	/**
	 * The headers as set; getHeaders() resolves server services.
	 *
	 * @param \OCP\AppFramework\Http\Response $response The response
	 *
	 * @return array<string,string>
	 */
	private function headersOf(\OCP\AppFramework\Http\Response $response): array {
		return (new \ReflectionProperty(\OCP\AppFramework\Http\Response::class, 'headers'))->getValue($response);
	}//end headersOf()
	/**
	 * A temporary app directory.
	 *
	 * @var string
	 */
	private string $appPath;

	/**
	 * Create the app directory.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->appPath = sys_get_temp_dir() . '/keepiq-openapi-' . bin2hex(random_bytes(4));
		mkdir($this->appPath);
	}//end setUp()

	/**
	 * Remove the app directory.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		@unlink($this->appPath . '/openapi.json');
		@rmdir($this->appPath);
	}//end tearDown()

	/**
	 * The controller over the temporary app directory.
	 *
	 * @return OpenApiController
	 */
	private function controller(): OpenApiController {
		$appManager = $this->createMock(IAppManager::class);
		$appManager->method('getAppPath')->with('keepiq')->willReturn($this->appPath);
		$appManager->method('getAppVersion')->with('keepiq')->willReturn('1.4.2');

		return new OpenApiController(request: $this->createMock(IRequest::class), appManager: $appManager);
	}//end controller()

	/**
	 * The document is returned with info.version set to the installed version.
	 *
	 * @return void
	 */
	public function testTheDocumentCarriesTheInstalledVersion(): void {
		file_put_contents($this->appPath . '/openapi.json', '{"openapi":"3.0.3","info":{"title":"Keepiq","version":"0.0.1"},"paths":{}}');

		$response = $this->controller()->show();

		$this->assertSame(Http::STATUS_OK, $response->getStatus());
		$this->assertSame('*', $this->headersOf($response)['Access-Control-Allow-Origin']);
		$data = json_decode(json_encode($response->getData()), true);
		$this->assertSame(['openapi' => '3.0.3', 'info' => ['title' => 'Keepiq', 'version' => '1.4.2'], 'paths' => []], $data);
	}//end testTheDocumentCarriesTheInstalledVersion()

	/**
	 * A missing file, or one without an info object, answers 404 with a message.
	 *
	 * @return void
	 */
	public function testAMissingOrBrokenDocumentIsNotFound(): void {
		foreach ([null, 'not json', '{"openapi":"3.0.3"}'] as $content) {
			@unlink($this->appPath . '/openapi.json');
			if ($content !== null) {
				file_put_contents($this->appPath . '/openapi.json', $content);
			}

			$response = $this->controller()->show();

			$this->assertSame(Http::STATUS_NOT_FOUND, $response->getStatus());
			$this->assertSame(['message' => 'The OpenAPI document is not installed'], $response->getData());
			$this->assertSame('*', $this->headersOf($response)['Access-Control-Allow-Origin']);
		}
	}//end testAMissingOrBrokenDocumentIsNotFound()

	/**
	 * The committed document describes the versioned API and itself.
	 *
	 * @return void
	 */
	public function testTheCommittedDocumentIsVersionedAndListsItself(): void {
		$document = json_decode((string)file_get_contents(__DIR__ . '/../../../openapi.json'), true);

		$this->assertStringEndsWith('/index.php/apps/keepiq/api/v1', $document['servers'][0]['url']);
		$this->assertArrayHasKey('get', $document['paths']['/openapi.json']);
		$this->assertSame(['name', 'email', 'url'], array_keys($document['info']['contact']));
	}//end testTheCommittedDocumentIsVersionedAndListsItself()
}//end class
