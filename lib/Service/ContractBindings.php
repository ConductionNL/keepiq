<?php

/**
 * Bindings of the Kernel contracts to their Service implementations
 *
 * @category  Service
 * @package   OCA\Keepiq\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/keepiq
 *
 * @spec openspec/specs/app-shell/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Keepiq\Service;

use OCA\Keepiq\Kernel\Contracts\AccountDeletionServiceInterface;
use OCA\Keepiq\Kernel\Contracts\AdminAreaAuthorizerInterface;
use OCA\Keepiq\Kernel\Contracts\AdminSettingsServiceInterface;
use OCA\Keepiq\Kernel\Contracts\AdminSuiteRevocationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ApplicationRequestAdminServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ApplicationSecretRequestServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ApplicationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\AttachmentServiceInterface;
use OCA\Keepiq\Kernel\Contracts\AudiencePolicyInterface;
use OCA\Keepiq\Kernel\Contracts\AuditServiceInterface;
use OCA\Keepiq\Kernel\Contracts\CertificateAuthorityServiceInterface;
use OCA\Keepiq\Kernel\Contracts\CertificateLifecycleServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ComplianceReportServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ConnectionReporterInterface;
use OCA\Keepiq\Kernel\Contracts\DashboardServiceInterface;
use OCA\Keepiq\Kernel\Contracts\DashboardSummaryServiceInterface;
use OCA\Keepiq\Kernel\Contracts\DecryptServiceInterface;
use OCA\Keepiq\Kernel\Contracts\DelegationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\DeviceApprovalServiceInterface;
use OCA\Keepiq\Kernel\Contracts\EmergencyAccessServiceInterface;
use OCA\Keepiq\Kernel\Contracts\EmergencyEnvelopeInvalidationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\EncryptionSuiteServiceInterface;
use OCA\Keepiq\Kernel\Contracts\EncryptServiceInterface;
use OCA\Keepiq\Kernel\Contracts\EphemeralSendServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ExtensionChallengeStoreInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedCertificateServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedCopyDeclineServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedDeclineReceiverInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedInboundServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedNotificationDeliveryInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedRemoteChangeServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedShareReceiverInterface;
use OCA\Keepiq\Kernel\Contracts\FederatedShareServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederationPartnerServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FederationRootServiceInterface;
use OCA\Keepiq\Kernel\Contracts\FolderServiceInterface;
use OCA\Keepiq\Kernel\Contracts\GdprServiceInterface;
use OCA\Keepiq\Kernel\Contracts\GroupShareServiceInterface;
use OCA\Keepiq\Kernel\Contracts\HoneyCredentialServiceInterface;
use OCA\Keepiq\Kernel\Contracts\HoneyTripwireServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ImportServiceInterface;
use OCA\Keepiq\Kernel\Contracts\JwtAuthServiceInterface;
use OCA\Keepiq\Kernel\Contracts\KeyGeneratorServiceInterface;
use OCA\Keepiq\Kernel\Contracts\LeaseServiceInterface;
use OCA\Keepiq\Kernel\Contracts\LinkShareServiceInterface;
use OCA\Keepiq\Kernel\Contracts\MachineSecretEnvelopeServiceInterface;
use OCA\Keepiq\Kernel\Contracts\MachineSecretResponseServiceInterface;
use OCA\Keepiq\Kernel\Contracts\MemberOverviewServiceInterface;
use OCA\Keepiq\Kernel\Contracts\MigrationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\MigrationWorkServiceInterface;
use OCA\Keepiq\Kernel\Contracts\NotificationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\OfflineManifestServiceInterface;
use OCA\Keepiq\Kernel\Contracts\OrgOwnershipGuardInterface;
use OCA\Keepiq\Kernel\Contracts\PasskeyServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecentlyUsedServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecipientStatusServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryAuditInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryEnrolmentServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryKeyServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryPolicyServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RecoveryRequestServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RotationFlagServiceInterface;
use OCA\Keepiq\Kernel\Contracts\RotationPolicyServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretOrganisationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretRequestServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretRequestSuiteLockServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretTrashServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretTypeServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SecretVersionServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ShareExpiryServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ShareQuarantineScannerInterface;
use OCA\Keepiq\Kernel\Contracts\ShareQuarantineServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ShareRequestServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ShareRestrictionInterface;
use OCA\Keepiq\Kernel\Contracts\ShareRestrictionResolverInterface;
use OCA\Keepiq\Kernel\Contracts\ShareRowJudgeInterface;
use OCA\Keepiq\Kernel\Contracts\ShareRowVerdictInterface;
use OCA\Keepiq\Kernel\Contracts\ShareSaveServiceInterface;
use OCA\Keepiq\Kernel\Contracts\ShareSaveStagesInterface;
use OCA\Keepiq\Kernel\Contracts\ShareServiceInterface;
use OCA\Keepiq\Kernel\Contracts\SiemServiceInterface;
use OCA\Keepiq\Kernel\Contracts\TeamFolderConfirmationServiceInterface;
use OCA\Keepiq\Kernel\Contracts\TeamFolderContributionServiceInterface;
use OCA\Keepiq\Kernel\Contracts\TeamFolderServiceInterface;
use OCA\Keepiq\Kernel\Contracts\TwoFactorGateInterface;
use OCA\Keepiq\Kernel\Contracts\UseOnlyUseRecorderInterface;
use OCA\Keepiq\Kernel\Contracts\VaultKeyProofServiceInterface;
use OCA\Keepiq\Kernel\Contracts\VaultPolicyServiceInterface;
use OCA\Keepiq\Service\Connection\ConnectionReporter;
use OCA\Keepiq\Service\ShareSave\ShareSaveStages;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Nextcloud autowires concrete classes but not interfaces, so every contract
 * in OCA\Keepiq\Kernel\Contracts that a caller type-hints needs an alias to
 * the class that implements it. An alias resolves to the same shared instance
 * as the implementation itself.
 *
 * @category Service
 * @package  OCA\Keepiq\Service
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/keepiq
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The binding table names every contract and its implementation.
 * @SuppressWarnings(PHPMD.ExcessiveMethodLength) One alias line per contract, nothing else.
 *
 * @spec openspec/specs/app-shell/spec.md
 */
