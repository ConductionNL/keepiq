<template>
	<div class="ca-health-card" :class="`ca-health-card--${status}`">
		<div class="ca-health-card__header">
			<span class="ca-health-card__indicator" :title="status" />
			<h3>{{ t('keepiq', 'Certificate Authority') }}</h3>
		</div>
		<div class="ca-health-card__body">
			<div class="ca-health-card__row">
				<span>{{ t('keepiq', 'Status') }}</span>
				<strong>{{ statusLabel }}</strong>
			</div>
			<div v-if="intermediateExpiresAt" class="ca-health-card__row">
				<span>{{ t('keepiq', 'Intermediate expires') }}</span>
				<strong>{{ formatDate(intermediateExpiresAt) }}</strong>
			</div>
			<a class="ca-health-card__link" :href="adminSettingsUrl">
				{{ t('keepiq', 'Open admin settings') }}
			</a>
		</div>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'CaHealthCard',

	data() {
		return {
			status: 'unknown',
			intermediateExpiresAt: null,
			rootExpiresAt: null,
		}
	},

	computed: {
		statusLabel() {
			const map = {
				healthy: t('keepiq', 'Healthy'),
				expiring_soon: t('keepiq', 'Expiring soon'),
				degraded: t('keepiq', 'Degraded'),
				not_configured: t('keepiq', 'Not configured'),
				unknown: t('keepiq', 'Unknown'),
			}
			return map[this.status] || this.status
		},

		adminSettingsUrl() {
			return generateUrl('/settings/admin/keepiq')
		},
	},

	/**
	 * Load CA status from the admin settings endpoint.
	 *
	 * @spec openspec/changes/implement-dashboard-settings/tasks.md#task-3.4
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/ca/status'),
			)
			this.status = response.data.status || 'unknown'
			this.intermediateExpiresAt =
				response.data.intermediate_expires_at || null
			this.rootExpiresAt = response.data.root_expires_at || null
		} catch (e) {
			console.warn('Keepiq: failed to load CA status', e)
			this.status = 'unknown'
		}
	},

	methods: {
		formatDate(date) {
			if (!date) return ''
			try {
				return new Date(date).toLocaleDateString()
			} catch {
				return String(date)
			}
		},
	},
}
</script>

<style scoped>
.ca-health-card {
	background: var(--color-main-background);
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);
	padding: 1rem;
}

.ca-health-card__header {
	display: flex;
	align-items: center;
	gap: 8px;
	margin-bottom: 0.5rem;
}

.ca-health-card__header h3 {
	margin: 0;
	font-size: 1rem;
}

.ca-health-card__indicator {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	background: var(--color-text-lighter);
}

.ca-health-card--healthy .ca-health-card__indicator {
	background: var(--color-success-text);
}

.ca-health-card--expiring_soon .ca-health-card__indicator {
	background: var(--color-warning-text);
}

.ca-health-card--degraded .ca-health-card__indicator,
.ca-health-card--not_configured .ca-health-card__indicator {
	background: var(--color-error-text);
}

.ca-health-card__row {
	display: flex;
	justify-content: space-between;
	padding: 4px 0;
}

.ca-health-card__link {
	display: inline-block;
	margin-top: 0.5rem;
	color: var(--color-primary-element);
}
</style>
