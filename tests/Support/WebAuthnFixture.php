<?php

/**
 * A software authenticator for WebAuthn tests.
 *
 * Builds real create() and get() PublicKeyCredential JSON over a P-256 key:
 * CBOR attestation objects (format `none`), authenticator data and DER
 * ECDSA signatures, with every field overridable so a test can break one.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Support;

use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * One software passkey: a credential id and its P-256 key pair.
 */
final class WebAuthnFixture {
	public const RP_ID = 'cloud.example.com';

	public const ORIGIN = 'https://cloud.example.com';

	public const AAGUID = '00112233-4455-6677-8899-aabbccddeeff';

	private const FLAG_UP = 0x01;

	private const FLAG_UV = 0x04;

	private const FLAG_AT = 0x40;

	public readonly string $credentialId;

	private OpenSSLAsymmetricKey $key;

	/**
	 * Generate a fresh credential.
	 */
	public function __construct() {
		$this->credentialId = random_bytes(16);
		$this->key = self::newKey();
	}//end __construct()

	/**
	 * The credential id as the clients send it.
	 *
	 * @return string
	 */
	public function credentialIdB64(): string {
		return self::b64u($this->credentialId);
	}//end credentialIdB64()

	/**
	 * A create() PublicKeyCredential.
	 *
	 * @param string $challenge Raw challenge bytes
	 * @param array<string,mixed> $o Overrides: type, origin, rpId, fmt, flags, counter, clientChallenge
	 *
	 * @return array<string,mixed>
	 */
	public function attestation(string $challenge, array $o = []): array {
		$authData = $this->authData(
			rpId: $o['rpId'] ?? self::RP_ID,
			flags: $o['flags'] ?? (self::FLAG_UP | self::FLAG_UV | self::FLAG_AT),
			counter: $o['counter'] ?? 0,
		);
		$authData .= hex2bin(str_replace('-', '', self::AAGUID))
			. pack('n', strlen($this->credentialId)) . $this->credentialId
			. $this->coseKey();
		$attestationObject = self::cbor([
			'fmt' => $o['fmt'] ?? 'none',
			'attStmt' => [],
			'authData' => new CborBytes($authData),
		]);

		return [
			'id' => $this->credentialIdB64(),
			'rawId' => $this->credentialIdB64(),
			'type' => 'public-key',
			'response' => [
				'clientDataJSON' => self::b64u($this->clientData(
					type: $o['type'] ?? 'webauthn.create',
					challenge: $o['clientChallenge'] ?? $challenge,
					origin: $o['origin'] ?? self::ORIGIN,
				)),
				'attestationObject' => self::b64u($attestationObject),
			],
		];
	}//end attestation()

	/**
	 * A get() PublicKeyCredential signed by this credential's key (or by
	 * `signer` when given).
	 *
	 * @param string $challenge Raw challenge bytes
	 * @param string $uid The user handle
	 * @param array<string,mixed> $o Overrides: type, origin, rpId, flags, counter, signer, rawId
	 *
	 * @return array<string,mixed>
	 */
	public function assertion(string $challenge, string $uid, array $o = []): array {
		$authData = $this->authData(
			rpId: $o['rpId'] ?? self::RP_ID,
			flags: $o['flags'] ?? (self::FLAG_UP | self::FLAG_UV),
			counter: $o['counter'] ?? 0,
		);
		$clientData = $this->clientData(
			type: $o['type'] ?? 'webauthn.get',
			challenge: $challenge,
			origin: $o['origin'] ?? self::ORIGIN,
		);
		$signature = '';
		$signer = $o['signer'] ?? $this->key;
		if (openssl_sign($authData . hash('sha256', $clientData, true), $signature, $signer, OPENSSL_ALGO_SHA256) === false) {
			throw new RuntimeException('signing failed');
		}

		$rawId = self::b64u($o['rawId'] ?? $this->credentialId);

		return [
			'id' => $rawId,
			'rawId' => $rawId,
			'type' => 'public-key',
			'response' => [
				'clientDataJSON' => self::b64u($clientData),
				'authenticatorData' => self::b64u($authData),
				'signature' => self::b64u($signature),
				'userHandle' => self::b64u($uid),
			],
		];
	}//end assertion()

	/**
	 * A different P-256 key, to sign with the wrong key.
	 *
	 * @return OpenSSLAsymmetricKey
	 */
	public static function newKey(): OpenSSLAsymmetricKey {
		$key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
		if ($key === false) {
			throw new RuntimeException('key generation failed');
		}

		return $key;
	}//end newKey()

	/**
	 * Unpadded base64url.
	 *
	 * @param string $bytes Raw bytes
	 *
	 * @return string
	 */
	public static function b64u(string $bytes): string {
		return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
	}//end b64u()

	/**
	 * The fixed-length head of authenticator data.
	 *
	 * @param string $rpId The relying party id hashed into it
	 * @param int $flags The flag byte
	 * @param int $counter The signature counter
	 *
	 * @return string
	 */
	private function authData(string $rpId, int $flags, int $counter): string {
		return hash('sha256', $rpId, true) . chr($flags) . pack('N', $counter);
	}//end authData()

	/**
	 * The client data JSON.
	 *
	 * @param string $type webauthn.create or webauthn.get
	 * @param string $challenge Raw challenge bytes
	 * @param string $origin The origin
	 *
	 * @return string
	 */
	private function clientData(string $type, string $challenge, string $origin): string {
		return (string)json_encode(
			['type' => $type, 'challenge' => self::b64u($challenge), 'origin' => $origin, 'crossOrigin' => false],
			JSON_UNESCAPED_SLASHES
		);
	}//end clientData()

	/**
	 * The COSE_Key (EC2, ES256, P-256) of the public key.
	 *
	 * @return string
	 */
	private function coseKey(): string {
		$details = openssl_pkey_get_details($this->key);
		if ($details === false) {
			throw new RuntimeException('no key details');
		}

		return self::cbor([
			1 => 2,
			3 => -7,
			-1 => 1,
			-2 => new CborBytes(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
			-3 => new CborBytes(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
		]);
	}//end coseKey()

	/**
	 * Minimal CBOR: maps, integers, text strings and byte strings.
	 *
	 * @param mixed $value The value
	 *
	 * @return string
	 */
	private static function cbor(mixed $value): string {
		if ($value instanceof CborBytes) {
			return self::head(2, strlen($value->bytes)) . $value->bytes;
		}

		if (is_int($value) === true) {
			return $value >= 0 ? self::head(0, $value) : self::head(1, -1 - $value);
		}

		if (is_string($value) === true) {
			return self::head(3, strlen($value)) . $value;
		}

		if (is_array($value) === true) {
			$out = self::head(5, count($value));
			foreach ($value as $key => $item) {
				$out .= self::cbor($key) . self::cbor($item);
			}

			return $out;
		}

		throw new RuntimeException('unsupported CBOR value');
	}//end cbor()

	/**
	 * A CBOR item head.
	 *
	 * @param int $major The major type
	 * @param int $length The argument
	 *
	 * @return string
	 */
	private static function head(int $major, int $length): string {
		$type = $major << 5;
		if ($length < 24) {
			return chr($type | $length);
		}

		if ($length < 256) {
			return chr($type | 24) . chr($length);
		}

		return chr($type | 25) . pack('n', $length);
	}//end head()
}//end class
