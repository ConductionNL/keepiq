<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  My-sends list (ephemeral-send §5.2): the caller's active sends with
  remaining views / expiry and one-click revoke. Metadata only — the
  ciphertext and any key material never appear here.

  @spec openspec/specs/ephemeral-send/spec.md#requirement-manage-and-revoke-sends
-->
<template>
	<NcDialog
		:name="t('keepiq', 'My ephemeral sends')"
		:open="open"
		size="normal"
		data-testid="my-sends-dialog"
		@update:open="$emit('close')">
		<div class="my-sends">
			<p v-if="store.sends.length === 0" class="my-sends__empty">
				{{ t('keepiq', 'No active sends.') }}
			</p>
			<table v-else class="my-sends__table" data-testid="my-sends-table">
				<thead>
					<tr>
						<th scope="col">
							{{ t('keepiq', 'Type') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Views left') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Expires') }}
						</th>
						<th scope="col">
							{{ t('keepiq', 'Password') }}
						</th>
						<th scope="col" />
					</tr>
				</thead>
				<tbody>
					<tr
						v-for="send in store.sends"
						:key="send.id"
						:data-testid="`send-row-${send.id}`">
						<td>{{ send.payloadType }}</td>
						<td>{{ send.remainingViews }}</td>
						<td>
							{{
								send.expiresAt
									? formatDate(send.expiresAt)
									: t('keepiq', 'never')
							}}
						</td>
						<td>
							{{
								send.hasPassword
									? t('keepiq', 'yes')
									: t('keepiq', 'no')
							}}
						</td>
						<td>
							<NcButton
								variant="tertiary"
								:data-testid="`send-revoke-${send.id}`"
								@click="onRevoke(send.id)">
								{{ t('keepiq', 'Revoke') }}
							</NcButton>
						</td>
					</tr>
				</tbody>
			</table>
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('keepiq', 'Close') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog } from '@nextcloud/vue'
import { useEphemeralSendStore } from '../store/modules/ephemeralSend.js'

export default {
	name: 'MySendsDialog',
	components: { NcButton, NcDialog },
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},

	emits: ['close'],
	computed: {
		store() {
			return useEphemeralSendStore()
		},
	},

	async mounted() {
		try {
			await this.store.fetchSends()
		} catch {
			// Surfaced via store state.
		}
	},

	methods: {
		/**
		 * Revoke one send.
		 *
		 * @param {string} id The send id.
		 * @return {Promise<void>}
		 */
		async onRevoke(id) {
			try {
				await this.store.revoke(id)
			} catch {
				// Surfaced via store state.
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
			return Number.isNaN(parsed)
				? (iso ?? '')
				: new Date(parsed).toLocaleString()
		},
	},
}
</script>

<style scoped>
.my-sends {
	padding: 4px 12px 12px;
}

.my-sends__table {
	width: 100%;
	border-collapse: collapse;
}

.my-sends__table th,
.my-sends__table td {
	text-align: start;
	padding: 4px 8px;
	border-bottom: 1px solid var(--color-border, #ddd);
}

.my-sends__empty {
	color: var(--color-text-maxcontrast, #777);
}
</style>
