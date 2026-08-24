<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Admin honey-alerts panel (honey-credentials §5.2): instance-wide
  tripwire alerts with accessor, channel, IP/UA, timestamp, and
  acknowledge + per-accessor snooze actions. Alerts carry access
  metadata only — never secret material.

  @spec openspec/specs/honey-credentials/spec.md#requirement-alert-storms-are-rate-limited-and-per-accessor-snoozable
-->
<template>
	<CnSettingsSection
		:name="t('keepiq', 'Honey credentials')"
		:description="
			t(
				'keepiq',
				'Decoy tripwires: instance-wide alerts raised when anyone accesses a honey-flagged secret. Deception is detection — an alert never blocks the access.',
			)
		">
		<div class="honey-admin" data-testid="honey-section">
			<NcNoteCard v-if="store.error" type="error">
				{{ store.error }}
			</NcNoteCard>

			<table
				v-if="store.alerts.length"
				class="honey-admin__table"
				data-testid="honey-admin-alerts">
				<thead>
					<tr>
						<th scope="col">
							{{ t('keepiq', 'Accessor') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Channel') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'IP / agent') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Count') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Last access') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Status') }}
						</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="alert in store.alerts"
						:key="alert.id"
						:data-testid="`honey-admin-alert-${alert.id}`">
						<td>
							{{ alert.accessorType }}:
							{{ alert.accessorId || t('keepiq', 'anonymous') }}
						</td>
						<td>{{ alert.channel }}</td>
						<td class="honey-admin__transport">
							{{ alert.ip || '—' }}
							<div class="honey-admin__muted">
								{{ shortAgent(alert.userAgent) }}
							</div>
						</td>
						<td>{{ alert.accessCount }}</td>
						<td>{{ formatDate(alert.accessedAt) }}</td>
						<td>
							<span
								v-if="alert.acknowledgedAt"
								class="honey-admin__muted">
								{{
									t('keepiq', 'handled by {who}', {
										who: alert.acknowledgedBy,
									})
								}}
							</span>
							<span
								v-else-if="isSnoozed(alert)"
								class="honey-admin__muted"
								>{{ t('keepiq', 'snoozed') }}</span
							>
							<strong v-else class="honey-admin__open">{{
								t('keepiq', 'OPEN')
							}}</strong>
						</td>
						<td class="honey-admin__actions">
							<NcButton
								v-if="!alert.acknowledgedAt"
								variant="tertiary"
								:data-testid="`honey-admin-ack-${alert.id}`"
								@click="store.acknowledge(alert.id)">
								{{ t('keepiq', 'Acknowledge') }}
							</NcButton>
							<NcButton
								v-if="!isSnoozed(alert)"
								variant="tertiary"
								:data-testid="`honey-admin-snooze-${alert.id}`"
								@click="store.snooze(alert.id)">
								{{ t('keepiq', 'Snooze 24h') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
			<p v-else class="honey-admin__muted">
				{{
					t(
						'keepiq',
						"No honey alerts. Owners arm tripwires from a secret's detail page.",
					)
				}}
			</p>
		</div>
	</CnSettingsSection>
</template>

<script>
import { CnSettingsSection } from '@conduction/nextcloud-vue'
import { NcButton, NcNoteCard } from '@nextcloud/vue'
import { useHoneyStore } from '../../store/modules/honey.js'

export default {
	name: 'HoneySection',

	components: {
		CnSettingsSection,
		NcButton,
		NcNoteCard,
	},

	data() {
		return {
			store: useHoneyStore(),
		}
	},

	/**
	 * Load the instance-wide alerts.
	 */
	created() {
		this.store.fetchAlerts()
	},

	methods: {
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
		 * Shorten a user agent for the table.
		 *
		 * @param {string|null} agent The user agent.
		 * @return {string}
		 */
		shortAgent(agent) {
			if (!agent) {
				return ''
			}
			return agent.length > 60 ? agent.slice(0, 60) + '…' : agent
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
.honey-admin {
	max-width: 1000px;

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

	&__transport {
		max-width: 240px;
		overflow-wrap: anywhere;
	}

	&__muted {
		color: var(--color-text-maxcontrast);
		font-size: 0.85em;
	}

	&__open {
		color: var(--color-error-text);
	}

	&__actions {
		display: flex;
		gap: 4px;
	}
}
</style>
