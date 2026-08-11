<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Certificate inventory (certificate-lifecycle §5.2/§5.3): stored
  certificate secrets (client-parsed metadata — the PEM is decrypted and
  parsed in this browser, never sent), the user's CA-issued suite
  certificate (server-parsed), and for admins all suites plus the CA
  root/intermediate. Renewal: private-CA re-issue for suite certs; a
  guided checklist for externally-issued stored certs.

  @spec openspec/changes/certificate-lifecycle/specs/certificate-lifecycle/spec.md#requirement-certificate-inventory
-->
<template>
	<div class="cert-inventory" data-testid="certificate-inventory-view">
		<h2>{{ t('doriath', 'Certificates') }}</h2>

		<NcNoteCard v-if="store.error" type="error">
			{{ store.error }}
		</NcNoteCard>

		<NcLoadingIcon v-if="store.loading && !store.inventory" :size="24" />

		<template v-if="store.inventory">
			<!-- Stored certificate secrets (client-parsed). -->
			<section data-testid="cert-stored-section">
				<h3>{{ t('doriath', 'Stored certificates') }}</h3>
				<p class="cert-inventory__hint">
					{{ t('doriath', 'These live encrypted in your vault. Metadata is parsed in your browser after unlocking — the certificate itself never leaves it.') }}
				</p>
				<NcEmptyContent v-if="store.inventory.stored.length === 0"
					:name="t('doriath', 'No certificate secrets')"
					:description="t('doriath', 'Secrets of the Certificate type appear here.')" />
				<table v-else class="cert-inventory__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('doriath', 'Name') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Subject') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Issuer') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Expires') }}
							</th>
							<th scope="col" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in store.inventory.stored" :key="row.id" :data-testid="`cert-stored-${row.id}`">
							<td>{{ row.name }}</td>
							<td>{{ row.metadata?.subject || '—' }}</td>
							<td>{{ row.metadata?.issuer || '—' }}</td>
							<td>
								<span v-if="row.metadata?.notAfter" :class="expiryClass(row.metadata.notAfter)">
									{{ formatDate(row.metadata.notAfter) }}
								</span>
								<span v-else class="cert-inventory__muted">{{ t('doriath', 'not parsed yet') }}</span>
							</td>
							<td class="cert-inventory__actions">
								<NcButton variant="tertiary"
									:disabled="busy || locked"
									:data-testid="`cert-parse-${row.id}`"
									@click="onParse(row)">
									{{ row.metadata ? t('doriath', 'Re-parse') : t('doriath', 'Parse certificate') }}
								</NcButton>
								<NcButton variant="tertiary"
									:disabled="busy"
									:data-testid="`cert-renew-${row.id}`"
									@click="onChecklist(row)">
									{{ t('doriath', 'Renew…') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<p v-if="locked" class="cert-inventory__muted" data-testid="cert-locked-hint">
					{{ t('doriath', 'Unlock your vault to parse stored certificates.') }}
				</p>
			</section>

			<!-- CA-issued suite certificates (server-parsed). -->
			<section data-testid="cert-suites-section">
				<h3>{{ t('doriath', 'Vault encryption certificates') }}</h3>
				<p class="cert-inventory__hint">
					{{ t('doriath', 'Issued by the built-in certificate authority. Re-issuing keeps your existing key pair — nothing becomes unreadable.') }}
				</p>
				<table v-if="store.inventory.suites.length" class="cert-inventory__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('doriath', 'Owner') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Subject') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Expires') }}
							</th>
							<th scope="col" />
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in store.inventory.suites" :key="row.id" :data-testid="`cert-suite-${row.id}`">
							<td>{{ row.ownerType }}: {{ row.ownerId }}</td>
							<td>{{ row.metadata?.subject || '—' }}</td>
							<td>
								<span v-if="row.metadata?.notAfter" :class="expiryClass(row.metadata.notAfter)">
									{{ formatDate(row.metadata.notAfter) }}
								</span>
								<span v-else>—</span>
							</td>
							<td>
								<NcButton variant="tertiary"
									:disabled="busy"
									:data-testid="`cert-reissue-${row.id}`"
									@click="onReissue(row)">
									{{ t('doriath', 'Re-issue') }}
								</NcButton>
							</td>
						</tr>
					</tbody>
				</table>
				<NcEmptyContent v-else :name="t('doriath', 'No active vault certificate')" />
			</section>

			<!-- CA certificates (admin only). -->
			<section v-if="store.inventory.ca.length" data-testid="cert-ca-section">
				<h3>{{ t('doriath', 'Certificate authority') }}</h3>
				<table class="cert-inventory__table">
					<thead>
						<tr>
							<th scope="col">
								{{ t('doriath', 'Role') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Subject') }}
							</th>
							<th scope="col">
								{{ t('doriath', 'Expires') }}
							</th>
						</tr>
					</thead>
					<tbody>
						<tr v-for="row in store.inventory.ca" :key="row.caRole" :data-testid="`cert-ca-${row.caRole}`">
							<td>{{ row.caRole }}</td>
							<td>{{ row.metadata?.subject || '—' }}</td>
							<td>
								<span v-if="row.metadata?.notAfter" :class="expiryClass(row.metadata.notAfter)">
									{{ formatDate(row.metadata.notAfter) }}
								</span>
								<span v-else>—</span>
							</td>
						</tr>
					</tbody>
				</table>
			</section>
		</template>

		<!-- Renewal checklist dialog (externally-issued stored certs). -->
		<CertificateRenewalChecklistDialog :checklist="checklist" @close="checklist = null" />
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import { showSuccess, showError } from '@nextcloud/dialogs'
import CertificateRenewalChecklistDialog from '../dialogs/CertificateRenewalChecklistDialog.vue'
import { useCertificateStore } from '../store/modules/certificate.js'
import { useSessionStore } from '../store/modules/session.js'

export default {
	name: 'CertificateInventoryView',

	components: {
		CertificateRenewalChecklistDialog,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
	},

	data() {
		return {
			store: useCertificateStore(),
			session: useSessionStore(),
			checklist: null,
			busy: false,
		}
	},

	computed: {
		locked() {
			return this.session.isLocked
		},
	},

	/**
	 * Load the inventory on entry.
	 */
	created() {
		this.store.fetchInventory()
	},

	methods: {
		/**
		 * Decrypt + parse a stored certificate in the browser and submit
		 * its display metadata.
		 *
		 * @param {object} row The stored-secret inventory row.
		 * @return {Promise<void>}
		 */
		async onParse(row) {
			this.busy = true
			try {
				const result = await this.store.parseAndSubmit(row.id)
				if (result) {
					showSuccess(t('doriath', 'Certificate parsed — expiry reminder set.'))
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open the renewal checklist for a stored certificate.
		 *
		 * @param {object} row The stored-secret inventory row.
		 * @return {Promise<void>}
		 */
		async onChecklist(row) {
			this.busy = true
			try {
				this.checklist = await this.store.renewalChecklist(row.id)
			} finally {
				this.busy = false
			}
		},

		/**
		 * Re-issue a suite certificate from the private CA.
		 *
		 * @param {object} row The suite inventory row.
		 * @return {Promise<void>}
		 */
		async onReissue(row) {
			this.busy = true
			try {
				const result = await this.store.reissueSuite(row.id)
				if (result) {
					showSuccess(t('doriath', 'Certificate re-issued with the same key pair.'))
				} else if (this.store.error) {
					showError(this.store.error)
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Style expiry: red when past/inside 7 days, orange inside 30.
		 *
		 * @param {string} iso The notAfter ISO timestamp.
		 * @return {string} CSS class.
		 */
		expiryClass(iso) {
			const daysLeft = (new Date(iso).getTime() - Date.now()) / 86400000
			if (daysLeft < 7) {
				return 'cert-inventory__expiry--critical'
			}
			if (daysLeft < 30) {
				return 'cert-inventory__expiry--warning'
			}
			return ''
		},

		/**
		 * Render an ISO date briefly.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string} Localised date.
		 */
		formatDate(iso) {
			return new Date(iso).toLocaleDateString()
		},
	},
}
</script>

<style scoped lang="scss">
.cert-inventory {
	padding: 20px;
	max-width: 1100px;

	section {
		margin-top: 24px;
	}

	&__hint {
		color: var(--color-text-maxcontrast);
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: start;
			padding: 6px 8px;
			border-bottom: 1px solid var(--color-border);
			vertical-align: top;
			overflow-wrap: anywhere;
		}
	}

	&__actions {
		display: flex;
		gap: 4px;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__expiry--warning {
		color: var(--color-warning-text);
		font-weight: bold;
	}

	&__expiry--critical {
		color: var(--color-error-text);
		font-weight: bold;
	}
}
</style>
