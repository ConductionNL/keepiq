<?php

/**
 * Unit tests for SiteDirectoryService.
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
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service;

use DateTime;
use OCA\Keepiq\Service\SiteDirectoryService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\AppData\IAppDataFactory;
use OCP\Files\IAppData;
use OCP\Files\NotFoundException;
use OCP\Files\SimpleFS\ISimpleFile;
use OCP\Files\SimpleFS\ISimpleFolder;
use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\IAppConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The site directory: fetched whole, only while switched on, the last copy kept on failure.
 */
#[CoversClass(SiteDirectoryService::class)]
class SiteDirectoryServiceTest extends TestCase {

	/** @var IAppConfig&MockObject */
	private IAppConfig $appConfig;

	/** @var IClient&MockObject */
	private IClient $client;

	/** @var IClientService&MockObject */
	private IClientService $clientService;

	/** @var IAppData&MockObject */
	private IAppData $appData;

	/** @var LoggerInterface&MockObject */
	private LoggerInterface $logger;

	/** @var string|null The stored copy, as the fake app data file holds it. */
	private ?string $stored = null;

	private SiteDirectoryService $service;

	/**
	 * Wire the service over mocks; the app data folder is a fake holding one file.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->appConfig     = $this->createMock(IAppConfig::class);
		$this->client        = $this->createMock(IClient::class);
		$this->clientService = $this->createMock(IClientService::class);
		$this->clientService->method('newClient')->willReturn($this->client);
		$this->appData = $this->createMock(IAppData::class);
		$factory       = $this->createMock(IAppDataFactory::class);
		$factory->method('get')->with('keepiq')->willReturn($this->appData);
		$time = $this->createMock(ITimeFactory::class);
		$time->method('getDateTime')->willReturn(new DateTime('2026-11-14T10:00:00+00:00'));
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new SiteDirectoryService(
			appConfig: $this->appConfig,
			clientService: $this->clientService,
			appDataFactory: $factory,
			time: $time,
			logger: $this->logger,
		);
	}//end setUp()

	/**
	 * A folder whose one file reads and writes $this->stored.
	 *
	 * @return ISimpleFolder&MockObject
	 */
	private function fakeFolder(): ISimpleFolder {
		$file = $this->createMock(ISimpleFile::class);
		$file->method('getContent')->willReturnCallback(fn () => (string)$this->stored);
		$file->method('putContent')->willReturnCallback(function ($data): void {
			$this->stored = (string)$data;
		});
		$folder = $this->createMock(ISimpleFolder::class);
		$folder->method('fileExists')->willReturnCallback(fn () => $this->stored !== null);
		$folder->method('newFile')->willReturn($file);
		$folder->method('getFile')->willReturnCallback(function () use ($file) {
			if ($this->stored === null) {
				throw new NotFoundException('no copy');
			}

			return $file;
		});

		return $folder;
	}//end fakeFolder()

	/**
	 * Switch the directory on or off.
	 *
	 * @param bool $enabled The switch
	 *
	 * @return void
	 */
	private function switchedOn(bool $enabled): void {
		$this->appConfig->method('getValueBool')->with('keepiq', 'site_directory_enabled', false)->willReturn($enabled);
	}//end switchedOn()

	/**
	 * An HTTP answer with a body.
	 *
	 * @param string $body The body
	 *
	 * @return IResponse&MockObject
	 */
	private function answer(string $body): IResponse {
		$response = $this->createMock(IResponse::class);
		$response->method('getBody')->willReturn($body);

		return $response;
	}//end answer()

	/**
	 * Off by default: no request goes out and nothing is stored.
	 *
	 * @return void
	 */
	public function testSwitchedOffNothingIsFetched(): void {
		$this->switchedOn(enabled: false);
		$this->client->expects($this->never())->method('get');
		$this->appData->expects($this->never())->method('getFolder');

		$this->assertFalse($this->service->refresh());
		$this->assertFalse($this->service->isEnabled());
	}//end testSwitchedOffNothingIsFetched()

