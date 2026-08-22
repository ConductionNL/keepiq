<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin compliance panel (compliance-reporting §6): live posture card
  from the warm metrics cache, snapshot generation + list, snapshot
  detail with the printed metadata-only boundary statement, and
  client-side CSV / print-to-PDF export firing the audit beacon.

  @spec openspec/specs/compliance-reporting/spec.md#requirement-org-level-metadata-only-compliance-report
  @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
  @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Compliance reporting')"
		:description="
			t(
				'keepiq',
				'BIO2/NIS2-oriented posture snapshots from server-visible metadata only — no secret value, name, or ciphertext is ever read.',
			)
		">
		<div class="compliance" data-testid="compliance-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Live posture card (warm cache). -->
			<div
				v-if="metrics"
				class="compliance__card"
				data-testid="compliance-posture-card">
				<h4>
					{{
						t('keepiq', 'Live posture ({when})', {
							when: computedAt || t('keepiq', 'just computed'),
						})
					}}
				</h4>
				<ul>
					<li>
						{{
							t('keepiq', 'Users with an active vault: {n}', {
								n: metrics.adoption?.usersWithActiveSuite ?? 0,
							})
						}}
					</li>
					<li>
						{{
							t('keepiq', 'Total secrets: {n}', {
								n: metrics.secretsPerUser?.totalSecrets ?? 0,
							})
						}}
					</li>
					<li>
						{{
							t(
								'keepiq',
								'Outstanding shares (user/group/link): {a} / {b} / {c}',
								{
									a: metrics.shareHygiene?.userShares ?? 0,
									b: metrics.shareHygiene?.groupShares ?? 0,
									c: metrics.shareHygiene?.linkShares ?? 0,
								},
							)
						}}
					</li>
					<li>
						{{
							t('keepiq', 'Open rotation flags: {n}', {
								n: openFlagTotal,
							})
						}}
					</li>
					<li>
						{{
							t('keepiq', 'Audit entries retained: {n} ({d} days)', {
								n: metrics.auditIntegrity?.totalEntries ?? 0,
								d: metrics.auditIntegrity?.retentionDays ?? 0,
							})
						}}
					</li>
				</ul>
			</div>

			<NcButton
				variant="primary"
				:disabled="busy"
				data-testid="compliance-generate"
				@click="onGenerate">
				{{ t('keepiq', 'Generate snapshot') }}
			</NcButton>

			<table
				v-if="reports.length"
				class="compliance__table"
				data-testid="compliance-report-list">
				<thead>
					<tr>
						<th scope="col">
							{{ t('keepiq', 'Generated') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'By') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Version') }}
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
							<NcButton
								variant="tertiary"
								:data-testid="`compliance-open-${report.id}`"
								@click="openReport(report.id)">
								{{ t('keepiq', 'View') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>

			<!-- Snapshot detail. -->
			<ComplianceSnapshotDialog :report="detail" @close="detail = null" />
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import ComplianceSnapshotDialog from '../../dialogs/ComplianceSnapshotDialog.vue'

export default {
	name: 'ComplianceSection',
	components: {
		CnSettingsSection,
		ComplianceSnapshotDialog,
		NcButton,
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
				axios.get(generateUrl('/apps/keepiq/api/v1/compliance/metrics')),
				axios.get(generateUrl('/apps/keepiq/api/v1/compliance/reports')),
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
				const response = await axios.post(
					generateUrl('/apps/keepiq/api/v1/compliance/reports'),
				)
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
				const response = await axios.get(
					generateUrl(`/apps/keepiq/api/v1/compliance/reports/${id}`),
				)
				this.detail = response.data
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			}
		},

		// exportCsv / exportPdf / beacon / sectionTitle moved to
		// src/dialogs/ComplianceSnapshotDialog.vue together with the markup they
		// serialise: print-to-PDF reads a `ref` that only exists inside the
		// dialog, and the beacon records the export that component performed.

		/**
		 * Locale date-time display.
		 *
		 * @param {string} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDate(iso) {
			const parsed = Date.parse(iso ?? '')
			return Number.isNaN(parsed)
				? (iso ?? '')
				: new Date(parsed).toLocaleString()
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
