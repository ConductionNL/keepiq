<?php

/**
 * Keepiq SIEM Failure Outcome
 *
 * Describes a failed SIEM delivery for the log, the queue row, the sink row
 * and the admin screen without ever reading the exception's message: an
 * HTTP client names the full request URL in its message, and a webhook URL
 * can carry a token in its query string or its userinfo (keepiq#728). The
 * description holds the exception's short class name, the HTTP status (or
 * "no answer") and the sink's host, nothing else.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Exception\SiemDeliveryException;
use Throwable;

/**
 * Builds a credential-free description of a SIEM failure.
 *
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
 */
class SiemFailureOutcome {
	/**
	 * Describe a failed delivery to a sink.
	 *
	 * @param Throwable $exception The failure
	 * @param SiemSink|null $sink The sink, when one was involved
	 *
	 * @return string e.g. "ServerException (HTTP 500) at siem.example.org"
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function describe(Throwable $exception, ?SiemSink $sink = null): string {
		$status = $this->statusOf(exception: $exception);
		$outcome = '(no answer)';
		if ($status !== null) {
			$outcome = '(HTTP '.$status.')';
		}

		$description = $this->classOf(exception: $exception).' '.$outcome;
		$host = $this->hostOf(sink: $sink);
		if ($host !== '') {
			$description .= ' at '.$host;
		}

		return $description;
	}//end describe()

	/**
	 * The exception's short class name, for a failure with no sink.
	 *
	 * @param Throwable $exception The failure
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function classOf(Throwable $exception): string {
		$class = get_class($exception);
		$slash = strrpos($class, '\\');
		if ($slash === false) {
			return $class;
		}

		return substr($class, $slash + 1);
	}//end classOf()

	/**
	 * The HTTP status the sink answered with, read from the exception's
	 * answer and never from its message; null when nothing answered.
	 *
	 * @param Throwable $exception The failure
	 *
	 * @return int|null
	 */
	private function statusOf(Throwable $exception): ?int {
		if ($exception instanceof SiemDeliveryException) {
			return $exception->getHttpStatus();
		}

		if (method_exists($exception, 'getResponse') === true) {
			$response = $exception->getResponse();
			if (is_object($response) === true && method_exists($response, 'getStatusCode') === true) {
				return (int)$response->getStatusCode();
			}
		}

		return null;
	}//end statusOf()

	/**
	 * The sink's host only: no scheme, userinfo, port, path or query.
	 *
	 * @param SiemSink|null $sink The sink
	 *
	 * @return string The host, or '' when there is none
	 */
	private function hostOf(?SiemSink $sink): string {
		if ($sink === null) {
			return '';
		}

		$endpoint = $sink->getEndpoint();
		if (str_contains($endpoint, '://') === false) {
			// A syslog endpoint is host:port.
			$endpoint = 'tcp://'.$endpoint;
		}

		$host = parse_url($endpoint, PHP_URL_HOST);
		if (is_string($host) === false) {
			return '';
		}

		return $host;
	}//end hostOf()
}//end class
