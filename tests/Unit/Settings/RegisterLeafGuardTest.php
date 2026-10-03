<?php

/**
 * Guard: no Keepiq register schema declares an integration leaf.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Settings
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

namespace OCA\Keepiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * Vault data never reaches an OpenRegister integration leaf: the register
 * and every register.d fragment carry no `linkedTypes` and no
 * `mailObjectTemplate`, anywhere. A fleet codemod that adds one fails here.
 *
 * @spec openspec/specs/integration-boundary/spec.md#requirement-no-integration-leaf-is-declared
 */
class RegisterLeafGuardTest extends TestCase {

	/**
	 * The configuration keys that turn an integration leaf on.
	 *
	 * @var list<string>
	 */
	private const LEAF_KEYS = ['linkedTypes', 'mailObjectTemplate'];

	/**
	 * Where the boundary and its exception gate are written down.
	 *
	 * @var string
	 */
	private const BOUNDARY = 'openspec/specs/integration-boundary/spec.md (requirement "No Integration Leaf Is Declared"; '
		. 'a leaf needs the "Future Leaf Adoption Must Pass The Exception Gate" change first)';

	/**
	 * Every path in a decoded register document where a leaf key appears.
	 *
	 * @param mixed $node The decoded JSON
	 * @param string $path The path so far
	 *
	 * @return list<string>
	 */
	public static function leafPaths(mixed $node, string $path = ''): array {
		if (is_array($node) === false) {
			return [];
		}

		$found = [];
		foreach ($node as $key => $child) {
			$here = $path . '/' . $key;
			if (in_array((string)$key, self::LEAF_KEYS, true) === true) {
				$found[] = $here;
			}

			$found = array_merge($found, self::leafPaths($child, $here));
		}

		return $found;
	}//end leafPaths()

	/**
	 * The register and every fragment, decoded, by file name.
	 *
	 * @return array<string,mixed>
	 */
	private static function registerDocuments(): array {
		$root = __DIR__ . '/../../../lib/Settings/';
		$files = array_merge([$root . 'keepiq_register.json'], (glob($root . 'register.d/*.json') ?: []));
		$documents = [];
		foreach ($files as $file) {
			$documents[basename($file)] = json_decode((string)file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
		}

		return $documents;
	}//end registerDocuments()

	/**
	 * 1.1: the shipped register carries no leaf configuration.
	 *
	 * @return void
	 */
	public function testRegisterDeclaresNoLeaf(): void {
		$documents = self::registerDocuments();
		$this->assertArrayHasKey('keepiq_register.json', $documents);
		foreach ($documents as $file => $document) {
			$this->assertSame([], self::leafPaths($document), $file . ' declares an integration leaf. See ' . self::BOUNDARY);
		}
	}//end testRegisterDeclaresNoLeaf()

	/**
	 * 1.2: the guard sees a leaf when one is there (positive control): the
	 * shipped register with `linkedTypes` added to a schema configuration,
	 * and a fragment with `mailObjectTemplate`.
	 *
	 * @return void
	 */
	public function testGuardReportsAPlantedLeaf(): void {
		$document = self::registerDocuments()['keepiq_register.json'];
		$document['components']['schemas']['example'] = [
			'type' => 'object',
			'configuration' => ['linkedTypes' => ['calendar']],
		];
		$this->assertSame(['/components/schemas/example/configuration/linkedTypes'], self::leafPaths($document));

		$fragment = ['components' => ['schemas' => ['certificate' => ['configuration' => ['mailObjectTemplate' => 'x']]]]];
		$this->assertSame(['/components/schemas/certificate/configuration/mailObjectTemplate'], self::leafPaths($fragment));
	}//end testGuardReportsAPlantedLeaf()
}//end class
