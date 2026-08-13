<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Honey (decoy) panel on the secret detail (honey-credentials §5.1/§5.2,
  owner/admin only): toggle the tripwire flag with a placement note, and
  review this decoy's alerts with acknowledge + per-accessor snooze.
  Recipients never see this panel — the flag lives in a side table and
  is absent from every secret response.

  @spec openspec/specs/honey-credentials/spec.md#requirement-honey-flag-is-owner-admin-only-and-invisible-to-others
-->
<template>
	<div class="honey-panel" data-testid="honey-panel">
		<NcNoteCard v-if="store.error" type="error">
			{{ store.error }}
		</NcNoteCard>

		<!-- v9 renamed `checked` -> `modelValue`. Deliberately NOT v-model: the
		     handler is async and persists server-side, so the prop stays
		     one-way and the store remains the source of truth. -->
		<NcCheckboxRadioSwitch
			:model-value="flagged"
			type="switch"
			data-testid="honey-toggle"
			@update:model-value="onToggle">
			{{
				t(
					'doriath',
					'Honey tripwire — page me when anyone accesses this secret',
				)
			}}
		</NcCheckboxRadioSwitch>

		<template v-if="flagged">
			<NcTextField
				v-model="note"
				:label="
					t('doriath', 'Placement note (only you and admins see this)')
				"
				data-testid="honey-note"
				@blur="saveNote" />

			<p class="honey-panel__hint">
				{{
					t(
						'doriath',
						'Every access via the app, machine API, link, or a shared copy raises an alert to you and the admins. The decoy is indistinguishable from a real secret.',
					)
				}}
			</p>

			<table
				v-if="secretAlerts.length"
				class="honey-panel__table"
				data-testid="honey-alerts">
				<thead>
					<tr>
						<th scope="col">
							{{ t('doriath', 'Accessor') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Channel') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Count') }}
						</th>
						<th scope="col">
							{{ t('doriath', 'Last access') }}
						</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="alert in secretAlerts"
						:key="alert.id"
						:data-testid="`honey-alert-${alert.id}`">
						<td>
							{{ alert.accessorId || t('doriath', 'anonymous') }}
							<div class="honey-panel__muted">
								{{ alert.ip || '' }}
							</div>
						</td>
						<td>{{ alert.channel }}</td>
						<td>{{ alert.accessCount }}</td>
						<td>{{ formatDate(alert.accessedAt) }}</td>
						<td class="honey-panel__actions">
							<NcButton
								v-if="!alert.acknowledgedAt"
								variant="tertiary"
								:data-testid="`honey-ack-${alert.id}`"
								@click="store.acknowledge(alert.id)">
								{{ t('doriath', 'Acknowledge') }}
							</NcButton>
							<span v-else class="honey-panel__muted">{{
								t('doriath', 'handled')
							}}</span>
							<NcButton
								v-if="!isSnoozed(alert)"
								variant="tertiary"
								:data-testid="`honey-snooze-${alert.id}`"
								@click="store.snooze(alert.id)">
								{{ t('doriath', 'Snooze 24h') }}
							</NcButton>
							<span v-else class="honey-panel__muted">{{
								t('doriath', 'snoozed')
							}}</span>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="honey-panel__muted" data-testid="honey-no-alerts">
				{{ t('doriath', 'No accesses recorded — the tripwire is armed.') }}
			</p>
		</template>
	</div>
</template>

<script>
import {
	NcButton,
	NcCheckboxRadioSwitch,
	NcNoteCard,
	NcTextField,
} from '@nextcloud/vue'
import { useHoneyStore } from '../store/modules/honey.js'

export default {
	name: 'HoneyPanel',

	components: {
		NcButton,
		NcCheckboxRadioSwitch,
		NcNoteCard,
		NcTextField,
	},

	props: {
		secretId: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			store: useHoneyStore(),
			note: '',
		}
	},

	computed: {
		flagged() {
			return this.store.status?.flagged === true
		},

		secretAlerts() {
			return this.store.alerts.filter((a) => a.secretId === this.secretId)
		},
	},

	/**
	 * Load the flag state + alerts for this secret.
	 */
	async created() {
		const status = await this.store.fetchStatus(this.secretId)
		this.note = status?.flag?.note || ''
		if (status?.flagged) {
			await this.store.fetchAlerts()
		}
	},

	methods: {
		/**
		 * Toggle the decoy flag.
		 *
		 * @param {boolean} checked The new switch state.
		 * @return {Promise<void>}
		 */
		async onToggle(checked) {
			if (checked) {
				await this.store.flag(this.secretId, this.note)
				await this.store.fetchAlerts()
			} else {
				await this.store.unflag(this.secretId)
			}
		},

		/**
		 * Persist the placement note (re-flag upserts it).
		 *
		 * @return {Promise<void>}
		 */
		async saveNote() {
			if (this.flagged) {
				await this.store.flag(this.secretId, this.note)
			}
		},

		/**
		 * Whether the alert's accessor is currently snoozed.
		 *
		 * @param {object} alert The alert row.
		 * @return {boolean}
		 */
		isSnoozed(alert) {
			return (
				!!alert.snoozedUntil
				&& new Date(alert.snoozedUntil).getTime() > Date.now()
			)
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
.honey-panel {
	display: flex;
	flex-direction: column;
	gap: 8px;

	&__hint,
	&__muted {
		color: var(--color-text-maxcontrast);
	}

	&__table {
		width: 100%;
		border-collapse: collapse;

		th,
		td {
			text-align: start;
			padding: 4px 8px;
			border-bottom: 1px solid var(--color-border);
			vertical-align: top;
		}
	}

	&__actions {
		display: flex;
		gap: 4px;
	}
}
</style>
