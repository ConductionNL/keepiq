<?php

/**
 * Decides which `aud` claim values name this instance.
 *
 * Split out of JwtAssertionVerifier because it is policy, not verification
 * mechanics: the verifier's job is to deserialize an assertion and check its
 * claims are well-formed and in-date, while WHICH audience strings this
 * deployment answers to — and for how much longer — is a contract decision
 * that also has to be published in the discovery document. Keeping the two
 * apart means the verifier no longer reaches into another service's constants
 * to make it.
 *
 * @category  Service
 * @package   OCA\Keepiq\Service
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

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Kernel\AppIdentity;
use OCA\Keepiq\Kernel\Contracts\AudiencePolicyInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The set of audience values this instance accepts, and their deprecation.
 */
class AudiencePolicy implements AudiencePolicyInterface {

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger Reports use of the deprecated value.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Assert the claim set names this instance, and flag the deprecated name.
	 *
	 * RFC 7519 section 4.1.3 makes `aud` either a single string or an array of
	 * them, and requires only that the recipient identify itself with ONE of
	 * the values. Both accepted values name this app, so honouring the pair is
	 * the claim's own semantics rather than a relaxation of it.
	 *
	 * REPORTING IS NOT DONE HERE. This runs before replay detection, issuer
	 * lookup and signature verification, so at this point the assertion is
	 * merely well-addressed, not authentic — and its `iss` is a string an
	 * unauthenticated caller chose. Warning here would let anyone forge
	 * migration traffic for any issuer they name, and the resulting log could
	 * not be used to decide when the deprecated value is safe to remove, which
	 * is the only reason the log exists. The caller reports separately, after
	 * authentication succeeds.
	 *
	 * @param array<string,mixed> $claims The decoded claim set.
	 *
	 * @return bool True when ONLY the deprecated value named this instance.
	 *
	 * @throws RuntimeException When no presented value names this instance.
	 *
	 * @spec openspec/specs/secret-store-api/spec.md#requirement-assertion-audience
	 */
	public function assertNamesThisInstance(array $claims): bool {
		$presented = $this->presentedValues(claim: ($claims['aud'] ?? null));
		$matched = array_values(array_intersect($presented, self::ACCEPTED_AUDIENCES));

		if ($matched === []) {
			throw new RuntimeException(message: 'Wrong audience');
		}

		return (in_array(self::CANONICAL_AUDIENCE, $matched, true) === false);
	}//end assertNamesThisInstance()

	/**
	 * Report that an AUTHENTICATED assertion used the deprecated audience.
	 *
	 * Called only once the signature, issuer and replay checks have passed, so
	 * the issuer named here is one this instance verified rather than one the
	 * caller asserted. That is what makes the resulting set of issuers usable
	 * as the migration checklist it is meant to be.
	 *
	 * @param array<string,mixed> $claims The decoded claim set.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/secret-store-api/spec.md#requirement-assertion-audience
	 */
	public function reportDeprecatedUse(array $claims): void {
		$this->logger->warning(
			'Assertion accepted on the deprecated audience "{deprecated}", which is '
			. 'removed in app version {version}. Update issuer "{iss}" to send "{canonical}".',
			[
				'deprecated' => self::DEPRECATED_AUDIENCE,
				'canonical' => self::CANONICAL_AUDIENCE,
				'version' => self::DEPRECATED_AUDIENCE_REMOVED_IN,
				'iss' => (string)($claims['iss'] ?? 'unknown'),
			]
		);
	}//end reportDeprecatedUse()

	/**
	 * Read the `aud` claim as the list of strings it presents.
	 *
	 * RFC 7519 section 4.1.3 defines `aud` as a StringOrURI or an array of
	 * them, so anything else is a malformed assertion and presents no audience
	 * at all — which the caller turns into a rejection.
	 *
	 * NOTHING IS COERCED. `aud: 123` and `aud: true` are not audiences that
	 * happen to be written oddly; they are malformed, and casting them to
	 * "123" and "1" would launder a type error into a value that then gets
	 * compared against the accepted set. Nothing in that set is numeric today,
	 * so the coercion changed no verdict — but "no accepted audience is
	 * currently a number" is a fact about configuration, not a property of the
	 * check, and it is not one an auth path should lean on.
	 *
	 * A MIXED ARRAY IS REJECTED WHOLE, rather than filtered down to its string
	 * members. `["keepiq", {...}]` is not a well-formed claim, and quietly
	 * discarding the part that does not parse would authenticate on the
	 * remainder — the lenient reading is the one that lets a malformed
	 * assertion through.
	 *
	 * @param mixed $claim The raw `aud` claim.
	 *
	 * @return string[] The presented audience values, empty when malformed.
	 *
	 * @spec openspec/specs/secret-store-api/spec.md#requirement-assertion-audience
	 */
	private function presentedValues(mixed $claim): array {
		if (is_array($claim) === true) {
			$values = [];
			foreach ($claim as $value) {
				if (is_string($value) === false || $value === '') {
					// One malformed member makes the whole claim malformed.
					return [];
				}

				$values[] = $value;
			}

			return $values;
		}

		if (is_string($claim) === true && $claim !== '') {
			return [$claim];
		}

		return [];
	}//end presentedValues()
}//end class
