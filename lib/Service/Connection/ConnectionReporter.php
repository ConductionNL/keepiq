<?php

/**
 * Keepiq connection reporter.
 *
 * Tells integriq's connection registry what only Keepiq can see about its two
 * outside connections: what the last Have I Been Pwned range lookup met, what
 * the last SIEM drain met, and which connection an admin save touched.
 * Integriq owns the rows the Integrations page lists and works out each status
 * itself (hydra change connection-registry, design D4). Keepiq reports, and
 * asks for a fresh resolve after a save.
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

use OCA\Keepiq\AppInfo\Application;
use OCA\Keepiq\Db\SiemSink;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sends connection reports and refresh requests to integriq.
 *
 * A save refreshes before it reports: under hydra#674 a refresh retires every
 * observation older than itself, so a report sent first would be thrown away.
 *
 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
 */
class ConnectionReporter {

	/**
	 * The app id integriq keys the rows by.
	 *
	 * @var string
	 */
	public const APP_ID = Application::APP_ID;

	/**
	 * Integriq's report event (ADR-041). Named by string so Keepiq stays
	 * installable without integriq: the class only exists when integriq does.
	 *
	 * @var string
	 */
	public const STATUS_EVENT = 'OCA\Integriq\Event\ConnectionStatusReportedEvent';

	/**
	 * Integriq's refresh event. Named by string for the same reason.
	 *
	 * @var string
	 */
	public const REFRESH_EVENT = 'OCA\Integriq\Event\ConnectionRefreshRequestedEvent';

	/**
	 * The Have I Been Pwned connection key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_HIBP = 'hibp';

	/**
	 * The SIEM audit export connection key in lib/Settings/connections.json.
	 *
	 * @var string
	 */
	public const KEY_SIEM = 'siem';

	/**
	 * The keys `lib/Settings/connections.json` declares, in declared order.
	 *
	 * A unit test keeps the two equal.
	 *
	 * @var array<int, string>
	 */
	public const KEYS = [self::KEY_HIBP, self::KEY_SIEM];

	/**
	 * Prefix of the app-config key that remembers the last report per connection.
	 *
	 * @var string
	 */
	public const MEMORY_KEY_PREFIX = 'connection_report_';

	/**
	 * Seconds after which the same status is reported again.
	 *
	 * @var int
	 */
	public const REPEAT_SECONDS = 3600;

	/**
	 * Seconds that must pass before a different status is reported.
	 *
	 * @var int
	 */
	public const CHANGE_SECONDS = 300;

	/**
	 * The pure outcome mapper.
	 *
	 * @var ConnectionObservations
	 */
	private readonly ConnectionObservations $observations;

