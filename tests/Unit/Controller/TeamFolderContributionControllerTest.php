<?php

/**
 * Contract tests for the team folder contribution endpoints
 * (admin-vault-policies §4.2 to §4.4): status codes, response shapes, and
 * the refusals for an anonymous caller, a read-grade member or non-member,
 * and an unknown team folder.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Controller
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

namespace OCA\Keepiq\Tests\Unit\Controller;

use OCA\Keepiq\Controller\TeamFolderContributionController;
use OCA\Keepiq\Db\Secret;
use OCA\Keepiq\Exception\ForbiddenException;
use OCA\Keepiq\Exception\NotFoundException;
use OCA\Keepiq\Service\OrgOwnershipGuard;
use OCA\Keepiq\Service\TeamFolderContributionService;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Every endpoint is for a logged-in user and scoped to that user.
 */
class TeamFolderContributionControllerTest extends TestCase {

	private TeamFolderContributionService&MockObject $contributions;

	private OrgOwnershipGuard&MockObject $ownership;

	/**
	 * Fresh doubles.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->contributions = $this->createMock(TeamFolderContributionService::class);
		$this->ownership = $this->createMock(OrgOwnershipGuard::class);
	}//end setUp()

	/**
	 * The controller for a session user, or anonymous.
	 *
	 * @param string|null $userId The session user
	 *
	 * @return TeamFolderContributionController
	 */
	private function controller(?string $userId): TeamFolderContributionController {
		$session = $this->createMock(IUserSession::class);
		$user = null;
		if ($userId !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($userId);
		}
		$session->method('getUser')->willReturn($user);

		return new TeamFolderContributionController(
			request: $this->createMock(IRequest::class),
			userSession: $session,
			contributions: $this->contributions,
			ownership: $this->ownership,
		);
	}//end controller()

	/**
	 * Each endpoint needs a logged-in user (no PublicPage) and not an admin.
	 *
	 * @return void
	 */
	public function testEndpointsAreForLoggedInUsers(): void {
		foreach (['contributable', 'ownershipFindings', 'contributionContext', 'contribute'] as $name) {
			$method = new ReflectionMethod(TeamFolderContributionController::class, $name);
			$this->assertCount(1, $method->getAttributes(NoAdminRequired::class), $name);
			$this->assertSame([], $method->getAttributes(PublicPage::class), $name);
		}
	}//end testEndpointsAreForLoggedInUsers()

	/**
	 * GET contribution-context: 200 with the certificate shape for a write
	 * member, 403 for a read member or non-member, 404 for an unknown folder,
	 * 401 for an anonymous caller. Always asked for the SESSION user.
	 *
	 * @return void
	 */
	public function testContributionContextContract(): void {
		$this->contributions->method('context')->willReturnCallback(
			static function (string $teamFolderId, string $userId): array {
				if ($teamFolderId === 'missing') {
					throw new NotFoundException('Team folder not found');
				}
				if ($userId !== 'hank') {
					throw new ForbiddenException('Only a member with write access can add a secret to this team folder');
				}
				return [
					'teamFolderId' => 'tf-ops',
					'folderId' => 'folder-ops',
					'ownerCertificate' => 'CERT-IRIS',
					'recipients' => [['userId' => 'hank', 'certificate' => 'CERT-HANK']],
				];
			}
		);

		$ok = $this->controller('hank')->contributionContext(id: 'tf-ops');
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['teamFolderId', 'folderId', 'ownerCertificate', 'recipients'], array_keys($ok->getData()));
		$this->assertSame(['userId', 'certificate'], array_keys($ok->getData()['recipients'][0]));
		$this->assertArrayNotHasKey('privateKey', $ok->getData());

		$this->assertSame(403, $this->controller('jack')->contributionContext(id: 'tf-ops')->getStatus());
		$this->assertSame(403, $this->controller('lee')->contributionContext(id: 'tf-ops')->getStatus());
		$this->assertSame(404, $this->controller('hank')->contributionContext(id: 'missing')->getStatus());
		$this->assertSame(401, $this->controller(null)->contributionContext(id: 'tf-ops')->getStatus());
	}//end testContributionContextContract()

	/**
	 * GET ownership-findings: 200 with metadata rows for the session user
	 * only, an empty list when the policy does not apply, 401 anonymous.
	 *
	 * @return void
	 */
	public function testOwnershipFindingsContract(): void {
		$this->ownership->method('findings')->willReturnCallback(
			static fn (string $userId): array => ($userId === 'gina'
				? [['id' => 's1', 'name' => 'Bank', 'typeId' => 'type-login', 'folderId' => 'private']]
				: [])
		);

		$ok = $this->controller('gina')->ownershipFindings();
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['id', 'name', 'typeId', 'folderId'], array_keys($ok->getData()[0]));
		$this->assertSame([], $this->controller('olaf')->ownershipFindings()->getData());
		$this->assertSame(401, $this->controller(null)->ownershipFindings()->getStatus());
	}//end testOwnershipFindingsContract()

	/**
	 * GET contributable: 200 with the folder shape, 401 anonymous.
	 *
	 * @return void
	 */
	public function testContributableContract(): void {
		$this->contributions->expects($this->once())->method('contributable')->with('hank')
			->willReturn([['teamFolderId' => 'tf-ops', 'folderId' => 'folder-ops', 'folderName' => 'Ops']]);

		$ok = $this->controller('hank')->contributable();
		$this->assertSame(200, $ok->getStatus());
		$this->assertSame(['teamFolderId', 'folderId', 'folderName'], array_keys($ok->getData()[0]));
		$this->assertSame(401, $this->controller(null)->contributable()->getStatus());
	}//end testContributableContract()

	/**
	 * POST secrets: 201 with the stored row for a write member, 403 for a
	 * read member, 404 for an unknown folder, 400 for a missing value, 401
	 * anonymous.
	 *
	 * @return void
	 */
	public function testContributeContract(): void {
		$this->contributions->method('contribute')->willReturnCallback(
			static function (string $teamFolderId, array $data, string $userId): array {
				if ($teamFolderId === 'missing') {
					throw new NotFoundException('Team folder not found');
				}
				if ($userId === 'jack') {
					throw new ForbiddenException('read grade');
				}
				if ($userId === 'empty') {
					throw new \InvalidArgumentException('A secret requires a name and a key');
				}
				$secret = new Secret();
				$secret->setId('s9');
				$secret->setOwnerId('iris');
				return ['secret' => $secret, 'copies' => 2];
			}
		);

		$ok = $this->controller('hank')->contribute(id: 'tf-ops');
		$this->assertSame(201, $ok->getStatus());
		$this->assertSame('iris', $ok->getData()['secret']['ownerId']);
		$this->assertSame(2, $ok->getData()['copies']);
		$this->assertSame(403, $this->controller('jack')->contribute(id: 'tf-ops')->getStatus());
		$this->assertSame(404, $this->controller('hank')->contribute(id: 'missing')->getStatus());
		$this->assertSame(400, $this->controller('empty')->contribute(id: 'tf-ops')->getStatus());
		$this->assertSame(401, $this->controller(null)->contribute(id: 'tf-ops')->getStatus());
	}//end testContributeContract()
}//end class
