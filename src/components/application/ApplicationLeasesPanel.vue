<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Active-leases panel on the application detail view
  (machine-secret-leases §6.1): the application's leases with grant /
  expiry / renewal metadata and a per-lease revoke action for the
  registrant or an admin. Identifiers and lifetimes only — no secret
  material appears here.

  @spec openspec/specs/machine-secret-leases/spec.md#requirement-lease-revocation-by-admin-owner-or-application
-->
<template>
	<section class="app-leases" data-testid="application-leases-panel">
		<h3>{{ t('doriath', 'Machine leases') }}</h3>

		<NcNoteCard v-if="error" type="error" data-testid="lease-error">
			{{ error }}
		</NcNoteCard>

		<p v-if="leases.length === 0" class="app-leases__empty">
			{{ t('doriath', 'No leases yet — a lease appears when the application fetches a secret.') }}
		</p>

		<table v-else class="app-leases__table">
			<thead>
				<tr>
					<th scope="col">
						{{ t('doriath', 'Secret') }}
					</th>
					<th scope="col">
						{{ t('doriath', 'Granted') }}
					</th>
					<th scope="col">
						{{ t('doriath', 'Expires') }}
					</th>
					<th scope="col">
						{{ t('doriath', 'Renewals') }}
					</th>
					<th scope="col">
						{{ t('doriath', 'Status') }}
					</th>
					<th scope="col" />
				</tr>
			</thead>
			<tbody>
				<tr v-for="lease in leases" :key="lease.id" :data-testid="`lease-${lease.id}`">
					<td class="app-leases__mono">
						{{ lease.secretId }}
					</td>
					<td>{{ formatDate(lease.grantedAt) }}</td>
					<td>{{ formatDate(lease.expiresAt) }}</td>
					<td>{{ lease.renewedCount }}</td>
					<td>
						<span :class="['app-leases__status', `app-leases__status--${lease.status}`]">
							{{ lease.status }}
						</span>
					</td>
					<td>
						<NcButton v-if="lease.status === 'active'"
							variant="tertiary"
							:data-testid="`lease-revoke-${lease.id}`"
							@click="onRevoke(lease)">
							{{ t('doriath', 'Revoke') }}
						</NcButton>
					</td>
				</tr>
			</tbody>
		</table>
	</section>
</template>

<script>
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useLeaseStore } from '../../store/modules/lease.js'

export default {
	name: 'ApplicationLeasesPanel',
	components: {
		NcButton,
		NcNoteCard,
	},
	props: {
		applicationId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			error: null,
		}
	},
	computed: {
		store() {
			return useLeaseStore()
		},
		leases() {
			return this.store.leases
		},
	},
	async mounted() {
		try {
			await this.store.fetchForApplication(this.applicationId)
			this.error = null
		} catch (e) {
			// A 404 means the viewer may not manage this application's
			// leases — hide silently rather than surfacing an error.
			if (e?.response?.status !== 404) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to load leases'
			}
		}
	},
	methods: {
		/**
		 * Revoke one lease (admin/registrant surface).
		 *
		 * @param {object} lease The lease row.
		 * @return {Promise<void>}
		 */
		async onRevoke(lease) {
			try {
				await this.store.revoke(lease.id)
				this.error = null
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to revoke lease'
			}
		},

		/**
		 * Locale date-time display.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDate(iso) {
			const parsed = Date.parse(iso ?? '')
			return Number.isNaN(parsed) ? (iso ?? '') : new Date(parsed).toLocaleString()
		},
	},
}
</script>

<style scoped>
.app-leases__table {
	width: 100%;
	border-collapse: collapse;
}

.app-leases__table th,
.app-leases__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.app-leases__mono {
	font-family: monospace;
	font-size: 12px;
}

.app-leases__status {
	padding: 1px 8px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 12px;
	font-weight: 600;
}

.app-leases__status--active {
	background-color: var(--color-success, #2d7b41);
	color: var(--color-success-text, #fff);
}

.app-leases__status--revoked {
	background-color: var(--color-error, #d91f2d);
	color: var(--color-error-text, #fff);
}

.app-leases__status--expired {
	background-color: var(--color-background-dark, #ededed);
	color: var(--color-main-text, #222);
}

.app-leases__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