	/**
	 * Constructor.
	 *
	 * @param IEventDispatcher $eventDispatcher Sends the integriq events (ADR-041).
	 * @param IAppConfig       $appConfig       Keeps the report memory.
	 * @param ITimeFactory     $timeFactory     Tells the time for the report memory.
	 * @param LoggerInterface  $logger          Records what could not be sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function __construct(
		private readonly IEventDispatcher $eventDispatcher,
		private readonly IAppConfig $appConfig,
		private readonly ITimeFactory $timeFactory,
		private readonly LoggerInterface $logger,
	) {
		$this->observations = new ConnectionObservations();
	}//end __construct()

	/**
	 * After an admin save wrote `breach_check_enabled`: ask integriq to look again.
	 *
	 * No report follows. Integriq reads the `hibp` switch itself (rule 2b), and
	 * a lookup reports once a user checks a password.
	 *
	 * @return bool True when the refresh was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function breachCheckSaved(): bool {
		return $this->refresh(key: self::KEY_HIBP);
	}//end breachCheckSaved()

	/**
	 * Report what one range lookup to Have I Been Pwned met.
	 *
	 * @param int|null $httpStatus The upstream's HTTP status, or null when nothing answered.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function reportBreachLookup(?int $httpStatus): bool {
		return $this->reportObserved(
			key: self::KEY_HIBP,
			observe: fn (): array => $this->observations->breachLookup(httpStatus: $httpStatus)
		);
	}//end reportBreachLookup()

	/**
	 * After a sink create, change or delete: refresh, then report when no sink is left on.
	 *
	 * The counts are only taken when integriq is installed, so without it the
	 * save costs no extra query. All sinks are only counted when none is
	 * enabled, to tell switched off from never added.
	 *
	 * @param callable(): int $enabledSinkCount Counts the sinks that are enabled after the save.
	 * @param callable(): int $sinkCount        Counts every sink after the save, enabled or not.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function siemSinksChanged(callable $enabledSinkCount, callable $sinkCount): bool {
		if ($this->refresh(key: self::KEY_SIEM) === false) {
			return false;
		}

		return $this->reportObserved(
			key: self::KEY_SIEM,
			observe: function () use ($enabledSinkCount, $sinkCount): ?array {
				$enabled = $enabledSinkCount();

				return $this->observations->siemSinksChanged(
					enabledSinks: $enabled,
					sinks: $this->countSinksWhenNoneEnabled(enabled: $enabled, sinkCount: $sinkCount)
				);
			}
		);
	}//end siemSinksChanged()

	/**
	 * Report what one SIEM drain met.
	 *
	 * @param int                   $enabledSinks   How many sinks are enabled.
	 * @param array<int, SiemSink>  $attemptedSinks The sinks this drain tried to deliver to, after the attempt.
	 * @param callable(): int       $sinkCount      Counts every sink, enabled or not. Only called when none is enabled.
	 *
	 * @return bool True when a report was sent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	public function reportSiemDrain(int $enabledSinks, array $attemptedSinks, callable $sinkCount): bool {
		return $this->reportObserved(
			key: self::KEY_SIEM,
			observe: fn (): ?array => $this->observations->siemDrain(
				enabledSinks: $enabledSinks,
				delivered: array_map(
					fn (SiemSink $sink): array => [
						'host' => $this->observations->siemSinkHost(endpoint: $sink->getEndpoint()),
						'ok'   => $sink->getLastDeliveryStatus() === 'ok',
					],
					array_values($attemptedSinks)
				),
				sinks: $this->countSinksWhenNoneEnabled(enabled: $enabledSinks, sinkCount: $sinkCount)
			)
		);
	}//end reportSiemDrain()

	/**
	 * Every sink, counted only when none is enabled; otherwise the enabled count stands in.
	 *
	 * With a sink enabled the total cannot change the report, so the query is skipped.
	 *
	 * @param int             $enabled   How many sinks are enabled.
	 * @param callable(): int $sinkCount Counts every sink.
	 *
	 * @return int
	 */
	private function countSinksWhenNoneEnabled(int $enabled, callable $sinkCount): int {
		if ($enabled > 0) {
			return $enabled;
		}

		return $sinkCount();
	}//end countSinksWhenNoneEnabled()

	/**
	 * The HTTP status a failed call still carries, for {@see reportBreachLookup()}.
	 *
	 * Pure: reads, stores and sends nothing.
	 *
	 * @param Throwable $exception What the call threw.
	 *
	 * @return int|null The answer's HTTP status, or null when nothing answered.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-003-a-report-names-a-status-code-or-a-host-and-nothing-a-user-typed
	 */
	public function httpStatusOf(Throwable $exception): ?int {
		return $this->observations->httpStatusOf(exception: $exception);
	}//end httpStatusOf()

	/**
	 * The event class to instantiate, or null when integriq does not ship it.
	 *
	 * @param string $eventClass The fully qualified class name, without a leading backslash.
	 *
	 * @return string|null The class name to instantiate, or null when absent.
	 *
	 * @spec openspec/changes/adopt-connection-registry/specs/admin-integrations/spec.md#requirement-req-keepiq-conn-002-a-save-asks-integriq-to-look-again-and-a-lookup-or-a-drain-reports-what-it-met
	 */
	protected function resolveEventClass(string $eventClass): ?string {
		$qualified = '\\' . $eventClass;
		if (class_exists($qualified) === false) {
			return null;
		}

		return $qualified;
	}//end resolveEventClass()

