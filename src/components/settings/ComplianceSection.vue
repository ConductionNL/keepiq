<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin compliance panel (compliance-reporting §6): live posture card
  from the warm metrics cache, snapshot generation + list, snapshot
  detail with the printed metadata-only boundary statement, and
  client-side CSV / print-to-PDF export firing the audit beacon.

  @spec openspec/changes/compliance-reporting/specs/compliance-reporting/spec.md#requirement-admin-surface
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'Compliance reporting')"
		:description="t('doriath', 'BIO2/NIS2-oriented posture snapshots from server-visible metadata only — no secret value, name, or ciphertext is ever read.')">
		<div class="compliance" data-testid="compliance-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Live posture card (warm cache). -->
			<div v-if="metrics" class="compliance__card" data-testid="compliance-posture-card">
				<h4>{{ t('doriath', 'Live posture ({when})', { when: computedAt || t('doriath', 'just computed') }) }}</h4>
				<ul>
					<li>{{ t('doriath', 'Users with an active vault: {n}', { n: metrics.adoption?.usersWithActiveSuite ?? 0 }) }}</li>
					<li>{{ t('doriath', 'Total secrets: {n}', { n: metrics.secretsPerUser?.totalSecrets ?? 0 }) }}</li>
					<li>{{ t('doriath', 'Outstanding shares (user/group/link): {a} / {b} / {c}', { a: metrics.shareHygiene?.userShares ?? 0, b: metrics.shareHygiene?.groupShares ?? 0, c: metrics.shareHygiene?.linkShares ?? 0 }) }}</li>
					<li>{{ t('doriath', 'Open rotation flags: {n}', { n: openFlagTotal }) }}</li>
					<li>{{ t('doriath', 'Audit entries retained: {n} ({d} days)', { n: metrics.auditIntegrity?.totalEntries ?? 0, d: metrics.auditIntegrity?.retentionDays ?? 0 }) }}</li>
				</ul>
			</div>

			<NcButton variant="primary"
				:disabled="busy"
				data-testid="compliance-generate"
				@click="onGenerate">
				{{ t('doriath', 'Generate snapshot') }}
			</NcButton>

			<table v-if="reports.length" class="compliance__table" data-testid="compliance-report-list">
				<thead>
					<tr>
						<th scope="col">
							{{ t('doriath', 'Generated') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'By') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Version') }}
						</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr v-for="report in reports" :key="report.id">
						<td>{{ formatDate(report.generatedAt) }}</td>
						<td>{{ report.generatedBy }}</td>
						<td>{{ report.appVersion }}</td>
						<td>
							<NcButton variant="tertiary" :data-testid="`compliance-open-${report.id}`" @click="openReport(report.id)">
								{{ t('doriath', 'View') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Snapshot detail. -->
			<NcDialog :name="t('doriath', 'Compliance snapshot')"
				:open="detail !== null"
				size="large"
				@update:open="detail = null">
				<div v-if="detail"
					ref="printable"
					class="compliance__detail"
					data-testid="compliance-detail">
					<p class="compliance__boundary" data-testid="compliance-boundary">
						{{ t('doriath', 'Zero-knowledge boundary: this report aggregates server-visible metadata only. No secret value, name, login, or ciphertext was read; no password strength, reuse, or breach figure exists anywhere in Doriath server-side. Ciphertext-age figures describe encryption-blob age, not password strength.') }}
					</p>
					<p>
						{{ t('doriath', 'Generated {at} by {by} on Doriath {version}', { at: formatDate(detail.generatedAt), by: detail.generatedBy, version: detail.appVersion }) }}
					</p>
					<div v-for="(values, section) in detail.aggregate" :key="section" class="compliance__section">
						<h4>{{ sectionTitle(section) }}</h4>
						<dl>
							<!-- Vue 3 requires the key on the <template> itself; a key on
							     a child of <template v-for> is a COMPILE error. -->
							<template v-for="(value, key) in values" :key="`${section}-${key}`">
								<dt>
									{{ key }}
								</dt>
								<dd>
									{{ typeof value === 'object' ? JSON.stringify(value) : value }}
								</dd>
							</template>
						</dl>
					</div>
					<h4>{{ t('doriath', 'Configuration snapshot') }}</h4>
					<dl>
						<template v-for="(value, key) in detail.configSnapshot" :key="`c-${key}`">
							<dt>
								{{ key }}
							</dt>
							<dd>
								{{ value }}
							</dd>
						</template>
					</dl>
				</div>
				<template #actions>
					<NcButton variant="secondary" data-testid="compliance-export-csv" @click="exportCsv">
						{{ t('doriath', 'Export CSV') }}
					</NcButton>
					<NcButton variant="secondary" data-testid="compliance-export-pdf" @click="exportPdf">
						{{ t('doriath', 'Export PDF (print)') }}
					</NcButton>
					<NcButton variant="tertiary" @click="detail = null">
						{{ t('doriath', 'Close') }}
					</NcButton>
				</template>
			</NcDialog>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcDialog, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

export default {
	name: 'ComplianceSection',
	components: {
		CnSettingsSection,
		NcButton,
		NcDialog,
		NcNoteCard,
	},

	data() {
		return {
			metrics: null,
			computedAt: '',
			reports: [],
			detail: null,
			busy: false,
			error: null,
		}
	},

	computed: {
		openFlagTotal() {
			const byReason = this.metrics?.rotationPosture?.openFlagsByReason ?? {}
			return Object.values(byReason).reduce((total, count) => total + count, 0)
		},
	},

	/**
	 * Load the warm metrics + the snapshot list.
	 */
	async created() {
		try {
			const [metricsResponse, listResponse] = await Promise.all([
				axios.get(generateUrl('/apps/doriath/api/v1/compliance/metrics')),
				axios.get(generateUrl('/apps/doriath/api/v1/compliance/reports')),
			])
			this.metrics = metricsResponse.data?.metrics ?? null
			this.computedAt = metricsResponse.data?.computedAt ?? ''
			this.reports = listResponse.data ?? []
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Generate a new immutable snapshot.
		 *
		 * @return {Promise<void>}
		 */
		async onGenerate() {
			this.busy = true
			this.error = null
			try {
				const response = await axios.post(generateUrl('/apps/doriath/api/v1/compliance/reports'))
				this.reports = [response.data, ...this.reports]
				this.detail = response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Open one snapshot.
		 *
		 * @param {string} id The report id.
		 * @return {Promise<void>}
		 */
		async openReport(id) {
			try {
				const response = await axios.get(generateUrl(`/apps/doriath/api/v1/compliance/reports/${id}`))
				this.detail = response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		/**
		 * Client-side CSV export from the stored snapshot; fires the
		 * audit beacon (§6.3).
		 *
		 * @return {Promise<void>}
		 */
		async exportCsv() {
			const rows = [['section', 'metric', 'value']]
			rows.push(['meta', 'generatedAt', this.detail.generatedAt])
			rows.push(['meta', 'generatedBy', this.detail.generatedBy])
			rows.push(['meta', 'appVersion', this.detail.appVersion])
			for (const [key, value] of Object.entries(this.detail.configSnapshot ?? {})) {
				rows.push(['config', key, String(value)])
			}
			for (const [section, values] of Object.entries(this.detail.aggregate ?? {})) {
				for (const [key, value] of Object.entries(values ?? {})) {
					rows.push([section, key, typeof value === 'object' ? JSON.stringify(value) : String(value)])
				}
			}
			const csv = rows.map((row) => row.map((cell) => `"${String(cell).replace(/"/g, '""')}"`).join(',')).join('\n')
			const blob = new Blob([csv], { type: 'text/csv' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `doriath-compliance-${this.detail.id}.csv`
			link.click()
			URL.revokeObjectURL(link.href)
			await this.beacon('csv')
		},

		/**
		 * Print-to-PDF export of the rendered snapshot; fires the beacon.
		 *
		 * @return {Promise<void>}
		 */
		async exportPdf() {
			const printWindow = window.open('', '_blank')
			printWindow.document.write('<html><head><title>Doriath compliance snapshot</title></head><body>'
				+ this.$refs.printable.innerHTML + '</body></html>')
			printWindow.document.close()
			printWindow.print()
			await this.beacon('pdf')
		},

		/**
		 * Fire the export audit beacon.
		 *
		 * @param {string} format The export format.
		 * @return {Promise<void>}
		 */
		async beacon(format) {
			try {
				await axios.post(
					generateUrl(`/apps/doriath/api/v1/compliance/reports/${this.detail.id}/exported`),
					{ format },
				)
			} catch {
				// The export already happened client-side; a beacon miss
				// is logged nowhere on purpose (no secret involved).
			}
		},

		/**
		 * Section display titles.
		 *
		 * @param {string} section The section key.
		 * @return {string}
		 */
		sectionTitle(section) {
			const titles = {
				adoption: this.t('doriath', 'Adoption'),
				secretsPerUser: this.t('doriath', 'Secrets per user'),
				shareHygiene: this.t('doriath', 'Share hygiene'),
				rotationPosture: this.t('doriath', 'Rotation posture (ciphertext-age, not strength)'),
				auditIntegrity: this.t('doriath', 'Audit-trail integrity'),
				emergencyAccess: this.t('doriath', 'Emergency-access coverage'),
			}
			return titles[section] || section
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
.compliance {
	display: flex;
	flex-direction: column;
	gap: 16px;
	max-width: 720px;
}

.compliance__card {
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	padding: 12px 16px;
}

.compliance__table {
	width: 100%;
	border-collapse: collapse;
}

.compliance__table th,
.compliance__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.compliance__detail {
	padding: 4px 12px 12px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.compliance__boundary {
	font-style: italic;
	color: var(--color-text-maxcontrast, #777);
}

.compliance__section dl,
.compliance__detail dl {
	display: grid;
	grid-template-columns: max-content 1fr;
	gap: 2px 16px;
}

.compliance__section dt,
.compliance__detail dt {
	font-weight: 600;
	font-size: 13px;
}
</style>
