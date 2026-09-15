<?php

/**
 * Keepiq connection observations.
 *
 * Turns an outcome Keepiq already has, such as the HTTP status of a range
 * lookup or which SIEM sinks took a delivery, into the status and message
 * integriq's connection registry shows (hydra change connection-registry,
 * design D4 and D6). Pure: it holds no state, reads nothing and sends nothing,
 * so every mapping is testable without a double.
 *
 * @category Service
 * @package  OCA\Keepiq\Service\Connection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service\Connection;

use Throwable;

/**
 * Maps outcomes to connection statuses and messages.
 *
 * Every message is built from fixed text, a number and a host. None of them
 * takes a string a user typed, an exception message or a full URL: a Guzzle
 * exception names the request URL, and on a range lookup that URL ends in the
 * caller's hash prefix.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
 */
class ConnectionObservations {

	/**
	 * The HTTP status that says the other side limited the call.
	 *
	 * @var int
	 */
	public const RATE_LIMITED_STATUS = 429;

	/**
	 * What one Have I Been Pwned range lookup says about the connection.
	 *
	 * Takes only the HTTP status. The prefix, the suffix list and the
	 * exception never reach this method, so they cannot reach a message.
	 *
	 * @param int|null $httpStatus The upstream's HTTP status, or null when nothing answered.
	 *
	 * @return array{0: string, 1: string} The status and the message.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function breachLookup(?int $httpStatus): array {
		if ($httpStatus === null) {
			return ['error', 'The last range lookup got no answer from Have I Been Pwned.'];
		}

		if ($httpStatus >= 200 && $httpStatus < 300) {
			return ['configured', 'The last range lookup reached Have I Been Pwned.'];
		}

		if ($httpStatus === self::RATE_LIMITED_STATUS) {
			return ['limited', 'Have I Been Pwned limited the last range lookup (HTTP 429).'];
		}

		return ['error', 'Have I Been Pwned answered HTTP ' . $httpStatus . ' on the last range lookup.'];
	}//end breachLookup()

	/**
	 * What a sink create, change or delete says about SIEM export.
	 *
	 * Only a state that blocks every delivery is reported. With sinks still
	 * enabled the refresh stands alone, and the row waits for the next drain.
	 *
	 * @param int $enabledSinks How many sinks are enabled after the save.
	 *
	 * @return array{0: string, 1: string}|null The status and the message, or null.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function siemSinksChanged(int $enabledSinks): ?array {
		if ($enabledSinks > 0) {
			return null;
		}

		return $this->noSinkEnabled();
	}//end siemSinksChanged()

	/**
	 * What one SIEM drain says about SIEM export.
	 *
	 * Only the sinks the drain delivered to in this run count. A sink's older
	 * delivery state may predate a save, and a refresh retires exactly that.
	 *
	 * @param int                                       $enabledSinks How many sinks are enabled.
	 * @param array<int, array{host: string, ok: bool}> $delivered    Per sink the drain delivered to: its host, and
	 *                                                                whether its last delivery went through.
	 *
	 * @return array{0: string, 1: string}|null The status and the message, or null when the drain met nothing.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function siemDrain(int $enabledSinks, array $delivered): ?array {
		if ($enabledSinks === 0) {
			return $this->noSinkEnabled();
		}

		$total = count($delivered);
		if ($total === 0) {
			return null;
		}

		$failed = array_values(array_filter($delivered, static fn (array $sink): bool => $sink['ok'] !== true));
		if ($failed === [] && $total === 1) {
			return ['configured', 'The SIEM sink' . $this->atHost(host: $delivered[0]['host']) . ' took the last delivery.'];
		}

		if ($failed === []) {
			return ['configured', 'All ' . $total . ' SIEM sinks took their last delivery.'];
		}

		if ($total === 1) {
			return ['error', 'The last delivery to the SIEM sink' . $this->atHost(host: $failed[0]['host']) . ' failed.'];
		}

		$firstFailure = '';
		if ($failed[0]['host'] !== '') {
			$firstFailure = ' The first to fail is at ' . $failed[0]['host'] . '.';
		}

		if (count($failed) === $total) {
			return ['error', 'None of the ' . $total . ' SIEM sinks took their last delivery.' . $firstFailure];
		}

		return [
			'limited',
			($total - count($failed)) . ' of ' . $total . ' SIEM sinks took their last delivery.' . $firstFailure,
		];
	}//end siemDrain()

	/**
	 * The host of a sink endpoint, and nothing else from it.
	 *
	 * A webhook endpoint is an https URL. A syslog endpoint is `host:port`, so
	 * it is read behind `tcp://`. A path, a query or user info can carry a
	 * token, and every admin reads the row.
	 *
	 * @param string $endpoint The sink's stored endpoint.
	 *
	 * @return string The host, or an empty string when there is none.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function siemSinkHost(string $endpoint): string {
		$endpoint = trim($endpoint);
		if (str_contains($endpoint, '://') === false) {
			$endpoint = 'tcp://' . $endpoint;
		}

		$host = parse_url($endpoint, PHP_URL_HOST);
		if (is_string($host) === false) {
			return '';
		}

		return $host;
	}//end siemSinkHost()

	/**
	 * The HTTP status a failed call still carries, or null when nothing answered.
	 *
	 * Nextcloud's HTTP client throws on a 4xx or 5xx answer. Guzzle's request
	 * exceptions keep that answer, and a connection failure has none.
	 *
	 * @param Throwable $exception What the call threw.
	 *
	 * @return int|null The answer's HTTP status, or null.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function httpStatusOf(Throwable $exception): ?int {
		if (method_exists($exception, 'getResponse') === false) {
			return null;
		}

		$response = $exception->getResponse();
		if (is_object($response) === false || method_exists($response, 'getStatusCode') === false) {
			return null;
		}

		return (int) $response->getStatusCode();
	}//end httpStatusOf()

	/**
	 * " at {host}", or nothing when the sink has no host.
	 *
	 * @param string $host The sink's host, possibly empty.
	 *
	 * @return string
	 */
	private function atHost(string $host): string {
		if ($host === '') {
			return '';
		}

		return ' at ' . $host;
	}//end atHost()

	/**
	 * The report for an instance where no sink is enabled.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function noSinkEnabled(): array {
		return ['unconfigured', 'No SIEM sink is switched on. Add one under SIEM audit export.'];
	}//end noSinkEnabled()
}//end class
