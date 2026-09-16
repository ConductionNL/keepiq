<?php

/**
 * ConnectionObservations unit tests.
 *
 * The mapper decides what integriq's Integrations page says about the breach
 * check and SIEM export. Each test guards one way the page could lie: a 429
 * read as broken, a partial SIEM outage read as fine, or a message that
 * carries a hash prefix, a token or a full URL.
 *
 * @category Tests
 * @package  OCA\Keepiq\Tests\Unit\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Service\Connection;

use OCA\Keepiq\Service\Connection\ConnectionObservations;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for ConnectionObservations.
 *
 * @covers \OCA\Keepiq\Service\Connection\ConnectionObservations
 */
class ConnectionObservationsTest extends TestCase {

	/**
	 * The mapper under test.
	 *
	 * @var ConnectionObservations
	 */
	private ConnectionObservations $observations;

	/**
	 * Set up the mapper.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->observations = new ConnectionObservations();
	}//end setUp()

	/**
	 * Every range lookup status maps to the status the design table names.
	 *
	 * @return void
	 */
	public function testARangeLookupMapsByStatusCode(): void {
		$this->assertSame(expected: 'configured', actual: $this->observations->breachLookup(httpStatus: 200)[0]);
		$this->assertSame(expected: 'limited', actual: $this->observations->breachLookup(httpStatus: 429)[0]);
		$this->assertSame(expected: 'error', actual: $this->observations->breachLookup(httpStatus: 503)[0]);
		$this->assertSame(expected: 'error', actual: $this->observations->breachLookup(httpStatus: 199)[0]);
		$this->assertSame(expected: 'error', actual: $this->observations->breachLookup(httpStatus: 300)[0]);
		$this->assertSame(expected: 'error', actual: $this->observations->breachLookup(httpStatus: null)[0]);
		$this->assertSame(
			expected: 'Have I Been Pwned answered HTTP 503 on the last range lookup.',
			actual: $this->observations->breachLookup(httpStatus: 503)[1]
		);
		$this->assertSame(
			expected: 'The last range lookup got no answer from Have I Been Pwned.',
			actual: $this->observations->breachLookup(httpStatus: null)[1]
		);
	}//end testARangeLookupMapsByStatusCode()

	/**
	 * A sink change reports only when no sink is left on.
	 *
	 * @return void
	 */
	public function testASinkChangeReportsOnlyWhenNoSinkIsOn(): void {
		$this->assertNull(actual: $this->observations->siemSinksChanged(enabledSinks: 2));
		$this->assertSame(
			expected: ['unconfigured', 'No SIEM sink is switched on. Add one under SIEM audit export.'],
			actual: $this->observations->siemSinksChanged(enabledSinks: 0)
		);
	}//end testASinkChangeReportsOnlyWhenNoSinkIsOn()

	/**
	 * A drain maps its delivered sinks to configured, limited or error.
	 *
	 * @return void
	 */
	public function testADrainMapsItsSinks(): void {
		$ok     = ['host' => 'siem.gemeente.example', 'ok' => true];
		$failed = ['host' => 'logs.gemeente.example', 'ok' => false];

		$this->assertSame(
			expected: ['unconfigured', 'No SIEM sink is switched on. Add one under SIEM audit export.'],
			actual: $this->observations->siemDrain(enabledSinks: 0, delivered: [])
		);
		$this->assertNull(actual: $this->observations->siemDrain(enabledSinks: 2, delivered: []));
		$this->assertSame(
			expected: ['configured', 'The SIEM sink at siem.gemeente.example took the last delivery.'],
			actual: $this->observations->siemDrain(enabledSinks: 1, delivered: [$ok])
		);
		$this->assertSame(
			expected: ['configured', 'All 2 SIEM sinks took their last delivery.'],
			actual: $this->observations->siemDrain(enabledSinks: 2, delivered: [$ok, $ok])
		);
		$this->assertSame(
			expected: ['error', 'The last delivery to the SIEM sink at logs.gemeente.example failed.'],
			actual: $this->observations->siemDrain(enabledSinks: 1, delivered: [$failed])
		);
		$this->assertSame(
			expected: ['limited', '1 of 2 SIEM sinks took their last delivery. The first to fail is at logs.gemeente.example.'],
			actual: $this->observations->siemDrain(enabledSinks: 2, delivered: [$ok, $failed])
		);
		$this->assertSame(
			expected: ['error', 'None of the 2 SIEM sinks took their last delivery. The first to fail is at logs.gemeente.example.'],
			actual: $this->observations->siemDrain(enabledSinks: 2, delivered: [$failed, $failed])
		);
	}//end testADrainMapsItsSinks()

	/**
	 * A sink with no host is left out of the message instead of printing an empty name.
	 *
	 * @return void
	 */
	public function testASinkWithoutAHostIsLeftOut(): void {
		$this->assertSame(
			expected: ['configured', 'The SIEM sink took the last delivery.'],
			actual: $this->observations->siemDrain(enabledSinks: 1, delivered: [['host' => '', 'ok' => true]])
		);
		$this->assertSame(
			expected: ['error', 'None of the 2 SIEM sinks took their last delivery.'],
			actual: $this->observations->siemDrain(enabledSinks: 2, delivered: [['host' => '', 'ok' => false], ['host' => '', 'ok' => false]])
		);
	}//end testASinkWithoutAHostIsLeftOut()

	/**
	 * A sink endpoint is reduced to its host: no scheme, user info, port, path or query.
	 *
	 * @return void
	 */
	public function testASinkEndpointIsReducedToItsHost(): void {
		$this->assertSame(
			expected: 'hooks.gemeente.example',
			actual: $this->observations->siemSinkHost(endpoint: 'https://svc:s3cr3t-token@hooks.gemeente.example:8443/ingest/abc123?token=s3cr3t-token')
		);
		$this->assertSame(expected: 'syslog.gemeente.example', actual: $this->observations->siemSinkHost(endpoint: 'syslog.gemeente.example:6514'));
		$this->assertSame(expected: '10.0.0.5', actual: $this->observations->siemSinkHost(endpoint: ' 10.0.0.5:514 '));
		$this->assertSame(expected: '', actual: $this->observations->siemSinkHost(endpoint: ''));
	}//end testASinkEndpointIsReducedToItsHost()

	/**
	 * A thrown answer keeps its status; a connection failure has none.
	 *
	 * @return void
	 */
	public function testAThrownAnswerKeepsItsStatus(): void {
		$answer = new class {

			/**
			 * The answer's status.
			 *
			 * @return int
			 */
			public function getStatusCode(): int {
				return 429;
			}//end getStatusCode()
		};

		$withAnswer = new class(message: 'Client error: GET https://api.pwnedpasswords.com/range/ABCDE', response: $answer) extends RuntimeException {

			/**
			 * Constructor.
			 *
			 * @param string $message  The exception message.
			 * @param object $response The answer the call got.
			 */
			public function __construct(string $message, private object $response) {
				parent::__construct(message: $message);
			}//end __construct()

			/**
			 * The answer the call got.
			 *
			 * @return object
			 */
			public function getResponse(): object {
				return $this->response;
			}//end getResponse()
		};

		$this->assertSame(expected: 429, actual: $this->observations->httpStatusOf(exception: $withAnswer));
		$this->assertNull(actual: $this->observations->httpStatusOf(exception: new RuntimeException('cURL error 28')));
	}//end testAThrownAnswerKeepsItsStatus()
}//end class
