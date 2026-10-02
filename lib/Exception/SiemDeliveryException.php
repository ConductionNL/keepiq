<?php

/**
 * Keepiq SIEM Delivery Exception
 *
 * Thrown by the SIEM transport when a sink did not take a payload. It carries
 * the HTTP status the sink answered with (null when nothing answered), so a
 * failure can be described without reading any message (keepiq#728).
 *
 * @category Exception
 * @package  OCA\Keepiq\Exception
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

namespace OCA\Keepiq\Exception;

use RuntimeException;

/**
 * A sink that did not take a payload.
 *
 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
 */
class SiemDeliveryException extends RuntimeException {
	/**
	 * Constructor for SiemDeliveryException.
	 *
	 * @param string $message A fixed description that names no endpoint
	 * @param int|null $httpStatus The sink's HTTP status, or null when nothing answered
	 *
	 * @return void
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function __construct(string $message, private ?int $httpStatus = null) {
		parent::__construct(message: $message);
	}//end __construct()

	/**
	 * The sink's HTTP status, or null when nothing answered.
	 *
	 * @return int|null
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-reliable-background-delivery
	 */
	public function getHttpStatus(): ?int {
		return $this->httpStatus;
	}//end getHttpStatus()
}//end class
