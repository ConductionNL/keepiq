<template>
	<CnSettingsSection
		:name="t('keepiq', 'Certificate Authority')"
		:description="t('keepiq', 'Status of the private Certificate Authority')">
		<div class="ca-health">
			<div class="ca-health__status">
				<span
					class="ca-health__indicator"
					:class="`ca-health__indicator--${statusClass}`" />
				<strong>{{ statusLabel }}</strong>
			</div>

			<template v-if="caStatus && caStatus.root">
				<p>
					{{ t('keepiq', 'Root expires') }}: {{ caStatus.root.expiresAt }}
				</p>
				<p>
					{{ t('keepiq', 'Intermediate expires') }}:
					{{ caStatus.intermediate?.expiresAt }}
				</p>
			</template>

			<ul
				v-if="caStatus?.issued"
				class="ca-health__issued"
				data-testid="ca-issued-counts">
				<li>
					{{
						t('keepiq', 'Active user vault certificates: {n}', {
							n: caStatus.issued.activeUserSuites,
						})
					}}
				</li>
				<li>
					{{
						t('keepiq', 'Active application certificates: {n}', {
							n: caStatus.issued.activeApplicationSuites,
						})
					}}
				</li>
				<li>
					{{
						t('keepiq', 'Stored certificate secrets: {n}', {
							n: caStatus.issued.storedCertificates,
						})
					}}
				</li>
				<li>
					{{
						t(
							'keepiq',
							'Stored certificates expiring within 30 days: {n}',
							{ n: caStatus.issued.storedExpiringSoon },
						)
					}}
				</li>
			</ul>

			<NcButton
				v-if="caStatus?.status === 'not_configured'"
				variant="primary"
				:disabled="loading"
				@click="retryBootstrap">
				{{ t('keepiq', 'Retry bootstrap') }}
			</NcButton>

			<NcButton
				v-if="
					caStatus?.status === 'healthy'
					|| caStatus?.status === 'expiring_soon'
				"
				variant="secondary"
				:disabled="loading"
				@click="forceRenewIntermediate">
				{{ t('keepiq', 'Force renew intermediate') }}
			</NcButton>

			<NcButton
				v-if="caStatus?.root"
				variant="error"
				:disabled="loading"
				data-testid="ca-renew-root"
				@click="renewRootOpen = true">
				{{ t('keepiq', 'Renew root') }}
			</NcButton>

			<p
				v-if="renewRootResult"
				class="ca-health__result"
				data-testid="ca-renew-root-result">
				{{ renewRootResult }}
			</p>
		</div>

		<CaRenewRootConfirmDialog
			:open="renewRootOpen"
			:busy="loading"
			@update:open="renewRootOpen = $event"
			@confirm="renewRoot" />
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton } from '@nextcloud/vue'
import CaRenewRootConfirmDialog from '../../dialogs/CaRenewRootConfirmDialog.vue'

export default {
	name: 'CaHealthSection',
	components: { NcButton, CnSettingsSection, CaRenewRootConfirmDialog },

	data() {
		return {
			caStatus: null,
			loading: false,
			renewRootOpen: false,
			renewRootResult: '',
		}
	},

	computed: {
		/**
		 * Map CA status to a colour class for the health indicator.
		 *
		 * @return {string} Colour token.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		statusClass() {
			const map = {
				healthy: 'green',
				expiring_soon: 'yellow',
				action_required: 'red',
				not_configured: 'grey',
			}
			return map[this.caStatus?.status] || 'grey'
		},

		/**
		 * Map CA status to a translated, human-readable label.
		 *
		 * @return {string} Status label.
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		statusLabel() {
			const map = {
				healthy: t('keepiq', 'Healthy'),
				expiring_soon: t('keepiq', 'Expiring soon'),
				action_required: t('keepiq', 'Action required'),
				not_configured: t('keepiq', 'Not configured'),
			}
			return map[this.caStatus?.status] || t('keepiq', 'Unknown')
		},
	},

	async created() {
		await this.fetchStatus()
	},

	methods: {
		/**
		 * Fetch current CA health from the API.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async fetchStatus() {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/ca/status'),
			)
			this.caStatus = response.data
		},

		/**
		 * Admin action: retry a failed CA bootstrap, then refresh status.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async retryBootstrap() {
			this.loading = true
			await axios.post(generateUrl('/apps/keepiq/api/v1/ca/bootstrap-retry'))
			await this.fetchStatus()
			this.loading = false
		},

		/**
		 * Admin action: force-renew the intermediate certificate, then refresh status.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async forceRenewIntermediate() {
			this.loading = true
			await axios.post(
				generateUrl('/apps/keepiq/api/v1/ca/renew-intermediate'),
			)
			await this.fetchStatus()
			this.loading = false
		},

		/**
		 * Admin action: renew the root certificate after the admin confirmed it
		 * in the dialog. The server re-signs every active suite and answers
		 * with the count, which is shown back so the admin sees the reach.
		 *
		 * @spec openspec/specs/encryption-suites/spec.md#requirement-ca-certificate-renewal
		 */
		async renewRoot() {
			this.loading = true
			this.renewRootResult = ''
			try {
				const response = await axios.post(
					generateUrl('/apps/keepiq/api/v1/ca/renew-root'),
				)
				this.renewRootResult = this.t(
					'keepiq',
					'Root renewed. {n} encryption suites signed again.',
					{ n: response.data?.resignedCount ?? 0 },
				)
				this.renewRootOpen = false
				await this.fetchStatus()
			} catch {
				this.renewRootResult = this.t(
					'keepiq',
					'Could not renew the root certificate.',
				)
				this.renewRootOpen = false
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.ca-health__status {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	margin-bottom: 0.5rem;
}

.ca-health__indicator {
	width: 12px;
	height: 12px;
	border-radius: 50%;
	display: inline-block;
}

.ca-health__indicator--green {
	background: var(--color-success-text);
}

.ca-health__indicator--yellow {
	background: var(--color-warning-text);
}

.ca-health__indicator--red {
	background: var(--color-error-text);
}

.ca-health__result {
	margin-top: 0.5rem;
}

.ca-health__indicator--grey {
	background: var(--color-text-lighter);
}
</style>
