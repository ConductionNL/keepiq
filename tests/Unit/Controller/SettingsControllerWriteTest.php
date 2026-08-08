<?php

/**
 * Unit tests for the SettingsController write path (PUT/POST /api/settings).
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

use OCA\Doriath\Controller\SettingsController;
use OCA\Doriath\Service\SettingsService;
use OCA\Doriath\Settings\AdminSettings;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\IRequest;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The canonical AppHost route table routes BOTH `PUT /api/settings`
 * (`settings#update`) and `POST /api/settings` (`settings#create`) into this
 * controller, and because Doriath ships the class itself no generic is aliased
 * in to cover either.
 *
 * These tests assert the ITEM — that the write actually reaches
 * `SettingsService::updateSettings()` with the request's own parameters, and
 * that the response carries the service's refreshed result. A test that only
 * checked for a 200, or only that the response was a JSONResponse, would pass
 * against a controller that silently wrote nothing.
 */
class SettingsControllerWriteTest extends TestCase
{

    /**
     * The mocked request.
     *
     * @var IRequest&MockObject
     */
    private IRequest&MockObject $request;

    /**
     * The mocked settings service.
     *
     * @var SettingsService&MockObject
     */
    private SettingsService&MockObject $settingsService;


    /**
     * Set up the mocks shared by every test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->request         = $this->createMock(IRequest::class);
        $this->settingsService = $this->createMock(SettingsService::class);
    }//end setUp()


    /**
     * Build the controller under test with its collaborators mocked.
     *
     * @return SettingsController The controller under test.
     */
    private function controller(): SettingsController
    {
        return new SettingsController(
            request: $this->request,
            settingsService: $this->settingsService,
            userSession: $this->createMock(IUserSession::class)
        );
    }//end controller()


    /**
     * PUT /api/settings must persist the request parameters and return the
     * refreshed settings the service reports back.
     *
     * @return void
     */
    public function testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig(): void
    {
        $submitted = ['register' => 'b7d1c0f6-0000-4000-8000-000000000001'];
        $stored    = [
            'register'      => 'b7d1c0f6-0000-4000-8000-000000000001',
            'openregisters' => true,
            'isAdmin'       => true,
        ];

        $this->request->method('getParams')->willReturn($submitted);

        // The ITEM: the write reaches the service, with the submitted params.
        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $response = $this->controller()->update();

        $this->assertSame(
            [
                'success' => true,
                'config'  => $stored,
            ],
            $response->getData(),
            'update() must return the config the service actually stored, not the submission'
        );
    }//end testUpdatePersistsTheRequestParametersAndReturnsTheStoredConfig()


    /**
     * POST /api/settings is the legacy alias and must write identically.
     *
     * `src/components/settings/PasswordPolicySection.vue::save()` and
     * `src/store/modules/settings.js::saveSettings()` still POST here, so the
     * alias staying a real write — not an empty success — is load-bearing.
     *
     * @return void
     */
    public function testCreateDelegatesToUpdateAndStillWrites(): void
    {
        $submitted = [
            'master_password_min_length' => '16',
            'master_password_min_score'  => '4',
        ];
        $stored    = [
            'register'      => '',
            'openregisters' => true,
            'isAdmin'       => true,
        ];

        $this->request->method('getParams')->willReturn($submitted);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with($submitted)
            ->willReturn($stored);

        $response = $this->controller()->create();

        $this->assertSame(
            [
                'success' => true,
                'config'  => $stored,
            ],
            $response->getData(),
            'create() must produce the same written result as update()'
        );
    }//end testCreateDelegatesToUpdateAndStillWrites()


    /**
     * The write must not be skipped when the submission is empty.
     *
     * An early return on an empty payload would look identical to a successful
     * no-op write from the caller's side.
     *
     * @return void
     */
    public function testEmptySubmissionStillReachesTheService(): void
    {
        $this->request->method('getParams')->willReturn([]);

        $this->settingsService->expects($this->once())
            ->method('updateSettings')
            ->with([])
            ->willReturn(['register' => '']);

        $response = $this->controller()->update();

        $this->assertSame(
            [
                'success' => true,
                'config'  => ['register' => ''],
            ],
            $response->getData()
        );
    }//end testEmptySubmissionStillReachesTheService()


    /**
     * Both write entry points must carry the admin gate themselves.
     *
     * Nextcloud's SecurityMiddleware evaluates the attributes of the DISPATCHED
     * method only, so `create()` delegating to `update()` does not inherit
     * `update()`'s posture. If the attribute were dropped from either method,
     * that verb would become reachable by any authenticated user.
     *
     * @return void
     */
    public function testBothWriteEntryPointsDeclareTheAdminGate(): void
    {
        $checked = 0;

        foreach (['update', 'create'] as $method) {
            $attributes = (new ReflectionMethod(SettingsController::class, $method))
                ->getAttributes(AuthorizedAdminSetting::class);

            $checked++;

            $this->assertCount(
                1,
                $attributes,
                sprintf(
                    'SettingsController::%s() is a WRITE and must declare '
                    .'#[AuthorizedAdminSetting] on its own — the middleware only reads the '
                    .'dispatched method\'s attributes.',
                    $method
                )
            );

            $this->assertSame(
                [AdminSettings::class],
                $attributes[0]->getArguments(),
                sprintf('SettingsController::%s() must gate on Doriath\'s own AdminSettings panel', $method)
            );
        }

        // Positive control: the loop above is only meaningful if it ran.
        $this->assertSame(2, $checked, 'Both write entry points must have been inspected');
    }//end testBothWriteEntryPointsDeclareTheAdminGate()


}//end class
