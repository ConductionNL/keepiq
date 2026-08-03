<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Anonymous ephemeral-send access page (ephemeral-send §5.3): reads the
  token from the route and the content key from the fragment query (or
  prompts for the password), decrypts client-side, renders the payload
  once with a burn notice. Exempt from the lock guard (PUBLIC_ROUTE_NAMES).

  @spec openspec/changes/ephemeral-send/specs/ephemeral-send/spec.md#requirement-anonymous-access
-->
<template>
	<div class="send-access" data-testid="send-access-page">
		<h2>{{ t('doriath', 'Someone sent you a secure message') }}</h2>

		<NcNoteCard v-if="error" type="error" data-testid="send-access-error">
			{{ error }}
		</NcNoteCard>

		<template v-if="payload === null && !gone">
			<label v-if="needsPassword" class="send-access__field">
				<span>{{ t('doriath', 'Password') }}</span>
				<input v-model="password"
					type="password"
					data-testid="send-access-password"
					@keyup.enter="onOpen">
			</label>
			<NcButton variant="primary"
				:disabled="busy || (needsPassword && password === '')"
				data-testid="send-access-open"
				@click="onOpen">
				{{ t('doriath', 'Reveal the message') }}
			</NcButton>
			<p class="send-access__hint">
				{{ t('doriath', 'Revealing counts as a view — the message may burn afterwards.') }}
			</p>
		</template>

		<template v-else-if="payload !== null">
			<NcNoteCard type="warning" data-testid="send-burn-notice">
				{{ burned
					? t('doriath', 'This was the last view — the message has now been destroyed. Save it before leaving this page.')
					: t('doriath', 'Save this content now — it will not be retrievable once its views run out.') }}
			</NcNoteCard>
			<pre class="send-access__payload" data-testid="send-access-payload">{{ payload }}</pre>
			<NcButton variant="secondary" data-testid="send-access-copy" @click="copyPayload">
				{{ t('doriath', 'Copy content') }}
			</NcButton>
		</template>

		<template v-else>
			<NcEmptyContent :name="t('doriath', 'This send is gone')"
				:description="t('doriath', 'It was burned, expired, or never existed.')" />
		</template>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcNoteCard } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { useEphemeralSendStore } from '../store/modules/ephemeralSend.js'

export default {
	name: 'EphemeralSendAccess',
	components: { NcButton, NcEmptyContent, NcNoteCard },
	data() {
		return {
			needsPassword: false,
			password: '',
			payload: null,
			burned: false,
			gone: false,
			busy: false,
			error: null,
		}
	},
	computed: {
		token() {
			return this.$route.params.token || ''
		},
		fragmentKey() {
			return this.$route.query.k || ''
		},
	},
	/**
	 * Peek the send so the page knows whether to prompt for a password.
	 */
	async mounted() {
		try {
			const response = await axios.get(
				generateUrl(`/apps/doriath/api/v1/public/sends/${encodeURIComponent(this.token)}`),
			)
			this.needsPassword = response.data?.hasPassword === true
		} catch {
			this.gone = true
		}
	},
	methods: {
		/**
		 * Fetch, decrypt, and confirm the view.
		 *
		 * @return {Promise<void>}
		 */
		async onOpen() {
			this.busy = true
			this.error = null
			try {
				const result = await useEphemeralSendStore().accessSend(this.token, this.fragmentKey, this.password)
				this.payload = result.payload
				this.burned = result.burned
			} catch (e) {
				if (e?.message === 'wrong-password') {
					this.error = e.burned
						? this.t('doriath', 'Too many wrong passwords — the message has been destroyed.')
						: this.t('doriath', 'Wrong password — {count} attempt(s) left before the message is destroyed.', { count: e.attemptsLeft })
					if (e.burned) {
						this.gone = true
					}
				} else if (e?.response?.status === 404) {
					this.gone = true
				} else {
					this.error = e?.response?.data?.message || e?.message || 'Failed to open the message'
				}
			} finally {
				this.busy = false
			}
		},

		/**
		 * Copy the revealed payload.
		 *
		 * @return {Promise<void>}
		 */
		async copyPayload() {
			try {
				await navigator.clipboard.writeText(this.payload ?? '')
			} catch {
				// The <pre> stays selectable for manual copy.
			}
		},
	},
}
</script>

<style scoped>
.send-access {
	max-width: 640px;
	margin: 48px auto;
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding: 0 16px;
}

.send-access__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.send-access__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.send-access__payload {
	padding: 16px;
	border: 1px solid var(--color-border, #ddd);
	border-radius: var(--border-radius-large, 12px);
	background-color: var(--color-background-dark, #f5f5f5);
	white-space: pre-wrap;
	word-break: break-word;
}

.send-access__hint {
	color: var(--color-text-maxcontrast, #777);
	font-size: 13px;
}
</style>
