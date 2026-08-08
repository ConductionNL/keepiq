<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  New ephemeral-send dialog (ephemeral-send §5.1): payload, type,
  max-views, optional TTL and password. Encrypts AES-256-GCM in the
  browser and shows the assembled one-time link exactly once.

  @spec openspec/changes/ephemeral-send/specs/ephemeral-send/spec.md#requirement-create-and-store-ciphertext-only
-->
<template>
	<NcDialog :name="t('doriath', 'New ephemeral send')"
		:open="open"
		size="normal"
		data-testid="new-send-dialog"
		@update:open="$emit('close')">
		<div class="new-send">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<template v-if="link === ''">
				<label class="new-send__field">
					<span>{{ t('doriath', 'Content to send') }}</span>
					<textarea v-model="payload"
						rows="4"
						data-testid="send-payload" />
				</label>
				<NcSelect v-model="payloadType"
					:options="['text', 'credential']"
					:input-label="t('doriath', 'Type')"
					:clearable="false"
					data-testid="send-type" />
				<label class="new-send__field">
					<span>{{ t('doriath', 'Maximum views (burns after)') }}</span>
					<input v-model.number="maxViews"
						type="number"
						min="1"
						max="100"
						data-testid="send-max-views">
				</label>
				<label class="new-send__field">
					<span>{{ t('doriath', 'Expires after (hours, 0 = no time limit, max 720)') }}</span>
					<input v-model.number="ttlHours"
						type="number"
						min="0"
						max="720"
						data-testid="send-ttl">
				</label>
				<label class="new-send__field">
					<span>{{ t('doriath', 'Password (optional — without one, the key travels in the link itself)') }}</span>
					<!--
						autocomplete="new-password": the author is minting a
						fresh passphrase to protect this send, so suppress
						autofill of the account password and let a manager offer
						to generate one. It is not the user's own credential, so
						it is never `current-password`.
					-->
					<input v-model="password"
						type="password"
						autocomplete="new-password"
						data-testid="send-password">
				</label>
			</template>

			<template v-else>
				<NcNoteCard type="warning" data-testid="send-link-once">
					{{ t('doriath', 'Copy this link now — it is shown only once. The content burns after {views} view(s).', { views: maxViews }) }}
				</NcNoteCard>
				<div class="new-send__link">
					<input :value="link"
						readonly
						data-testid="send-link"
						@focus="$event.target.select()">
					<NcButton variant="secondary" data-testid="send-copy-link" @click="copyLink">
						{{ t('doriath', 'Copy link') }}
					</NcButton>
				</div>
			</template>
		</div>
		<template #actions>
			<NcButton variant="tertiary" @click="$emit('close')">
				{{ t('doriath', 'Close') }}
			</NcButton>
			<NcButton v-if="link === ''"
				variant="primary"
				:disabled="payload === '' || busy"
				data-testid="send-create"
				@click="onCreate">
				{{ t('doriath', 'Create send') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import { useEphemeralSendStore } from '../store/modules/ephemeralSend.js'

export default {
	name: 'NewSendDialog',
	components: {
		NcButton,
		NcDialog,
		NcNoteCard,
		NcSelect,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
	},
	emits: ['close'],
	data() {
		return {
			payload: '',
			payloadType: 'text',
			maxViews: 1,
			ttlHours: 24,
			password: '',
			link: '',
			busy: false,
			error: null,
		}
	},
	methods: {
		/**
		 * Encrypt + create, then show the link once.
		 *
		 * @return {Promise<void>}
		 */
		async onCreate() {
			this.busy = true
			this.error = null
			try {
				this.link = await useEphemeralSendStore().createSend({
					payload: this.payload,
					payloadType: this.payloadType,
					maxViews: this.maxViews,
					ttlSeconds: (this.ttlHours || 0) * 3600,
					password: this.password,
				})
				// The plaintext is no longer needed in component state.
				this.payload = ''
				this.password = ''
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || 'Failed to create send'
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the one-time link to the clipboard.
		 *
		 * @return {Promise<void>}
		 */
		async copyLink() {
			try {
				await navigator.clipboard.writeText(this.link)
			} catch {
				// The input remains selectable for manual copy.
			}
		},
	},
}
</script>

<style scoped>
.new-send {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 12px 12px;
}

.new-send__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.new-send__field textarea,
.new-send__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.new-send__link {
	display: flex;
	gap: 8px;
}

.new-send__link input {
	flex: 1;
	padding: 8px;
	font-family: monospace;
	font-size: 12px;
}
</style>
