<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  One immutable compliance snapshot, rendered for reading and for export.

  The printed boundary statement is part of the artefact, not decoration: an
  auditor reading a CSV or a printed PDF has to be told, on the document
  itself, that every figure is server-visible METADATA — Doriath holds no
  password strength, reuse or breach number anywhere server-side, and the
  ciphertext-age columns describe blob age, not password quality.

  The CSV and print-to-PDF exports live here with the markup they serialise:
  print-to-PDF reads `$refs.printable`, which only exists while this dialog is
  rendered. The audit beacon fires from here for the same reason — the export
  it records is the one this component performed.

  Lives in its own file per ADR-004: a dialog written inline in its parent
  couples presentation to the parent's lifecycle and cannot be reused.

  @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
  @spec openspec/specs/compliance-reporting/spec.md#requirement-zero-knowledge-honesty-boundary
  @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
-->
<template>
	<NcDialog
		:name="t('doriath', 'Compliance snapshot')"
		:open="report !== null"
		size="large"
		data-testid="compliance-snapshot-dialog"
		@update:open="$emit('close')">
		<div
			v-if="report"
			ref="printable"
			class="compliance__detail"
			data-testid="compliance-detail">
			<p class="compliance__boundary" data-testid="compliance-boundary">
				{{
					t(
						'doriath',
						'Zero-knowledge boundary: this report aggregates server-visible metadata only. No secret value, name, login, or ciphertext was read; no password strength, reuse, or breach figure exists anywhere in Doriath server-side. Ciphertext-age figures describe encryption-blob age, not password strength.',
					)
				}}
			</p>
			<p>
				{{
					t('doriath', 'Generated {at} by {by} on Doriath {version}', {
						at: formatDate(report.generatedAt),
						by: report.generatedBy,
						version: report.appVersion,
					})
				}}
			</p>
			<div
				v-for="(values, section) in report.aggregate"
				:key="section"
				class="compliance__section">
				<h4>{{ sectionTitle(section) }}</h4>
				<dl>
					<!-- Vue 3 requires the key on the <template> itself; a key on
					     a child of <template v-for> is a COMPILE error. -->
					<template
						v-for="(value, key) in values"
						:key="`${section}-${key}`">
						<dt>
							{{ key }}
						</dt>
						<dd>
							{{
								typeof value === 'object'
									? JSON.stringify(value)
									: value
							}}
						</dd>
					</template>
				</dl>
			</div>
			<h4>{{ t('doriath', 'Configuration snapshot') }}</h4>
			<dl>
				<template
					v-for="(value, key) in report.configSnapshot"
					:key="`c-${key}`">
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
			<NcButton
				variant="secondary"
				data-testid="compliance-export-csv"
				@click="exportCsv">
				{{ t('doriath', 'Export CSV') }}
			</NcButton>
			<NcButton
				variant="secondary"
				data-testid="compliance-export-pdf"
				@click="exportPdf">
				{{ t('doriath', 'Export PDF (print)') }}
			</NcButton>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('doriath', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcDialog } from '@nextcloud/vue'

export default {
	name: 'ComplianceSnapshotDialog',

	components: {
		NcButton,
		NcDialog,
	},

	props: {
		/**
		 * The snapshot to render, or `null` when the dialog is closed.
		 * Null-as-closed matches the parent's own state: there is nothing to
		 * render without a snapshot, so a separate `open` flag could only ever
		 * disagree with it.
		 */
		report: {
			type: Object,
			default: null,
		},
	},

	emits: ['close'],

	methods: {
		/**
		 * Client-side CSV export from the stored snapshot; fires the
		 * audit beacon (compliance-reporting §6.3).
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
		 */
		async exportCsv() {
			const rows = [['section', 'metric', 'value']]
			rows.push(['meta', 'generatedAt', this.report.generatedAt])
			rows.push(['meta', 'generatedBy', this.report.generatedBy])
			rows.push(['meta', 'appVersion', this.report.appVersion])
			for (const [key, value] of Object.entries(
				this.report.configSnapshot ?? {},
			)) {
				rows.push(['config', key, String(value)])
			}
			for (const [section, values] of Object.entries(
				this.report.aggregate ?? {},
			)) {
				for (const [key, value] of Object.entries(values ?? {})) {
					rows.push([
						section,
						key,
						typeof value === 'object'
							? JSON.stringify(value)
							: String(value),
					])
				}
			}
			const csv = rows
				.map((row) =>
					row
						.map((cell) => `"${String(cell).replace(/"/g, '""')}"`)
						.join(','),
				)
				.join('\n')
			const blob = new Blob([csv], { type: 'text/csv' })
			const link = document.createElement('a')
			link.href = URL.createObjectURL(blob)
			link.download = `doriath-compliance-${this.report.id}.csv`
			link.click()
			URL.revokeObjectURL(link.href)
			await this.beacon('csv')
		},

		/**
		 * Print-to-PDF export of the rendered snapshot; fires the beacon.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
		 */
		async exportPdf() {
			const printWindow = window.open('', '_blank')
			printWindow.document.write(
				'<html><head><title>Doriath compliance snapshot</title></head><body>'
					+ this.$refs.printable.innerHTML
					+ '</body></html>',
			)
			printWindow.document.close()
			printWindow.print()
			await this.beacon('pdf')
		},

		/**
		 * Fire the export audit beacon.
		 *
		 * @param {string} format The export format.
		 * @return {Promise<void>}
		 * @spec openspec/specs/compliance-reporting/spec.md#requirement-csv-and-pdf-export
		 */
		async beacon(format) {
			try {
				await axios.post(
					generateUrl(
						`/apps/doriath/api/v1/compliance/reports/${this.report.id}/exported`,
					),
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
		 * @spec openspec/specs/compliance-reporting/spec.md#requirement-org-level-metadata-only-compliance-report
		 */
		sectionTitle(section) {
			const titles = {
				adoption: this.t('doriath', 'Adoption'),
				secretsPerUser: this.t('doriath', 'Secrets per user'),
				shareHygiene: this.t('doriath', 'Share hygiene'),
				rotationPosture: this.t(
					'doriath',
					'Rotation posture (ciphertext-age, not strength)',
				),

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
		 * @spec openspec/specs/compliance-reporting/spec.md#requirement-immutable-timestamped-evidence-snapshot
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

<!--
  Moved verbatim from ComplianceSection.vue together with the markup they
  style — the parent no longer renders any of these elements.
-->
<style scoped>
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
