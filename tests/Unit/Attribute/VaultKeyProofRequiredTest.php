<?php

/**
 * Unit tests for the VaultKeyProofRequired attribute: what a declaration
 * carries when it is read off a controller method, its defaults, and where
 * PHP allows it to be declared.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Attribute
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

namespace OCA\Keepiq\Tests\Unit\Attribute;

use Error;
use OCA\Keepiq\Attribute\VaultKeyProofRequired;
use OCA\Keepiq\Service\KnownShareRecipientExemption;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pins the contract VaultKeyProofMiddleware relies on when it reads the
 * attribute: bind order, subject, purpose and exemption survive reflection,
 * the defaults bind to the challenge alone on the active suite, and the
 * attribute is accepted on methods only, once per method.
 *
 * @covers \OCA\Keepiq\Attribute\VaultKeyProofRequired
 */
class VaultKeyProofRequiredTest extends TestCase {

	/**
	 * A bare declaration binds nothing, verifies against the active suite and
	 * has neither a purpose nor an exemption.
	 *
	 * @return void
	 */
	public function testBareDeclarationUsesTheDefaults(): void {
		$attribute = $this->attributeOf(method: 'bare');

		$this->assertSame(expected: [], actual: $attribute->getBinds());
		$this->assertSame(expected: 'active', actual: $attribute->getSubject());
		$this->assertSame(expected: '', actual: $attribute->getPurpose());
		$this->assertSame(expected: '', actual: $attribute->getExemption());
	}//end testBareDeclarationUsesTheDefaults()

	/**
	 * Every argument of a full declaration reaches the instance unchanged, and
	 * the binds keep the order in which they are hashed into the payload.
	 *
	 * @return void
	 */
	public function testFullDeclarationKeepsEveryArgumentAndTheBindOrder(): void {
		$attribute = $this->attributeOf(method: 'full');

		$this->assertSame(expected: ['secretId', 'recipient', 'id'], actual: $attribute->getBinds());
		$this->assertSame(expected: 'routeParam:suiteId', actual: $attribute->getSubject());
		$this->assertSame(expected: 'share.new-recipient', actual: $attribute->getPurpose());
		$this->assertSame(expected: KnownShareRecipientExemption::class, actual: $attribute->getExemption());
	}//end testFullDeclarationKeepsEveryArgumentAndTheBindOrder()

	/**
	 * Named arguments may be given in any order and the omitted ones keep
	 * their defaults.
	 *
	 * @return void
	 */
	public function testPartialNamedDeclarationKeepsTheOtherDefaults(): void {
		$attribute = $this->attributeOf(method: 'partial');

		$this->assertSame(expected: [], actual: $attribute->getBinds());
		$this->assertSame(expected: 'migrationNewSuite', actual: $attribute->getSubject());
		$this->assertSame(expected: 'suite.migrate', actual: $attribute->getPurpose());
		$this->assertSame(expected: '', actual: $attribute->getExemption());
	}//end testPartialNamedDeclarationKeepsTheOtherDefaults()

	/**
	 * Declared on a class, the attribute cannot be instantiated: it guards a
	 * single operation, never a whole controller.
	 *
	 * @return void
	 */
	public function testDeclarationOnAClassIsRefused(): void {
		$attributes = (new ReflectionClass(objectOrClass: VaultKeyProofRequiredOnClassFixture::class))
			->getAttributes(name: VaultKeyProofRequired::class);
		$this->assertCount(expectedCount: 1, haystack: $attributes);

		$this->expectException(exception: Error::class);
		$this->expectExceptionMessageMatches(regularExpression: '/cannot target class/');
		$attributes[0]->newInstance();
	}//end testDeclarationOnAClassIsRefused()

	/**
	 * Two declarations on one method cannot be instantiated, so a method can
	 * never carry two different bindings.
	 *
	 * @return void
	 */
	public function testRepeatedDeclarationIsRefused(): void {
		$attributes = (new ReflectionMethod(objectOrMethod: VaultKeyProofRequiredFixture::class, method: 'repeated'))
			->getAttributes(name: VaultKeyProofRequired::class);
		$this->assertCount(expectedCount: 2, haystack: $attributes);

		$this->expectException(exception: Error::class);
		$this->expectExceptionMessageMatches(regularExpression: '/must not be repeated/');
		$attributes[0]->newInstance();
	}//end testRepeatedDeclarationIsRefused()

	/**
	 * The single attribute declared on a fixture method, instantiated.
	 *
	 * @param string $method The fixture method name.
	 *
	 * @return VaultKeyProofRequired The attribute instance.
	 */
	private function attributeOf(string $method): VaultKeyProofRequired {
		$attributes = (new ReflectionMethod(objectOrMethod: VaultKeyProofRequiredFixture::class, method: $method))
			->getAttributes(name: VaultKeyProofRequired::class);
		$this->assertCount(expectedCount: 1, haystack: $attributes);

		return $attributes[0]->newInstance();
	}//end attributeOf()
}//end class

/**
 * Methods declaring the attribute the ways controllers do.
 */
class VaultKeyProofRequiredFixture {

	/**
	 * A bare declaration.
	 *
	 * @return void
	 */
	#[VaultKeyProofRequired]
	public function bare(): void {
	}//end bare()

	/**
	 * A declaration with every argument.
	 *
	 * @return void
	 */
	#[VaultKeyProofRequired(
		binds: ['secretId', 'recipient', 'id'],
		subject: 'routeParam:suiteId',
		purpose: 'share.new-recipient',
		exemption: KnownShareRecipientExemption::class
	)]
	public function full(): void {
	}//end full()

	/**
	 * A declaration with some named arguments, out of order.
	 *
	 * @return void
	 */
	#[VaultKeyProofRequired(purpose: 'suite.migrate', subject: 'migrationNewSuite')]
	public function partial(): void {
	}//end partial()

	/**
	 * Two declarations on one method.
	 *
	 * @return void
	 */
	#[VaultKeyProofRequired(binds: ['id'])]
	#[VaultKeyProofRequired(binds: ['secretId'])]
	public function repeated(): void {
	}//end repeated()
}//end class

/**
 * A class that wrongly declares the attribute on itself.
 */
#[VaultKeyProofRequired]
class VaultKeyProofRequiredOnClassFixture {
}//end class
