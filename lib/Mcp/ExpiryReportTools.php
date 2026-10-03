<?php

/**
 * Keepiq MCP: expiry report
 *
 * @category Mcp
 * @package  OCA\Keepiq\Mcp
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

namespace OCA\Keepiq\Mcp;

use DateTime;
use DateTimeInterface;
use InvalidArgumentException;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Service\CertificateLifecycleService;
use OCA\OpenRegister\Mcp\Attribute\McpTool;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * keepiq.expiryReport: the caller's certificates and secrets that expire
 * within a window, and those already expired.
 *
 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-expiry-report-tool
 */
class ExpiryReportTools {

	/**
	 * Constructor.
	 *
	 * @param CertificateLifecycleService $certificates The certificate inventory
	 * @param SecretMapper $secretMapper The caller's secrets (expiry dates)
	 * @param ITimeFactory $time The clock
	 * @param McpToolContext $context The principal and the audit
	 * @param MetadataAllowList $allowList The keys a result may carry
	 *
	 * @return void
	 */
	public function __construct(
		private CertificateLifecycleService $certificates,
		private SecretMapper $secretMapper,
		private ITimeFactory $time,
		private McpToolContext $context,
		private MetadataAllowList $allowList = new MetadataAllowList(),
	) {
	}//end __construct()

	#[McpTool(
		name: 'expiryReport',
		description: 'List your Keepiq certificates and secrets that expire within the given number of days '
			. '(default 30, at most 365), and those already expired, with subject, issuer, serial, expiry '
			. 'date and days remaining. Never returns a certificate body, key or secret value.',
		readOnlyHint: true,
		destructiveHint: false,
		idempotentHint: true,
		scope: 'read',
		subject: 'entry',
		action: 'list'
	)]
	/**
	 * Report what expires within withinDays, plus what already expired.
	 *
	 * @param int $withinDays The window in days, 0 to 365
	 *
	 * @return array{withinDays: int, certificates: list<array<string,scalar|null>>, secrets: list<array<string,scalar|null>>}
	 *
	 * @throws InvalidArgumentException On a window outside 0..365
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-expiry-report-tool
	 */
	public function expiryReport(int $withinDays = 30): array {
		if ($withinDays < 0 || $withinDays > 365) {
			throw new InvalidArgumentException('withinDays must be between 0 and 365');
		}

		$userId = $this->context->userId();
		$now = $this->time->getDateTime();
		$until = (clone $now)->modify('+' . $withinDays . ' days');

		[$certificates, $certificateIds] = $this->expiringCertificates(userId: $userId, now: $now, until: $until);

		$secrets = [];
		foreach ($this->secretMapper->findByOwner(ownerType: 'user', ownerId: $userId, state: SecretMapper::STATE_LIVE) as $secret) {
			$when = $secret->getExpiresAt();
			if ($when === null || $when > $until || isset($certificateIds[$secret->getId()]) === true) {
				continue;
			}

			$secrets[] = $this->allowList->project(
				row: [
					'id' => $secret->getId(),
					'name' => $secret->getName(),
					'expiresAt' => $when->format(DateTimeInterface::ATOM),
					'daysRemaining' => $this->daysBetween(from: $now, to: $when),
					'expired' => ($when < $now),
				],
				type: 'expiringSecret'
			);
		}

		$this->context->audit(userId: $userId, tool: 'expiryReport', resultCount: (count($certificates) + count($secrets)));
		return ['withinDays' => $withinDays, 'certificates' => $certificates, 'secrets' => $secrets];
	}//end expiryReport()

	/**
	 * The user's stored certificates that lapse by $until, and the ids of
	 * every stored certificate (so the secret list does not repeat them).
	 *
	 * @param string $userId The principal
	 * @param DateTime $now Now
	 * @param DateTime $until The end of the window
	 *
	 * @return array{0: list<array<string,scalar|null>>, 1: array<string,true>}
	 *
	 * @spec openspec/changes/hermiq-ai-tooling/specs/mcp-metadata-surface/spec.md#requirement-expiry-report-tool
	 */
	private function expiringCertificates(string $userId, DateTime $now, DateTime $until): array {
		$certificates = [];
		$certificateIds = [];
		foreach ($this->certificates->inventory(userId: $userId, isAdmin: false)['stored'] as $row) {
			$metadata = ($row['metadata'] ?? []);
			$notAfter = ($metadata['notAfter'] ?? null) ?? ($row['expiresAt'] ?? null);
			$when = $this->parse(value: $notAfter);
			$certificateIds[(string)$row['id']] = true;
			if ($when === null || $when > $until) {
				continue;
			}

			$certificates[] = $this->allowList->project(
				row: [
					'id' => $row['id'],
					'name' => $row['name'],
					'subject' => ($metadata['subject'] ?? null),
					'issuer' => ($metadata['issuer'] ?? null),
					'serial' => ($metadata['serial'] ?? null),
					'fingerprintSha256' => ($metadata['fingerprintSha256'] ?? null),
					'notAfter' => $when->format(DateTimeInterface::ATOM),
					'daysRemaining' => $this->daysBetween(from: $now, to: $when),
					'expired' => ($when < $now),
				],
				type: 'certificate'
			);
		}//end foreach

		return [$certificates, $certificateIds];
	}//end expiringCertificates()

	/**
	 * Parse an ISO 8601 value.
	 *
	 * @param mixed $value The value
	 *
	 * @return DateTime|null
	 */
	private function parse(mixed $value): ?DateTime {
		if (is_string($value) === false || $value === '') {
			return null;
		}

		$parsed = date_create_from_format(DateTimeInterface::ATOM, $value);
		if ($parsed === false) {
			return null;
		}

		return $parsed;
	}//end parse()

	/**
	 * Whole days from one moment to another; negative when past.
	 *
	 * @param DateTime $from The start
	 * @param DateTime $to The end
	 *
	 * @return int
	 */
	private function daysBetween(DateTime $from, DateTime $to): int {
		return (int)floor(($to->getTimestamp() - $from->getTimestamp()) / 86400);
	}//end daysBetween()
}//end class
