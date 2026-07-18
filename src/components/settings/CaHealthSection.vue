<template>
	<CnSettingsSection
		:name="t('doriath', 'Certificate Authority')"
		:description="t('doriath', 'Status of the private Certificate Authority')">
		<div class="ca-health">
			<div class="ca-health__status">
				<span
					class="ca-health__indicator"
					:class="`ca-health__indicator--${statusClass}`" />
				<strong>{{ statusLabel }}</strong>
			</div>

			<template v-if="caStatus && caStatus.root">
				<p>{{ t('doriath', 'Root expires') }}: {{ caStatus.root.expiresAt }}</p>
				<p>{{ t('doriath', 'Intermediate expires') }}: {{ caStatus.intermediate?.expiresAt }}</p>
			</template>

			<ul v-if="caStatus?.issued" class="ca-health__issued" data-testid="ca-issued-counts">
				<li>{{ t('doriath', 'Active user vault certificates: {n}', { n: caStatus.issued.activeUserSuites }) }}</li>
				<li>{{ t('doriath', 'Active application certificates: {n}', { n: caStatus.issued.activeApplicationSuites }) }}</li>
				<li>{{ t('doriath', 'Stored certificate secrets: {n}', { n: caStatus.issued.storedCertificates }) }}</li>
				<li>{{ t('doriath', 'Stored certificates expiring within 30 days: {n}', { n: caStatus.issued.storedExpiringSoon }) }}</li>
			</ul>

			<NcButton
				v-if="caStatus?.status === 'not_configured'"
				type="primary"
				:disabled="loading"
				@click="retryBootstrap">
				{{ t('doriath', 'Retry bootstrap') }}
			</NcButton>

			<NcButton
				v-if="caStatus?.status === 'healthy' || caStatus?.status === 'expiring_soon'"
				type="secondary"
				:disabled="loading"
				@click="forceRenewIntermediate">
				{{ t('doriath', 'Force renew intermediate') }}
			</NcButton>
		</div>
	</CnSettingsSection>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CaHealthSection',
	components: { NcButton, CnSettingsSection },

	data() {
		return {
			caStatus: null,
			loading: false,
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
				healthy: t('doriath', 'Healthy'),
				expiring_soon: t('doriath', 'Expiring soon'),
				action_required: t('doriath', 'Action required'),
				not_configured: t('doriath', 'Not configured'),
			}
			return map[this.caStatus?.status] || t('doriath', 'Unknown')
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
			const response = await axios.get(generateUrl('/apps/doriath/api/v1/ca/status'))
			this.caStatus = response.data
		},
		/**
		 * Admin action: retry a failed CA bootstrap, then refresh status.
		 *
		 * @spec openspec/changes/retrofit-2026-05-25-doriath-coverage/tasks.md#task-8
		 */
		async retryBootstrap() {
			this.loading = true
			await axios.post(generateUrl('/apps/doriath/api/v1/ca/bootstrap-retry'))
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
			await axios.post(generateUrl('/apps/doriath/api/v1/ca/renew-intermediate'))
			await this.fetchStatus()
			this.loading = false
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
.ca-health__indicator--green { background: var(--color-success); }
.ca-health__indicator--yellow { background: var(--color-warning); }
.ca-health__indicator--red { background: var(--color-error); }
.ca-health__indicator--grey { background: var(--color-text-lighter); }
</style>
