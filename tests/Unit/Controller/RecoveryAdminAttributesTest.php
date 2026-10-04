<?php

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\RecoveryAdminController;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PasswordConfirmationRequired;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * The account recovery settings are admin only, and every change needs a
 * fresh password confirmation (crypto-organisation-account-recovery 1.2).
 *
 * @spec openspec/specs/organisation-account-recovery/spec.md#requirement-administrators-name-recovery-officers-a-threshold-and-a-policy
 */
class RecoveryAdminAttributesTest extends TestCase {

	public function testEveryRouteIsAdminOnlyAndChangesNeedAPasswordConfirmation(): void {
		foreach (['show' => false, 'update' => true, 'retireKey' => true, 'enrolled' => false] as $method => $changes) {
			$reflection = new ReflectionMethod(RecoveryAdminController::class, $method);
			$this->assertCount(1, $reflection->getAttributes(AuthorizedAdminSetting::class), $method);
			$this->assertCount(0, $reflection->getAttributes(NoAdminRequired::class), $method);
			$this->assertCount(($changes === true) ? 1 : 0, $reflection->getAttributes(PasswordConfirmationRequired::class), $method);
		}
	}
}
