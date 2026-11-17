<?php

/**
 * Keepiq AddressRange
 *
 * @category Service
 * @package  OCA\Keepiq\Service\PrivilegedAccess
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

namespace OCA\Keepiq\Service\PrivilegedAccess;

use InvalidArgumentException;

/**
 * One IPv4 or IPv6 CIDR range an approved access window is limited to.
 *
 * A bare address is a range of that one address. An IPv4 address never falls
 * in an IPv6 range or the other way round, IPv4-mapped IPv6 addresses
 * included: the check compares the bytes the client was seen with.
 *
 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
 */
final class AddressRange {

	/**
	 * Constructor.
	 *
	 * @param string $network      The network address as packed bytes.
	 * @param int    $prefixLength The number of leading bits that must match.
	 */
	private function __construct(
		private readonly string $network,
		private readonly int $prefixLength,
	) {
	}//end __construct()

	/**
	 * Parse `192.0.2.0/24`, `2001:db8::/32` or a bare address.
	 *
	 * @param string $cidr The range as typed by the owner.
	 *
	 * @return self
	 *
	 * @throws InvalidArgumentException When it is not an address or the prefix is out of range.
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
	 */
	public static function parse(string $cidr): self {
		$parts   = explode('/', trim($cidr), 2);
		$network = @inet_pton($parts[0]);
		if ($network === false) {
			throw new InvalidArgumentException('Not an IP address range');
		}

		$maxBits = (strlen($network) * 8);
		$prefix  = $maxBits;
		if (count($parts) === 2) {
			if (preg_match('/^\d{1,3}$/', $parts[1]) !== 1 || (int) $parts[1] > $maxBits) {
				throw new InvalidArgumentException('Not an IP address range');
			}

			$prefix = (int) $parts[1];
		}

		return new self(network: self::masked(bytes: $network, prefixLength: $prefix), prefixLength: $prefix);
	}//end parse()

	/**
	 * Whether the client address falls in this range; an address that does
	 * not parse never does.
	 *
	 * @param string $address The client address, as Nextcloud reports it.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
	 */
	public function contains(string $address): bool {
		$bytes = @inet_pton(trim($address));
		if ($bytes === false || strlen($bytes) !== strlen($this->network)) {
			return false;
		}

		return self::masked(bytes: $bytes, prefixLength: $this->prefixLength) === $this->network;
	}//end contains()

	/**
	 * The range in canonical CIDR form, for storage and the audit trail.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/capability-requirements-from-the-matrix/tasks.md#task-3.1
	 */
	public function toString(): string {
		return inet_ntop($this->network) . '/' . $this->prefixLength;
	}//end toString()

	/**
	 * The bytes with every bit after the prefix cleared.
	 *
	 * @param string $bytes        Packed address bytes.
	 * @param int    $prefixLength Bits to keep.
	 *
	 * @return string
	 */
	private static function masked(string $bytes, int $prefixLength): string {
		$result = '';
		$length = strlen($bytes);
		for ($i = 0; $i < $length; $i++) {
			$keep    = max(0, min(8, ($prefixLength - ($i * 8))));
			$mask    = ((0xFF << (8 - $keep)) & 0xFF);
			$result .= chr(ord($bytes[$i]) & $mask);
		}

		return $result;
	}//end masked()
}//end class
