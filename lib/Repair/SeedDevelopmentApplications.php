<?php

/**
 * Keepiq Seed Development Applications Repair Step
 *
 * Creates example Application rows for the development vault so the admin
 * queue and JWT-Bearer flows have realistic dev fixtures. Debug-only,
 * idempotent. CSR validation, EncryptionSuite generation and full secret
 * attribution land via the dedicated implement-application-mgmt build cycle.
 *
 * @category Repair
 * @package  OCA\Keepiq\Repair
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

namespace OCA\Keepiq\Repair;

use DateTime;
use OCA\Keepiq\Db\Application;
use OCA\Keepiq\Db\ApplicationMapper;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Seed Application rows for the development vault.
 *
 * @spec openspec/changes/implement-application-mgmt/tasks.md#task-1.2
 */
class SeedDevelopmentApplications implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param ApplicationMapper $appMapper The application mapper
	 * @param IConfig $config The system config
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private ApplicationMapper $appMapper,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Repair step display name.
	 *
	 * @return string
	 */
	public function getName(): string {
		return 'Seed Keepiq development applications (debug only)';
	}//end getName()

	/**
	 * Run the repair step.
	 *
	 * @param IOutput $output The output channel
	 *
	 * @return void
	 *
	 * @spec exclude Debug-only fixture data, gated on the `debug` system value
	 *       and never present on a production instance; it asserts no product
	 *       behaviour of its own. Real application creation and the approval
	 *       states it fakes are specified in
	 *       openspec/specs/application-mgmt/spec.md#requirement-register-application
	 *       and
	 *       openspec/specs/application-mgmt/spec.md#requirement-approval-queue.
	 */
	public function run(IOutput $output): void {
		if ($this->config->getSystemValueBool('debug', false) === false) {
			return;
		}

		$apps = [
			[
				'name' => 'OpenConnector Dev',
				'description' => 'Internal connector dev integration',
				'type' => Application::TYPE_INTERNAL,
				'status' => Application::STATUS_ACTIVE,
			],
			[
				'name' => 'CI Pipeline Bot',
				'description' => 'External CI bot — pending admin approval',
				'type' => Application::TYPE_EXTERNAL,
				'status' => Application::STATUS_PENDING,
			],
			[
				'name' => 'Monitoring Agent',
				'description' => 'External monitoring agent for endpoint health',
				'type' => Application::TYPE_EXTERNAL,
				'status' => Application::STATUS_ACTIVE,
			],
		];

		$seeded = 0;
		foreach ($apps as $spec) {
			$id = $this->deterministicId(name: $spec['name']);

			// Idempotency: skip if the deterministic ID is already present.
			try {
				$this->appMapper->findById($id);
				continue;
			} catch (DoesNotExistException) {
				// Not yet present — seed it.
			}

			$application = new Application();
			$application->setId($id);
			$application->setName($spec['name']);
			$application->setDescription($spec['description']);
			$application->setType($spec['type']);
			$application->setStatus($spec['status']);
			$application->setRegisteredBy('admin');
			if ($spec['status'] === Application::STATUS_ACTIVE) {
				$application->setApprovedBy('admin');
				$application->setApprovedAt(new DateTime());
			}

			$application->setCreatedAt(new DateTime());
			$this->appMapper->insert($application);
			$seeded++;
		}//end foreach

		$output->info('Keepiq: seeded ' . $seeded . ' development applications');
		$this->logger->info('Keepiq dev seed: created ' . $seeded . ' applications');
	}//end run()

	/**
	 * Produce a deterministic UUIDv5 from an application name.
	 *
	 * @param string $name The application name
	 *
	 * @return string
	 */
	private function deterministicId(string $name): string {
		return Uuid::uuid5(Uuid::NAMESPACE_OID, 'keepiq:application:' . $name)->toString();
	}//end deterministicId()
}//end class
