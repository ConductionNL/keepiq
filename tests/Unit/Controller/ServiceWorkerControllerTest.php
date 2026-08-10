<?php

/**
 * Contract tests for the ServiceWorkerController static-asset endpoint.
 *
 * @category Test
 * @package  OCA\Doriath\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Doriath\Tests\Unit\Controller;

use OCA\Doriath\Controller\ServiceWorkerController;
use OCP\App\IAppManager;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Wire contract for `serviceWorker#script` (GET /serviceworker.js).
 *
 * The whole reason this route exists is that Nextcloud's static `js/` asset
 * route mislabels the MIME type and the browser then refuses to register the
 * worker. So the contract worth testing is not "a response object came back"
 * but the three things the browser actually enforces: the exact
 * `Content-Type: application/javascript`, the status, and a body that really
 * is the service-worker script served from the app's `js/` directory.
 *
 * The fixture writes the app's REAL service-worker source
 * (`src/offline/service-worker.js`) to the built artefact path the controller
 * resolves, so "the body contains the service-worker code" is asserted against
 * the shipped code, not against a string the test invented.
 *
 */
class ServiceWorkerControllerTest extends TestCase
{

    /**
     * The exact MIME type the browser requires for a service worker.
     *
     * @var string
     */
    private const EXPECTED_MIME = 'application/javascript';

    /**
     * The temporary app directory standing in for the installed app path.
     *
     * @var string
     */
    private string $appPath = '';

    /**
     * The mocked app manager, which resolves the installed app path.
     *
     * @var IAppManager&MockObject
     */
    private IAppManager&MockObject $appManager;


    /**
     * Create the temporary app directory and mock the app manager.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->appPath = sys_get_temp_dir().'/doriath-sw-'.bin2hex(random_bytes(8));
        mkdir($this->appPath.'/js', 0777, true);

        $this->appManager = $this->createMock(originalClassName: IAppManager::class);
    }//end setUp()


    /**
     * Remove the temporary app directory.
     *
     * @return void
     */
    protected function tearDown(): void
    {
        $script = $this->appPath.'/js/doriath-service-worker.js';
        if (is_file($script) === true) {
            unlink($script);
        }

        if (is_dir($this->appPath.'/js') === true) {
            rmdir($this->appPath.'/js');
        }

        if (is_dir($this->appPath) === true) {
            rmdir($this->appPath);
        }

        parent::tearDown();
    }//end tearDown()


    /**
     * Build the controller with the app manager pointed at the fixture path.
     *
     * @return ServiceWorkerController The controller under test.
     */
    private function controller(): ServiceWorkerController
    {
        $this->appManager->expects($this->once())
            ->method('getAppPath')
            ->with('doriath')
            ->willReturn($this->appPath);

        return new ServiceWorkerController(
            request: $this->createMock(originalClassName: IRequest::class),
            appManager: $this->appManager,
        );
    }//end controller()


    /**
     * The response headers the controller itself set.
     *
     * `Response::getHeaders()` merges in CSP defaults resolved from the OC
     * server container, which a pure unit test does not have, so the
     * controller-set header bag is read directly.
     *
     * @param Response $response The response under inspection
     *
     * @return array<string,string> The headers the controller set.
     */
    private function ownHeaders(Response $response): array
    {
        $property = new ReflectionProperty(Response::class, 'headers');

        return $property->getValue($response);
    }//end ownHeaders()


    /**
     * The built worker is served verbatim, as JavaScript, with a 200.
     *
     * @return void
     */
    public function testScriptServesTheBuiltWorkerAsJavaScript(): void
    {
        $source = file_get_contents(__DIR__.'/../../../src/offline/service-worker.js');
        $this->assertIsString($source, 'the app must ship a service-worker source to serve');
        file_put_contents($this->appPath.'/js/doriath-service-worker.js', $source);

        $response = $this->controller()->script();
        $headers  = $this->ownHeaders($response);

        $this->assertSame(
            Http::STATUS_OK,
            $response->getStatus(),
            'a present worker script must be served with 200'
        );
        $this->assertArrayHasKey('Content-Type', $headers);
        $this->assertSame(
            self::EXPECTED_MIME,
            $headers['Content-Type'],
            'the browser refuses to register a worker served with any other MIME type'
        );
        $this->assertSame(
            $source,
            $response->render(),
            'the body must be the built worker byte-for-byte, not a rewritten copy'
        );

        // The body really is a service worker, not an arbitrary payload: it
        // registers the install/activate/fetch lifecycle the shell depends on.
        $this->assertStringContainsString("self.addEventListener('install'", $response->render());
        $this->assertStringContainsString("self.addEventListener('activate'", $response->render());
        $this->assertStringContainsString("self.addEventListener('fetch'", $response->render());
    }//end testScriptServesTheBuiltWorkerAsJavaScript()


    /**
     * The worker script must never be cached by the browser.
     *
     * A cached worker pins the app shell to a retired build, which is exactly
     * the failure the version-keyed cache name is there to avoid, so
     * `cacheFor(0)` staying on this response is part of the contract.
     *
     * @return void
     */
    public function testScriptIsServedUncacheable(): void
    {
        file_put_contents(
            $this->appPath.'/js/doriath-service-worker.js',
            "self.addEventListener('install', () => {})\n"
        );

        $headers = $this->ownHeaders($this->controller()->script());

        $this->assertArrayHasKey('Cache-Control', $headers);
        $this->assertSame('no-cache, no-store, must-revalidate', $headers['Cache-Control']);
    }//end testScriptIsServedUncacheable()


    /**
     * A missing build artefact answers 404 — still as JavaScript.
     *
     * An HTML error page here would be registered by the browser as the worker
     * script and fail with a MIME error instead of a readable 404, so the
     * fallback keeps the JavaScript MIME and carries a JavaScript comment.
     *
     * @return void
     */
    public function testScriptAnswers404AsJavaScriptWhenTheBuildIsMissing(): void
    {
        $this->assertFileDoesNotExist(
            $this->appPath.'/js/doriath-service-worker.js',
            'this test only means anything while the artefact is absent'
        );

        $response = $this->controller()->script();
        $headers  = $this->ownHeaders($response);

        $this->assertSame(
            Http::STATUS_NOT_FOUND,
            $response->getStatus(),
            'an unbuilt worker must not be reported as a served one'
        );
        $this->assertSame(self::EXPECTED_MIME, $headers['Content-Type']);
        $this->assertSame('/* service worker unavailable */', $response->render());
    }//end testScriptAnswers404AsJavaScriptWhenTheBuildIsMissing()


}//end class
