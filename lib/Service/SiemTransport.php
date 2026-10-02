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
use OCA\Keepiq\AppInfo\Application as KeepiqApp;
use OCA\Keepiq\Exception\SiemDeliveryException;
use OCA\Keepiq\Service\Siem\CefFormatter;
use OCA\Keepiq\Service\Siem\SentinelRowFormatter;
use OCA\Keepiq\Service\Siem\SplunkHecFormatter;
use OCA\Keepiq\Support\SuppressesDiagnostics;
use OCP\App\IAppManager;
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
	 * @param IClientService $clientService The HTTP client factory (webhooks, Splunk, Sentinel)
	 * @param IAppManager|null $appManager The app manager (Keepiq version in CEF)
	 *
	 * @return void
	 *
	 * @spec exclude Constructor wiring only; the transports carry the spec anchors.
	 */
	public function __construct(
		private ICrypto $crypto,
		private IClientService $clientService,
		private ?IAppManager $appManager = null,
	) {
	}//end __construct()

	/**
	 * Sentinel access tokens, per tenant and client, for this process only:
	 * one drain run. A bearer token is a credential and is never written to a
	 * cache or table.
	 *
	 * @var array<string,string>
	 */
	private array $sentinelTokens = [];

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
		$type = $sink->getType();
		if ($type === 'syslog') {
			// A json sink sends the stored payload exactly as before; a cef
			// sink sends the CEF line in the same RFC 5424 frame.
			$message = $payloadJson;
			if ($sink->getFormat() === 'cef') {
				$message = (new CefFormatter())->format(payload: $this->decode(payloadJson: $payloadJson), appVersion: $this->appVersion());
			}

			$this->deliverSyslog(sink: $sink, payloadJson: $message);
			return;
		}

		if ($type === 'splunk_hec') {
			$this->deliverSplunk(sink: $sink, payload: $this->decode(payloadJson: $payloadJson));
			return;
		}

		if ($type === 'sentinel') {
			$this->deliverSentinel(sink: $sink, payload: $this->decode(payloadJson: $payloadJson));
			return;
		}

		$this->deliverWebhook(sink: $sink, payloadJson: $payloadJson);
	}//end deliver()

	/**
	 * Decode a queued payload.
	 *
	 * @param string $payloadJson The JSON payload
	 *
	 * @return array<string,mixed>
	 *
	 * @throws SiemDeliveryException On a payload that is not a JSON object
	 */
	private function decode(string $payloadJson): array {
		$payload = json_decode($payloadJson, true);
		if (is_array($payload) === false) {
			throw new SiemDeliveryException(message: 'queued payload is not a JSON object');
		}

		return $payload;
	}//end decode()

	/**
	 * The Keepiq version for the CEF header.
	 *
	 * @return string
	 */
	private function appVersion(): string {
		$version = $this->appManager?->getAppVersion(KeepiqApp::APP_ID);
		if ($version === null || $version === '') {
			return 'unknown';
		}

		return $version;
	}//end appVersion()

	/**
	 * The connector credential, decrypted in memory for one request.
	 *
	 * @param SiemSink $sink The sink
	 *
	 * @return string
	 *
	 * @throws SiemDeliveryException When no credential is stored
	 */
	private function credential(SiemSink $sink): string {
		$enc = $sink->getCredentialEnc();
		if ($enc === null || $enc === '') {
			throw new SiemDeliveryException(message: 'connector has no credential');
		}

		return $this->crypto->decrypt($enc);
	}//end credential()

	/**
	 * Splunk HTTP Event Collector delivery: one event per request,
	 * `Authorization: Splunk <token>`, success only on HTTP 200 with a
	 * response code of 0.
	 *
	 * @param SiemSink $sink The sink (HEC URL)
	 * @param array<string,mixed> $payload The payload
	 *
	 * @return void
	 *
	 * @throws SiemDeliveryException On any other answer
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-splunk-http-event-collector-delivery
	 */
	private function deliverSplunk(SiemSink $sink, array $payload): void {
		$host = (string)gethostname();
		$body = (new SplunkHecFormatter())->format(payload: $payload, host: $host, options: $sink->connectorOptionsArray());
		$response = $this->clientService->newClient()->post(
			$sink->getEndpoint(),
			[
				'body' => (string)json_encode($body),
				'headers' => [
					'Content-Type' => 'application/json',
					'Authorization' => 'Splunk ' . $this->credential(sink: $sink),
				],
				'timeout' => self::DELIVERY_TIMEOUT,
				'http_errors' => false,
			]
		);
		$status = $response->getStatusCode();
		$answer = json_decode((string)$response->getBody(), true);
		$code = null;
		if (is_array($answer) === true && array_key_exists('code', $answer) === true) {
			$code = (int)$answer['code'];
		}

		if ($status !== 200 || $code !== 0) {
			throw new SiemDeliveryException(message: 'splunk did not accept the event', httpStatus: $status);
		}
	}//end deliverSplunk()

	/**
	 * Microsoft Sentinel delivery through the Logs Ingestion API: a
	 * client-credentials token held for this run, one row per post, 204 as
	 * success, and one fresh token and retry after a 401.
	 *
	 * @param SiemSink $sink The sink (data collection endpoint)
	 * @param array<string,mixed> $payload The payload
	 *
	 * @return void
	 *
	 * @throws SiemDeliveryException On any other answer
	 *
	 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-microsoft-sentinel-delivery-through-the-logs-ingestion-api
	 */
	private function deliverSentinel(SiemSink $sink, array $payload): void {
		$options = $sink->connectorOptionsArray();
		$endpoint = rtrim(($options['dataCollectionEndpoint'] ?? $sink->getEndpoint()), '/');
		$url = $endpoint . '/dataCollectionRules/' . rawurlencode(($options['dcrImmutableId'] ?? ''))
			. '/streams/' . rawurlencode(($options['streamName'] ?? 'Custom-KeepiqAudit')) . '?api-version=2023-01-01';
		$body = (string)json_encode([(new SentinelRowFormatter())->format(payload: $payload)]);

		for ($attempt = 0; $attempt < 2; $attempt++) {
			$token = $this->sentinelToken(sink: $sink, options: $options);
			$response = $this->clientService->newClient()->post(
				$url,
				[
					'body' => $body,
					'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $token],
					'timeout' => self::DELIVERY_TIMEOUT,
					'http_errors' => false,
				]
			);
			$status = $response->getStatusCode();
			if ($status === 204) {
				return;
			}

			if ($status !== 401) {
				break;
			}

			// An expired or revoked token: forget it and try once more.
			unset($this->sentinelTokens[$this->tokenKey(options: $options)]);
		}

		throw new SiemDeliveryException(message: 'sentinel did not accept the row', httpStatus: $status);
	}//end deliverSentinel()

	/**
	 * The cache key of a Sentinel token.
	 *
	 * @param array<string,string> $options The connector options
	 *
	 * @return string
	 */
	private function tokenKey(array $options): string {
		return ($options['authorityHost'] ?? '') . '|' . ($options['tenantId'] ?? '') . '|' . ($options['clientId'] ?? '');
	}//end tokenKey()

	/**
	 * A Sentinel access token: the one this run already holds, or a new one
	 * from Entra ID with the client-credentials grant.
	 *
	 * @param SiemSink $sink The sink (client secret)
	 * @param array<string,string> $options The connector options
	 *
	 * @return string
	 *
	 * @throws SiemDeliveryException When Entra ID refuses
	 */
	private function sentinelToken(SiemSink $sink, array $options): string {
		$key = $this->tokenKey(options: $options);
		if (isset($this->sentinelTokens[$key]) === true) {
			return $this->sentinelTokens[$key];
		}

		$authority = rtrim(($options['authorityHost'] ?? 'https://login.microsoftonline.com'), '/');
		$response = $this->clientService->newClient()->post(
			$authority . '/' . rawurlencode(($options['tenantId'] ?? '')) . '/oauth2/v2.0/token',
			[
				'body' => http_build_query(
					[
						'grant_type' => 'client_credentials',
						'client_id' => ($options['clientId'] ?? ''),
						'client_secret' => $this->credential(sink: $sink),
						'scope' => 'https://monitor.azure.com//.default',
					]
				),
				'headers' => ['Content-Type' => 'application/x-www-form-urlencoded'],
				'timeout' => self::DELIVERY_TIMEOUT,
				'http_errors' => false,
			]
		);
		$status = $response->getStatusCode();
		$answer = json_decode((string)$response->getBody(), true);
		if ($status !== 200 || is_array($answer) === false || is_string(($answer['access_token'] ?? null)) === false) {
			throw new SiemDeliveryException(message: 'entra id refused the token request', httpStatus: $status);
		}

		$this->sentinelTokens[$key] = $answer['access_token'];
		return $answer['access_token'];
	}//end sentinelToken()

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
