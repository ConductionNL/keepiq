<?php

/**
 * Doriath SIEM Transport
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
 * @package  OCA\Doriath\Service
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

namespace OCA\Doriath\Service;

use DateTime;
use OCA\Doriath\Db\SiemSink;
use OCA\Doriath\Support\SuppressesDiagnostics;
use OCP\Http\Client\IClientService;
use OCP\Security\ICrypto;
use RuntimeException;

/**
 * Sends one SIEM payload over a sink's configured transport.
 */
class SiemTransport
{
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
     * @param ICrypto        $crypto        NC crypto (HMAC secret at rest)
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
     * @param SiemSink $sink        The target sink
     * @param string   $payloadJson The JSON payload
     *
     * @return void
     *
     * @throws \RuntimeException On transport failure
     *
     * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
     */
    public function deliver(SiemSink $sink, string $payloadJson): void
    {
        if ($sink->getType() === 'syslog') {
            $this->deliverSyslog(sink: $sink, payloadJson: $payloadJson);
            return;
        }

        $this->deliverWebhook(sink: $sink, payloadJson: $payloadJson);
    }//end deliver()

    /**
     * RFC 5424 syslog delivery over TCP (TLS when configured, §3.1).
     *
     * @param SiemSink $sink        The sink (endpoint host:port)
     * @param string   $payloadJson The JSON payload
     *
     * @return void
     *
     * @throws \RuntimeException On transport failure
     */
    private function deliverSyslog(SiemSink $sink, string $payloadJson): void
    {
        $endpoint = $sink->getEndpoint();
        $scheme   = 'tcp://';
        if ($sink->getTls() === true) {
            $scheme = 'tls://';
        }

        // The stream_socket_client() call warns on an unreachable endpoint and
        // returns false; the detail is already captured in $errstr/$errno,
        // which the exception below re-reports.
        $errno  = 0;
        $errstr = '';
        $socket = $this->withoutDiagnostics(
            call: static function () use ($scheme, $endpoint, &$errno, &$errstr) {
                return stream_socket_client(
                    $scheme.$endpoint,
                    $errno,
                    $errstr,
                    self::DELIVERY_TIMEOUT
                );
            }
        );
        if ($socket === false) {
            throw new RuntimeException('syslog connect failed: '.$errstr.' ('.$errno.')');
        }

        try {
            // RFC 5424: <PRI>VERSION TIMESTAMP HOSTNAME APP-NAME PROCID MSGID SD MSG
            // PRI 134 = facility 16 (local0), severity 6 (informational).
            $message = '<134>1 '.(new DateTime())->format('c').' nextcloud doriath - - - '.$payloadJson;
            // RFC 6587 octet-counted framing for TCP transport.
            $frame   = strlen($message).' '.$message;
            $written = fwrite($socket, $frame);
            if ($written === false || $written < strlen($frame)) {
                throw new RuntimeException('syslog write failed');
            }
        } finally {
            fclose($socket);
        }
    }//end deliverSyslog()

    /**
     * HTTPS webhook delivery with an HMAC-SHA256 signature header
     * (§3.2). The secret is decrypted in memory only.
     *
     * @param SiemSink $sink        The sink (HTTPS endpoint)
     * @param string   $payloadJson The JSON payload
     *
     * @return void
     *
     * @throws \RuntimeException On transport failure / non-2xx
     */
    private function deliverWebhook(SiemSink $sink, string $payloadJson): void
    {
        $headers = ['Content-Type' => 'application/json'];
        $enc     = $sink->getHmacSecretEnc();
        if ($enc !== null && $enc !== '') {
            $secret = $this->crypto->decrypt($enc);
            $headers['X-Doriath-Signature'] = 'sha256='.hash_hmac('sha256', $payloadJson, $secret);
        }

        $client   = $this->clientService->newClient();
        $response = $client->post(
            $sink->getEndpoint(),
            [
                'body'    => $payloadJson,
                'headers' => $headers,
                'timeout' => self::DELIVERY_TIMEOUT,
            ]
        );
        $status   = $response->getStatusCode();
        if ($status < 200 || $status > 299) {
            throw new RuntimeException('webhook responded '.$status);
        }
    }//end deliverWebhook()
}//end class