final class ContractBindings {

	/**
	 * Alias every contract to its implementation.
	 *
	 * @param IRegistrationContext $context Registration context
	 *
	 * @return void
	 *
	 * @spec openspec/specs/app-shell/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerServiceAlias(AccountDeletionServiceInterface::class, AccountDeletionService::class);
		$context->registerServiceAlias(AdminAreaAuthorizerInterface::class, AdminAreaAuthorizer::class);
		$context->registerServiceAlias(AdminSettingsServiceInterface::class, AdminSettingsService::class);
		$context->registerServiceAlias(AdminSuiteRevocationServiceInterface::class, AdminSuiteRevocationService::class);
		$context->registerServiceAlias(ApplicationRequestAdminServiceInterface::class, ApplicationRequestAdminService::class);
		$context->registerServiceAlias(ApplicationSecretRequestServiceInterface::class, ApplicationSecretRequestService::class);
		$context->registerServiceAlias(ApplicationServiceInterface::class, ApplicationService::class);
		$context->registerServiceAlias(AttachmentServiceInterface::class, AttachmentService::class);
		$context->registerServiceAlias(AudiencePolicyInterface::class, AudiencePolicy::class);
		$context->registerServiceAlias(AuditServiceInterface::class, AuditService::class);
		$context->registerServiceAlias(CertificateAuthorityServiceInterface::class, CertificateAuthorityService::class);
		$context->registerServiceAlias(CertificateLifecycleServiceInterface::class, CertificateLifecycleService::class);
		$context->registerServiceAlias(ComplianceReportServiceInterface::class, ComplianceReportService::class);
		$context->registerServiceAlias(ConnectionReporterInterface::class, ConnectionReporter::class);
		$context->registerServiceAlias(DashboardServiceInterface::class, DashboardService::class);
		$context->registerServiceAlias(DashboardSummaryServiceInterface::class, DashboardSummaryService::class);
		$context->registerServiceAlias(DecryptServiceInterface::class, DecryptService::class);
		$context->registerServiceAlias(DelegationServiceInterface::class, DelegationService::class);
		$context->registerServiceAlias(DeviceApprovalServiceInterface::class, DeviceApprovalService::class);
		$context->registerServiceAlias(EmergencyAccessServiceInterface::class, EmergencyAccessService::class);
		$context->registerServiceAlias(EmergencyEnvelopeInvalidationServiceInterface::class, EmergencyEnvelopeInvalidationService::class);
		$context->registerServiceAlias(EncryptServiceInterface::class, EncryptService::class);
		$context->registerServiceAlias(EncryptionSuiteServiceInterface::class, EncryptionSuiteService::class);
		$context->registerServiceAlias(EphemeralSendServiceInterface::class, EphemeralSendService::class);
		$context->registerServiceAlias(ExtensionChallengeStoreInterface::class, ExtensionChallengeStore::class);
		$context->registerServiceAlias(FederatedCertificateServiceInterface::class, FederatedCertificateService::class);
		$context->registerServiceAlias(FederatedCopyDeclineServiceInterface::class, FederatedCopyDeclineService::class);
		$context->registerServiceAlias(FederatedDeclineReceiverInterface::class, FederatedDeclineReceiver::class);
		$context->registerServiceAlias(FederatedInboundServiceInterface::class, FederatedInboundService::class);
		$context->registerServiceAlias(FederatedNotificationDeliveryInterface::class, FederatedNotificationDelivery::class);
		$context->registerServiceAlias(FederatedRemoteChangeServiceInterface::class, FederatedRemoteChangeService::class);
		$context->registerServiceAlias(FederatedShareReceiverInterface::class, FederatedShareReceiver::class);
		$context->registerServiceAlias(FederatedShareServiceInterface::class, FederatedShareService::class);
		$context->registerServiceAlias(FederationPartnerServiceInterface::class, FederationPartnerService::class);
		$context->registerServiceAlias(FederationRootServiceInterface::class, FederationRootService::class);
		$context->registerServiceAlias(FolderServiceInterface::class, FolderService::class);
		$context->registerServiceAlias(GdprServiceInterface::class, GdprService::class);
		$context->registerServiceAlias(GroupShareServiceInterface::class, GroupShareService::class);
		$context->registerServiceAlias(HoneyCredentialServiceInterface::class, HoneyCredentialService::class);
		$context->registerServiceAlias(HoneyTripwireServiceInterface::class, HoneyTripwireService::class);
		$context->registerServiceAlias(ImportServiceInterface::class, ImportService::class);
		$context->registerServiceAlias(JwtAuthServiceInterface::class, JwtAuthService::class);
		$context->registerServiceAlias(KeyGeneratorServiceInterface::class, KeyGeneratorService::class);
		$context->registerServiceAlias(LeaseServiceInterface::class, LeaseService::class);
		$context->registerServiceAlias(LinkShareServiceInterface::class, LinkShareService::class);
		$context->registerServiceAlias(MachineSecretEnvelopeServiceInterface::class, MachineSecretEnvelopeService::class);
		$context->registerServiceAlias(MachineSecretResponseServiceInterface::class, MachineSecretResponseService::class);
		$context->registerServiceAlias(MemberOverviewServiceInterface::class, MemberOverviewService::class);
		$context->registerServiceAlias(MigrationServiceInterface::class, MigrationService::class);
		$context->registerServiceAlias(MigrationWorkServiceInterface::class, MigrationWorkService::class);
		$context->registerServiceAlias(NotificationServiceInterface::class, NotificationService::class);
		$context->registerServiceAlias(OfflineManifestServiceInterface::class, OfflineManifestService::class);
		$context->registerServiceAlias(OrgOwnershipGuardInterface::class, OrgOwnershipGuard::class);
		$context->registerServiceAlias(PasskeyServiceInterface::class, PasskeyService::class);
		$context->registerServiceAlias(RecentlyUsedServiceInterface::class, RecentlyUsedService::class);
		$context->registerServiceAlias(RecipientStatusServiceInterface::class, RecipientStatusService::class);
		$context->registerServiceAlias(RecoveryAuditInterface::class, RecoveryAudit::class);
		$context->registerServiceAlias(RecoveryEnrolmentServiceInterface::class, RecoveryEnrolmentService::class);
		$context->registerServiceAlias(RecoveryKeyServiceInterface::class, RecoveryKeyService::class);
		$context->registerServiceAlias(RecoveryPolicyServiceInterface::class, RecoveryPolicyService::class);
		$context->registerServiceAlias(RecoveryRequestServiceInterface::class, RecoveryRequestService::class);
		$context->registerServiceAlias(RotationFlagServiceInterface::class, RotationFlagService::class);
		$context->registerServiceAlias(RotationPolicyServiceInterface::class, RotationPolicyService::class);
		$context->registerServiceAlias(SecretOrganisationServiceInterface::class, SecretOrganisationService::class);
		$context->registerServiceAlias(SecretRequestServiceInterface::class, SecretRequestService::class);
		$context->registerServiceAlias(SecretRequestSuiteLockServiceInterface::class, SecretRequestSuiteLockService::class);
		$context->registerServiceAlias(SecretServiceInterface::class, SecretService::class);
		$context->registerServiceAlias(SecretTrashServiceInterface::class, SecretTrashService::class);
		$context->registerServiceAlias(SecretTypeServiceInterface::class, SecretTypeService::class);
		$context->registerServiceAlias(SecretVersionServiceInterface::class, SecretVersionService::class);
		$context->registerServiceAlias(ShareExpiryServiceInterface::class, ShareExpiryService::class);
		$context->registerServiceAlias(ShareQuarantineScannerInterface::class, ShareQuarantineScanner::class);
		$context->registerServiceAlias(ShareQuarantineServiceInterface::class, ShareQuarantineService::class);
		$context->registerServiceAlias(ShareRequestServiceInterface::class, ShareRequestService::class);
		$context->registerServiceAlias(ShareRestrictionInterface::class, ShareRestriction::class);
		$context->registerServiceAlias(ShareRestrictionResolverInterface::class, ShareRestrictionResolver::class);
		$context->registerServiceAlias(ShareRowJudgeInterface::class, ShareRowJudge::class);
		$context->registerServiceAlias(ShareRowVerdictInterface::class, ShareRowVerdict::class);
		$context->registerServiceAlias(ShareSaveServiceInterface::class, ShareSaveService::class);
		$context->registerServiceAlias(ShareSaveStagesInterface::class, ShareSaveStages::class);
		$context->registerServiceAlias(ShareServiceInterface::class, ShareService::class);
		$context->registerServiceAlias(SiemServiceInterface::class, SiemService::class);
		$context->registerServiceAlias(TeamFolderConfirmationServiceInterface::class, TeamFolderConfirmationService::class);
		$context->registerServiceAlias(TeamFolderContributionServiceInterface::class, TeamFolderContributionService::class);
		$context->registerServiceAlias(TeamFolderServiceInterface::class, TeamFolderService::class);
		$context->registerServiceAlias(TwoFactorGateInterface::class, TwoFactorGate::class);
		$context->registerServiceAlias(UseOnlyUseRecorderInterface::class, UseOnlyUseRecorder::class);
		$context->registerServiceAlias(VaultKeyProofServiceInterface::class, VaultKeyProofService::class);
		$context->registerServiceAlias(VaultPolicyServiceInterface::class, VaultPolicyService::class);
	}//end register()
}//end class