	/**
	 * Ask integriq to resolve one connection again, and forget its report memory.
	 *
	 * Forgetting lets the first outcome after a save go out at once instead of
	 * waiting out the hour. Never throws.
	 *
	 * @param string $key One of {@see self::KEYS}.
	 *
	 * @return bool True when the event was dispatched.
	 */
	private function refresh(string $key): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::REFRESH_EVENT);
		if ($eventClass === null) {
			return false;
		}

		$this->forget(key: $key);

		return $this->send(
			key: $key,
			build: static fn (): object => new $eventClass(
				app: self::APP_ID,
				key: $key,
			)
		);
	}//end refresh()

	/**
	 * Observe, throttle and send one status report. Never throws.
	 *
	 * Without integriq the class check fails first, so nothing is read,
	 * stored, sent or logged.
	 *
	 * @param string                                         $key     One of {@see self::KEYS}.
	 * @param callable(): (array{0: string, 1: string}|null) $observe Works out the status and message, or null to report nothing.
	 *
	 * @return bool True when a report was sent.
	 */
	private function reportObserved(string $key, callable $observe): bool {
		$eventClass = $this->resolveEventClass(eventClass: self::STATUS_EVENT);
		if ($eventClass === null) {
			return false;
		}

		try {
			$observed = $observe();
			if ($observed === null) {
				return false;
			}

			[$status, $message] = $observed;

			$now = $this->timeFactory->getTime();
			if ($this->isDue(key: $key, status: $status, now: $now) === false) {
				return false;
			}

			$sent = $this->send(
				key: $key,
				build: static fn (): object => new $eventClass(
					app: self::APP_ID,
					key: $key,
					status: $status,
					message: $message,
				)
			);
			if ($sent === true) {
				$this->appConfig->setValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, $status . '|' . $now);
			}

			return $sent;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: could not report a connection to integriq',
				['key' => $key, 'exception' => $e::class]
			);
			return false;
		}//end try
	}//end reportObserved()

	/**
	 * Whether the report memory allows a report with this status now.
	 *
	 * A different status waits five minutes after the last report, so an
	 * upstream that flips cannot report on every call. The same status
	 * reports again after an hour.
	 *
	 * @param string $key    The connection key.
	 * @param string $status The status the call observed.
	 * @param int    $now    The current Unix time.
	 *
	 * @return bool
	 */
	private function isDue(string $key, string $status, int $now): bool {
		$memory = $this->appConfig->getValueString(self::APP_ID, self::MEMORY_KEY_PREFIX . $key, '');
		$parts  = explode('|', $memory, 2);
		if (count($parts) !== 2 || ctype_digit($parts[1]) === false) {
			return true;
		}

		$elapsed = ($now - (int) $parts[1]);
		if ($parts[0] === $status) {
			return $elapsed >= self::REPEAT_SECONDS;
		}

		return $elapsed >= self::CHANGE_SECONDS;
	}//end isDue()

	/**
	 * Clear the report memory of one connection.
	 *
	 * @param string $key The connection key.
	 *
	 * @return void
	 */
	private function forget(string $key): void {
		try {
			$this->appConfig->deleteKey(self::APP_ID, self::MEMORY_KEY_PREFIX . $key);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: could not clear a connection report memory',
				['key' => $key, 'exception' => $e::class]
			);
		}
	}//end forget()

	/**
	 * Build and dispatch one event, swallowing anything a listener throws.
	 *
	 * The log names the exception class only. A listener's message could quote
	 * the event, and the event is not for the log.
	 *
	 * @param string             $key   The connection the event is about, for the log.
	 * @param callable(): object $build Builds the event.
	 *
	 * @return bool True when the event was dispatched without an exception.
	 */
	private function send(string $key, callable $build): bool {
		try {
			$event = $build();
			if (($event instanceof Event) === false) {
				return false;
			}

			$this->eventDispatcher->dispatchTyped($event);
			return true;
		} catch (Throwable $e) {
			$this->logger->warning(
				'Keepiq: could not send a connection event to integriq',
				['key' => $key, 'exception' => $e::class]
			);
			return false;
		}
	}//end send()
}//end class