	/**
	 * A refresh downloads both directories whole and keeps each domain with its documentation link.
	 *
	 * @return void
	 */
	public function testARefreshStoresBothDirectoriesByDomain(): void {
		$this->switchedOn(enabled: true);
		$this->appData->method('getFolder')->willReturn($this->fakeFolder());
		$this->client->expects($this->exactly(2))->method('get')->willReturnCallback(
			function (string $url, array $options) {
				$this->assertSame(30, $options['timeout']);
				if ($url === SiteDirectoryService::TOTP_URL) {
					return $this->answer(body: (string)json_encode([
						'GitHub.com' => ['methods' => ['totp'], 'documentation' => 'https://docs.github.com/2fa'],
						'example.org' => ['methods' => ['totp'], 'documentation' => 'http://example.org/insecure'],
						'bad domain' => ['methods' => ['totp']],
					]));
				}

				$this->assertSame(SiteDirectoryService::PASSKEY_URL, $url);
				return $this->answer(body: (string)json_encode([
					'example.com' => ['passwordless' => 'allowed', 'documentation' => 'https://example.com/passkeys'],
				]));
			}
		);

		$this->assertTrue($this->service->refresh());

		$this->assertSame(
			[
				'fetchedAt' => '2026-11-14T10:00:00+00:00',
				'totp' => ['example.org' => '', 'github.com' => 'https://docs.github.com/2fa'],
				'passkeys' => ['example.com' => 'https://example.com/passkeys'],
			],
			json_decode((string)$this->service->current(), true)
		);
	}//end testARefreshStoresBothDirectoriesByDomain()

	/**
	 * A network error keeps yesterday's copy.
	 *
	 * @return void
	 */
	public function testAFailedRefreshKeepsTheLastCopy(): void {
		$this->switchedOn(enabled: true);
		$this->appData->method('getFolder')->willReturn($this->fakeFolder());
		$this->stored = '{"fetchedAt":"2026-11-13T10:00:00+00:00","totp":{"github.com":""},"passkeys":{}}';
		$this->client->method('get')->willThrowException(new RuntimeException('connection refused'));
		$this->logger->expects($this->once())->method('warning')
			->with($this->stringContains('the last copy is kept'));

		$this->assertFalse($this->service->refresh());
		$this->assertSame('{"fetchedAt":"2026-11-13T10:00:00+00:00","totp":{"github.com":""},"passkeys":{}}', $this->service->current());
	}//end testAFailedRefreshKeepsTheLastCopy()

	/**
	 * An answer that is not a directory (an error page, a list) is refused and the copy kept.
	 *
	 * @return void
	 */
	public function testAnAnswerThatIsNotADirectoryKeepsTheLastCopy(): void {
		$this->switchedOn(enabled: true);
		$this->appData->method('getFolder')->willReturn($this->fakeFolder());
		$this->stored = '{"old":true}';
		$this->client->method('get')->willReturnOnConsecutiveCalls(
			$this->answer(body: '{"github.com":{"documentation":"https://x"}}'),
			$this->answer(body: '["not","a","directory"]')
		);

		$this->assertFalse($this->service->refresh());
		$this->assertSame('{"old":true}', $this->service->current());
	}//end testAnAnswerThatIsNotADirectoryKeepsTheLastCopy()

	/**
	 * Before the first refresh there is no copy.
	 *
	 * @return void
	 */
	public function testNoCopyBeforeTheFirstRefresh(): void {
		$this->appData->method('getFolder')->willThrowException(new NotFoundException('no folder'));

		$this->assertNull($this->service->current());
	}//end testNoCopyBeforeTheFirstRefresh()

	/**
	 * The first refresh creates the folder.
	 *
	 * @return void
	 */
	public function testTheFirstRefreshCreatesTheFolder(): void {
		$this->switchedOn(enabled: true);
		$this->appData->method('getFolder')->willThrowException(new NotFoundException('no folder'));
		$this->appData->expects($this->once())->method('newFolder')->with('site-directory')->willReturn($this->fakeFolder());
		$this->client->method('get')->willReturn($this->answer(body: '{"github.com":{}}'));

		$this->assertTrue($this->service->refresh());
		$this->assertSame(['github.com' => ''], json_decode((string)$this->stored, true)['passkeys']);
	}//end testTheFirstRefreshCreatesTheFolder()
}//end class
