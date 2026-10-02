<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin SIEM export panel (siem-audit-export §6): sink list with
  per-sink delivery state, add/edit form (write-only HMAC secret,
  category filter, queue cap), test-fire, and delete. Sinks receive
  whitelisted audit metadata only — never secret material.

  The root id section-siem is the anchor lib/Settings/connections.json links the
  SIEM audit export connection to (adopt-connection-registry). Keep it stable.

  @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
-->
<template>
	<CnSettingsSection
		id="section-siem"
		:name="t('keepiq', 'SIEM audit export')"
		:description="
			t(
				'keepiq',
				'Forward whitelisted audit events to Splunk, Microsoft Sentinel, a syslog listener or a webhook. Payloads carry sanitized metadata only: no secret value, name, login or ciphertext ever leaves the server.',
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
							{{ t('keepiq', 'Name') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Type') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Status') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Last success') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Failures') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Dropped') }}
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
								>({{ t('keepiq', 'disabled') }})</span
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
								{{ t('keepiq', 'Test') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:data-testid="`siem-edit-${sink.id}`"
								@click="startEdit(sink)">
								{{ t('keepiq', 'Edit') }}
							</NcButton>
							<NcButton
								variant="tertiary"
								:disabled="busy"
								:data-testid="`siem-delete-${sink.id}`"
								@click="onDelete(sink)">
								{{ t('keepiq', 'Delete') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="siem__muted">
				{{ t('keepiq', 'No SIEM sinks configured.') }}
			</p>

			<NcButton
				v-if="!formOpen"
				variant="primary"
				data-testid="siem-add"
				@click="startCreate">
				{{ t('keepiq', 'Add sink') }}
			</NcButton>

			<!-- Add / edit form. -->
			<div v-if="formOpen" class="siem__form" data-testid="siem-form">
				<h4>
					{{
						editingId
							? t('keepiq', 'Edit sink')
							: t('keepiq', 'New sink')
					}}
				</h4>
				<NcTextField
					v-model="form.name"
					:label="t('keepiq', 'Name')"
					data-testid="siem-form-name" />
				<NcSelect
					v-if="!editingId"
					v-model="connectorOption"
					:options="connectorOptions"
					label="label"
					:clearable="false"
					:inputLabel="t('keepiq', 'Connector')"
					data-testid="siem-form-connector" />
				<NcTextField
					v-if="shows('endpoint')"
					v-model="form.endpoint"
					:label="endpointLabel"
					:placeholder="endpointPlaceholder"
					data-testid="siem-form-endpoint" />
				<NcCheckboxRadioSwitch
					v-if="shows('tls')"
					v-model="form.tls"
					type="switch"
					data-testid="siem-form-tls">
					{{ t('keepiq', 'Use TLS transport') }}
				</NcCheckboxRadioSwitch>
				<NcTextField
					v-if="shows('hmacSecret')"
					v-model="form.hmacSecret"
					type="password"
					:label="t('keepiq', 'HMAC signing secret (write-only)')"
					:placeholder="
						editingHasSecret
							? t('keepiq', 'Leave blank to keep the current secret')
							: ''
					"
					data-testid="siem-form-secret" />
				<NcTextField
					v-if="shows('tenantId')"
					v-model="form.tenantId"
					:label="t('keepiq', 'Directory (tenant) ID')"
					data-testid="siem-form-tenant" />
				<NcTextField
					v-if="shows('clientId')"
					v-model="form.clientId"
					:label="t('keepiq', 'Application (client) ID')"
					data-testid="siem-form-client" />
				<NcTextField
					v-if="shows('dcrImmutableId')"
					v-model="form.dcrImmutableId"
					:label="t('keepiq', 'Data collection rule immutable ID')"
					data-testid="siem-form-dcr" />
				<NcTextField
					v-if="shows('streamName')"
					v-model="form.streamName"
					:label="t('keepiq', 'Stream name')"
					placeholder="Custom-KeepiqAudit"
					data-testid="siem-form-stream" />
				<NcTextField
					v-if="shows('index')"
					v-model="form.index"
					:label="t('keepiq', 'Splunk index (optional)')"
					data-testid="siem-form-index" />
				<NcTextField
					v-if="shows('sourcetype')"
					v-model="form.sourcetype"
					:label="t('keepiq', 'Sourcetype (optional)')"
					placeholder="keepiq:audit"
					data-testid="siem-form-sourcetype" />
				<NcTextField
					v-if="shows('credential')"
					v-model="form.credential"
					type="password"
					:label="credentialLabel"
					:placeholder="
						editingHasCredential
							? t('keepiq', 'Leave blank to keep the current one')
							: ''
					"
					data-testid="siem-form-credential" />
				<NcSelect
					v-model="form.categoryFilter"
					:options="categoryOptions"
					multiple
					:inputLabel="t('keepiq', 'Category filter (empty = all events)')"
					data-testid="siem-form-categories" />
				<NcTextField
					v-model="form.queueCap"
					type="number"
					:label="
						t('keepiq', 'Queue cap (oldest events drop beyond this)')
					"
					data-testid="siem-form-queuecap" />
				<NcCheckboxRadioSwitch
					v-model="form.enabled"
					type="switch"
					data-testid="siem-form-enabled">
					{{ t('keepiq', 'Enabled') }}
				</NcCheckboxRadioSwitch>
				<div class="siem__form-actions">
					<NcButton
						variant="primary"
						:disabled="busy || !formValid"
						data-testid="siem-form-save"
						@click="onSave">
						{{ editingId ? t('keepiq', 'Save') : t('keepiq', 'Create') }}
					</NcButton>
					<NcButton
						variant="tertiary"
						:disabled="busy"
						data-testid="siem-form-cancel"
						@click="formOpen = false">
						{{ t('keepiq', 'Cancel') }}
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
import {
	connectorOf,
	fieldsFor,
	formIsValid,
	requestBody,
} from './siemConnectors.js'

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
		connector: 'splunk_hec',
		endpoint: '',
		tls: true,
		hmacSecret: '',
		credential: '',
		tenantId: '',
		clientId: '',
		dcrImmutableId: '',
		streamName: '',
		index: '',
		sourcetype: '',
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
			editingHasCredential: false,
			form: EMPTY_FORM(),
			busy: false,
			error: null,
			notice: null,
			categoryOptions: CATEGORY_OPTIONS,
		}
	},

	computed: {
		/**
		 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
		 */
		formValid() {
			return formIsValid(this.form, !this.editingId)
		},

		/**
		 * The picker entries, in the order the design names them.
		 *
		 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
		 */
		connectorOptions() {
			return [
				{
					id: 'splunk_hec',
					label: t('keepiq', 'Splunk HTTP Event Collector'),
				},
				{ id: 'sentinel', label: t('keepiq', 'Microsoft Sentinel') },
				{ id: 'syslog_cef', label: t('keepiq', 'CEF over syslog') },
				{ id: 'syslog_json', label: t('keepiq', 'Syslog JSON') },
				{ id: 'webhook', label: t('keepiq', 'Webhook JSON') },
			]
		},

		/**
		 * The picker's selected entry, bound to form.connector.
		 *
		 * @spec exclude Two-way binding between the picker object and the connector key.
		 */
		connectorOption: {
			/**
			 * @spec exclude Two-way binding: the picker object for the connector key.
			 */
			get() {
				return this.connectorOptions.find(
					(o) => o.id === this.form.connector,
				)
			},

			/**
			 * @param {object} option The picked entry.
			 * @spec exclude Two-way binding: stores the picked connector key.
			 */
			set(option) {
				this.form.connector = option?.id ?? 'splunk_hec'
			},
		},

		/**
		 * @spec exclude Presentation-only: endpoint label per connector.
		 */
		endpointLabel() {
			if (this.form.connector === 'sentinel') {
				return t('keepiq', 'Data collection endpoint (https URL)')
			}
			if (this.form.connector === 'splunk_hec') {
				return t('keepiq', 'HTTP Event Collector URL (https)')
			}
			return this.form.connector.startsWith('syslog')
				? t('keepiq', 'Endpoint (host:port)')
				: t('keepiq', 'Endpoint (https URL)')
		},

		/**
		 * @spec exclude Presentation-only: endpoint example per connector.
		 */
		endpointPlaceholder() {
			return {
				splunk_hec:
					'https://splunk.example.org:8088/services/collector/event',

				sentinel: 'https://keepiq-dce.westeurope-1.ingest.monitor.azure.com',
				syslog_cef: 'siem.example.org:6514',
				syslog_json: 'siem.example.org:6514',
				webhook: 'https://siem.example.org/ingest',
			}[this.form.connector]
		},

		/**
		 * @spec exclude Presentation-only: credential label per connector.
		 */
		credentialLabel() {
			return this.form.connector === 'sentinel'
				? t('keepiq', 'Client secret (write-only)')
				: t('keepiq', 'HEC token (write-only)')
		},
	},

	/**
	 * Load the sink list.
	 *
	 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
	 */
	async created() {
		try {
			const response = await axios.get(
				generateUrl('/apps/keepiq/api/v1/siem/sinks'),
			)
			this.sinks = response.data ?? []
		} catch (e) {
			this.error = e?.response?.data?.message || e?.message
		}
	},

	methods: {
		/**
		 * Open the empty create form.
		 *
		 * @spec exclude Form-state reset: opens an empty sink create form with default values.
		 */
		startCreate() {
			this.editingId = null
			this.editingHasSecret = false
			this.editingHasCredential = false
			this.form = EMPTY_FORM()
			this.formOpen = true
			this.notice = null
		},

		/**
		 * Open the edit form prefilled from a sink (secret stays blank —
		 * it is write-only).
		 *
		 * @param {object} sink The sink row.
		 *
		 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
		 */
		startEdit(sink) {
			const options = sink.connectorOptions ?? {}
			this.editingId = sink.id
			this.editingHasSecret = sink.hasHmacSecret
			this.editingHasCredential = sink.hasCredential === true
			this.form = {
				...EMPTY_FORM(),
				name: sink.name,
				connector: connectorOf(sink),
				endpoint: sink.endpoint,
				tls: sink.tls,
				// Write-only: never prefilled, blank keeps the stored value.
				hmacSecret: '',
				credential: '',
				tenantId: options.tenantId ?? '',
				clientId: options.clientId ?? '',
				dcrImmutableId: options.dcrImmutableId ?? '',
				streamName: options.streamName ?? '',
				index: options.index ?? '',
				sourcetype: options.sourcetype ?? '',
				categoryFilter: [...(sink.categoryFilter ?? [])],
				queueCap: sink.queueCap,
				enabled: sink.enabled,
			}
			this.formOpen = true
			this.notice = null
		},

		/**
		 * Whether the form shows a field for the picked connector.
		 *
		 * @param {string} field The field name.
		 * @return {boolean}
		 * @spec openspec/specs/siem-vendor-connectors/spec.md#requirement-named-siem-connectors-on-a-sink
		 */
		shows(field) {
			return fieldsFor(this.form.connector).includes(field)
		},

		/**
		 * Create or update the sink from the form.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
		 */
		async onSave() {
			this.busy = true
			this.error = null
			try {
				const { type, ...payload } = requestBody(this.form)
				if (this.editingId) {
					const response = await axios.put(
						generateUrl(
							`/apps/keepiq/api/v1/siem/sinks/${this.editingId}`,
						),
						payload,
					)
					this.sinks = this.sinks.map((s) =>
						s.id === this.editingId ? response.data : s,
					)
				} else {
					const response = await axios.post(
						generateUrl('/apps/keepiq/api/v1/siem/sinks'),
						{
							...payload,
							type,
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
		 * @spec openspec/specs/siem-audit-export/spec.md#requirement-forwarded-payload-carries-no-secret-material
		 */
		async onTest(sink) {
			this.busy = true
			this.error = null
			this.notice = null
			try {
				const response = await axios.post(
					generateUrl(`/apps/keepiq/api/v1/siem/sinks/${sink.id}/test`),
				)
				if (response.data?.ok) {
					this.notice = t('keepiq', 'Test event delivered to "{name}".', {
						name: sink.name,
					})
				} else {
					this.error = t(
						'keepiq',
						'Test delivery to "{name}" failed: {error}',
						{
							name: sink.name,
							error:
								response.data?.error || t('keepiq', 'unknown error'),
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
		 * @spec openspec/specs/siem-audit-export/spec.md#requirement-admin-configured-syslog-and-webhook-sinks
		 */
		async onDelete(sink) {
			this.busy = true
			this.error = null
			try {
				await axios.delete(
					generateUrl(`/apps/keepiq/api/v1/siem/sinks/${sink.id}`),
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
		 * @spec openspec/specs/siem-audit-export/spec.md#requirement-backpressure-and-observability
		 */
		statusLabel(sink) {
			if (!sink.lastDeliveryStatus) {
				return t('keepiq', 'never attempted')
			}
			return sink.lastDeliveryStatus
		},

		/**
		 * Status CSS class.
		 *
		 * @param {object} sink The sink row.
		 * @return {string}
		 *
		 * @spec exclude Presentation-only: maps a sink delivery status to a CSS class.
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
		 *
		 * @spec exclude Presentation-only formatter: renders an ISO timestamp as a locale string.
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
