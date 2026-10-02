<?php

/**
 * Keepiq SIEM Transport
 *
 * The wire half of SIEM audit export (siem-audit-export §3): the single
 * place the syslog/webhook choice is made and the only code that opens a
 * socket or an HTTP client on a sink's behalf. Extracted from SiemService
 * so sink administration and queue drainage no longer carry the transport
 * clients alongside their own collaborators.
 *
 * The webhook HMAC secret is ICrypto-encrypted at rest and decrypted in
 * memory only, for the lifetime of one signature.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use DateTime;
use OCA\Keepiq\Db\SiemSink;
use OCA\Keepiq\Exception\SiemDeliveryException;
use OCA\Keepiq\Support\SuppressesDiagnostics;
use OCP\Http\Client\IClientService;
use OCP\Security\ICrypto;
use Throwable;

/**
 * Sends one SIEM payload over a sink's configured transport.
 */
class SiemTransport {
	use SuppressesDiagnostics;

	/**
	 * Per-request delivery timeout in seconds.
	 *
	 * @var int
	 */
	private const DELIVERY_TIMEOUT = 10;

	/**
	 * Constructor for SiemTransport.
	 *
	 * @param ICrypto $crypto NC crypto (HMAC secret at rest)
	 * @param IClientService $clientService The HTTP client factory (webhooks)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the transports carry the spec anchors.
	 */
	public function __construct(
		private ICrypto $crypto,
		private IClientService $clientService,
	) {
	}//end __construct()

	/**
	 * Send one payload over the sink's configured transport. The single
	 * place the syslog/webhook choice is made, shared by the delivery
	 * drain and the admin test-fire.
	 *
	 * @param SiemSink $sink The target sink
	 * @param string $payloadJson The JSON payload
	 *
	 * @return void
	 *
	 * @throws SiemDeliveryException On transport failure
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function deliver(SiemSink $sink, string $payloadJson): void {
		if ($sink->getType() === 'syslog') {
			$this->deliverSyslog(sink: $sink, payloadJson: $payloadJson);
			return;
		}

		$this->deliverWebhook(sink: $sink, payloadJson: $payloadJson);
	}//end deliver()

	/**
	 * Describe a failed delivery to this sink without its message: class,
	 * HTTP status and host only (keepiq#728).
	 *
	 * @param Throwable $exception The failure
	 * @param SiemSink $sink The sink
	 *
	 * @return string
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function describeFailure(Throwable $exception, SiemSink $sink): string {
		return (new SiemFailureOutcome())->describe(exception: $exception, sink: $sink);
	}//end describeFailure()

	/**
	 * RFC 5424 syslog delivery over TCP (TLS when configured, §3.1).
	 *
	 * @param SiemSink $sink The sink (endpoint host:port)
	 * @param string $payloadJson The JSON payload
	 *
	 * @return void
	 *
	 * @throws SiemDeliveryException On transport failure
	 */
	private function deliverSyslog(SiemSink $sink, string $payloadJson): void {
		$endpoint = $sink->getEndpoint();
		$scheme = 'tcp://';
		if ($sink->getTls() === true) {
			$scheme = 'tls://';
		}

		// The stream_socket_client() call warns on an unreachable endpoint and
		// returns false. Its detail is not re-reported: a failure is described
		// by class, status and host only (keepiq#728).
		$errno = 0;
		$errstr = '';
		$socket = $this->withoutDiagnostics(
			call: static function () use ($scheme, $endpoint, &$errno, &$errstr) {
				return stream_socket_client(
					$scheme . $endpoint,
					$errno,
					$errstr,
					self::DELIVERY_TIMEOUT
				);
			}
		);
		if ($socket === false) {
			throw new SiemDeliveryException(message: 'syslog connect failed');
		}

		try {
			// RFC 5424: <PRI>VERSION TIMESTAMP HOSTNAME APP-NAME PROCID MSGID SD MSG
			// PRI 134 = facility 16 (local0), severity 6 (informational).
			$message = '<134>1 ' . (new DateTime())->format('c') . ' nextcloud keepiq - - - ' . $payloadJson;
			// RFC 6587 octet-counted framing for TCP transport.
			$frame = strlen($message) . ' ' . $message;
			$written = fwrite($socket, $frame);
			if ($written === false || $written < strlen($frame)) {
				throw new SiemDeliveryException(message: 'syslog write failed');
			}
		} finally {
			fclose($socket);
		}
	}//end deliverSyslog()

	/**
	 * HTTPS webhook delivery with an HMAC-SHA256 signature header
	 * (§3.2). The secret is decrypted in memory only.
	 *
	 * @param SiemSink $sink The sink (HTTPS endpoint)
	 * @param string $payloadJson The JSON payload
	 *
	 * @return void
	 *
	 * @throws SiemDeliveryException On transport failure / non-2xx
	 */
	private function deliverWebhook(SiemSink $sink, string $payloadJson): void {
		$headers = ['Content-Type' => 'application/json'];
		$enc = $sink->getHmacSecretEnc();
		if ($enc !== null && $enc !== '') {
			$secret = $this->crypto->decrypt($enc);
			$headers['X-Keepiq-Signature'] = 'sha256=' . hash_hmac('sha256', $payloadJson, $secret);
		}

		$client = $this->clientService->newClient();
		$response = $client->post(
			$sink->getEndpoint(),
			[
				'body' => $payloadJson,
				'headers' => $headers,
				'timeout' => self::DELIVERY_TIMEOUT,
			]
		);
		$status = $response->getStatusCode();
		if ($status < 200 || $status > 299) {
			throw new SiemDeliveryException(message: 'webhook did not accept the payload', httpStatus: $status);
		}
	}//end deliverWebhook()
}//end class
