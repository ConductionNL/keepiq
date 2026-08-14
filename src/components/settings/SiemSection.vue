<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin SIEM export panel (siem-audit-export §6): sink list with
  per-sink delivery state, add/edit form (write-only HMAC secret,
  category filter, queue cap), test-fire, and delete. Sinks receive
  whitelisted audit metadata only — never secret material.

  @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
-->
<template>
	<CnSettingsSection
		:name="t('doriath', 'SIEM audit export')"
		:description="
			t(
				'doriath',
				'Forward whitelisted audit events to syslog or webhook sinks. Payloads carry sanitized metadata only — no secret value, name, login, or ciphertext ever leaves the server.',
			)
		">
		<div class="siem" data-testid="siem-section">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="notice" type="success">
				{{ notice }}
			</NcNoteCard>

			<table
				v-if="sinks.length"
				class="siem__table"
				data-testid="siem-sink-list">
				<thead>
					<tr>
						<th scope="col">
							{{ t('doriath', 'Name') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Type') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Status') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Last success') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Failures') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Dropped') }}
						</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="sink in sinks"
						:key="sink.id"
						:data-testid="`siem-sink-${sink.id}`">
						<td>
							{{ sink.name }}
							<span v-if="!sink.enabled" class="siem__muted"
								>({{ t('doriath', 'disabled') }})</span
							>
						</td>
						<td>{{ sink.type }}</td>
						<td>
							<span
								:class="statusClass(sink)"
								:data-testid="`siem-status-${sink.id}`">
								{{ statusLabel(sink) }}
							</span>
							<div
								v-if="sink.lastError"
								class="siem__muted siem__error-detail">
								{{ sink.lastError }}
							</div>
						</td>
						<td>{{ formatDate(sink.lastSuccessAt) }}</td>
						<td>{{ sink.consecutiveFailures }}</td>
						<td>{{ sink.droppedCount }}</td>
						<td class="siem__actions">
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:data-testid="`siem-test-${sink.id}`"
								@click="onTest(sink)">
								{{ t('doriath', 'Test') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:data-testid="`siem-edit-${sink.id}`"
								@click="startEdit(sink)">
								{{ t('doriath', 'Edit') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:data-testid="`siem-delete-${sink.id}`"
								@click="onDelete(sink)">
								{{ t('doriath', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="siem__muted">
				{{ t('doriath', 'No SIEM sinks configured.') }}
			</p>

			<NcButton
				v-if="!formOpen"
				variant="primary"
				data-testid="siem-add"
				@click="startCreate">
				{{ t('doriath', 'Add sink') }}
			</NcButton>

			<!-- Add / edit form. -->
			<div v-if="formOpen" class="siem__form" data-testid="siem-form">
				<h4>
					{{
						editingId
							? t('doriath', 'Edit sink')
							: t('doriath', 'New sink')
					}}
				</h4>
				<NcTextField
					v-model="form.name"
					:label="t('doriath', 'Name')"
					data-testid="siem-form-name" />
				<NcSelect
					v-if="!editingId"
					v-model="form.type"
					:options="['syslog', 'webhook']"
					:clearable="false"
					:inputLabel="t('doriath', 'Type')"
					data-testid="siem-form-type" />
				<NcTextField
					v-model="form.endpoint"
					:label="
						form.type === 'syslog'
							? t('doriath', 'Endpoint (host:port)')
							: t('doriath', 'Endpoint (https URL)')
					"
					:placeholder="
						form.type === 'syslog'
							? 'siem.example.org:6514'
							: 'https://siem.example.org/ingest'
					"
					data-testid="siem-form-endpoint" />
				<NcCheckboxRadioSwitch
					v-if="form.type === 'syslog'"
					v-model="form.tls"
					type="switch"
					data-testid="siem-form-tls">
					{{ t('doriath', 'Use TLS transport') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="form.type === 'webhook'"
					v-model="form.hmacSecret"
					type="password"
					:label="t('doriath', 'HMAC signing secret (write-only)')"
					:placeholder="
						editingHasSecret
							? t('doriath', 'Leave blank to keep the current secret')
							: ''
					"
					data-testid="siem-form-secret" />
				<NcSelect
					v-model="form.categoryFilter"
					:options="categoryOptions"
					multiple
					:inputLabel="
						t('doriath', 'Category filter (empty = all events)')
					"
					data-testid="siem-form-categories" />
				<NcTextField
					v-model="form.queueCap"
					type="number"
					:label="
						t('doriath', 'Queue cap (oldest events drop beyond this)')
					"
					data-testid="siem-form-queuecap" />
				<NcCheckboxRadioSwitch
					v-model="form.enabled"
					type="switch"
					data-testid="siem-form-enabled">
					{{ t('doriath', 'Enabled') }}
				</NcCheckboxRadioSwitch>
				<div class="siem__form-actions">
					<NcButton
						variant="primary"
						:disabled="busy || !formValid"
						data-testid="siem-form-save"
						@click="onSave">
						{{
							editingId ? t('doriath', 'Save') : t('doriath', 'Create')
						}}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						data-testid="siem-form-cancel"
						@click="formOpen = false">
						{{ t('doriath', 'Cancel') }}
					</NcButton>
				</div>
			</div>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'

/**
 * Audit-event category slugs (prefix before the first dot of an event
 * type). Kept in sync with lib/Event/Audit/AuditEventTypes.php.
 */
const CATEGORY_OPTIONS = [
	'secret',
	'folder',
	'share',
	'link_share',
	'request',
	'suite',
	'application',
	'vault',
	'emergency_access',
	'policy',
	'password_policy',
	'compliance',
	'lease',
	'attachment',
	'team_folder',
	'siem',
]

/**
 *
 */
function EMPTY_FORM() {
	return {
		name: '',
		type: 'webhook',
		endpoint: '',
		tls: true,
		hmacSecret: '',
		categoryFilter: [],
		queueCap: 1000,
		enabled: true,
	}
}

export default {
	name: 'SiemSection',
	components: {
		CnSettingsSection,
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcSelect,
		NcTextField,
	},

	data() {
		return {
			sinks: [],
			formOpen: false,
			editingId: null,
			editingHasSecret: false,
			form: EMPTY_FORM(),
			busy: false,
			error: null,
			notice: null,
			categoryOptions: CATEGORY_OPTIONS,
		}
	},

	computed: {
		formValid() {
			if (this.form.endpoint === '') {
				return false
			}
			if (
				this.form.type === 'webhook'
				&& !this.form.endpoint.startsWith('https://')
			) {
				return false
			}
			return true
		},
	},

	/**
	 * Load the sink list.
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/doriath/api/v1/siem/sinks'),
			)
			this.sinks = response.data ?? []
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Open the empty create form.
		 */
		startCreate() {
			this.editingId = null
			this.editingHasSecret = false
			this.form = EMPTY_FORM()
			this.formOpen = true
			this.notice = null
		},

		/**
		 * Open the edit form prefilled from a sink (secret stays blank —
		 * it is write-only).
		 *
		 * @param {object} sink The sink row.
		 */
		startEdit(sink) {
			this.editingId = sink.id
			this.editingHasSecret = sink.hasHmacSecret
			this.form = {
				name: sink.name,
				type: sink.type,
				endpoint: sink.endpoint,
				tls: sink.tls,
				hmacSecret: '',
				categoryFilter: [...(sink.categoryFilter ?? [])],
				queueCap: sink.queueCap,
				enabled: sink.enabled,
			}
			this.formOpen = true
			this.notice = null
		},

		/**
		 * Create or update the sink from the form.
		 *
		 * @return {Promise<void>}
		 */
		async onSave() {
			this.busy = true
			this.error = null
			try {
				const payload = {
					name: this.form.name || this.form.type,
					endpoint: this.form.endpoint,
					tls: this.form.tls,
					hmacSecret: this.form.hmacSecret,
					categoryFilter: this.form.categoryFilter,
					queueCap: parseInt(this.form.queueCap, 10) || 1000,
					enabled: this.form.enabled,
				}
				if (this.editingId) {
					const response = await axios.put(
						generateUrl(
							`/apps/doriath/api/v1/siem/sinks/${this.editingId}`,
						),
						payload,
					)
					this.sinks = this.sinks.map((s) =>
						s.id === this.editingId ? response.data : s,
					)
				} else {
					const response = await axios.post(
						generateUrl('/apps/doriath/api/v1/siem/sinks'),
						{
							...payload,
							type: this.form.type,
						},
					)
					this.sinks = [response.data, ...this.sinks]
				}
				this.formOpen = false
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Test-fire a synthetic payload.
		 *
		 * @param {object} sink The sink row.
		 * @return {Promise<void>}
		 */
		async onTest(sink) {
			this.busy = true
			this.error = null
			this.notice = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/doriath/api/v1/siem/sinks/${sink.id}/test`),
				)
				if (response.data?.ok) {
					this.notice = t('doriath', 'Test event delivered to "{name}".', {
						name: sink.name,
					})
				} else {
					this.error = t(
						'doriath',
						'Test delivery to "{name}" failed: {error}',
						{
							name: sink.name,
							error:
								response.data?.error
								|| t('doriath', 'unknown error'),
						},
					)
				}
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Delete a sink and its queued events.
		 *
		 * @param {object} sink The sink row.
		 * @return {Promise<void>}
		 */
		async onDelete(sink) {
			this.busy = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/doriath/api/v1/siem/sinks/${sink.id}`),
				)
				this.sinks = this.sinks.filter((s) => s.id !== sink.id)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message
			} finally {
				this.busy = false
			}
		},

		/**
		 * Human status label from the sink delivery state.
		 *
		 * @param {object} sink The sink row.
		 * @return {string}
		 */
		statusLabel(sink) {
			if (!sink.lastDeliveryStatus) {
				return t('doriath', 'never attempted')
			}
			return sink.lastDeliveryStatus
		},

		/**
		 * Status CSS class.
		 *
		 * @param {object} sink The sink row.
		 * @return {string}
		 */
		statusClass(sink) {
			if (sink.lastDeliveryStatus === 'ok') {
				return 'siem__status--ok'
			}
			if (
				sink.lastDeliveryStatus === 'failing'
				|| sink.lastDeliveryStatus === 'dead'
			) {
				return 'siem__status--bad'
			}
			return 'siem__muted'
		},

		/**
		 * Render an ISO date briefly.
		 *
		 * @param {string|null} iso The ISO timestamp.
		 * @return {string}
		 */
		formatDate(iso) {
			if (!iso) {
				return '—'
			}
			return new Date(iso).toLocaleString()
		},
	},
}
</script>

<style scoped lang="scss">
.siem {
	display: flex;
	flex-direction: column;
	gap: 12px;
	max-width: 900px;

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: start;
			padding: 6px 8px;
			border-bottom: 1px solid var(--color-border);
			vertical-align: top;
		}
	}

	&__actions {
		display: flex;
		gap: 4px;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__error-detail {
		font-size: 0.85em;
		max-width: 240px;
		overflow-wrap: anywhere;
	}

	&__status--ok {
		color: var(--color-success-text);
	}

	&__status--bad {
		color: var(--color-error-text);
	}

	&__form {
		display: flex;
		flex-direction: column;
		gap: 8px;
		max-width: 480px;
		padding: 12px;
		border: 1px solid var(--color-border);
		border-radius: var(--border-radius-large);
	}

	&__form-actions {
		display: flex;
		gap: 8px;
	}
}
</style>
