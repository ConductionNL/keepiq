<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  A single secret row in the vault list. Shows the favicon (or type icon),
  name, url, a masked password indicator, and copy/open actions. Decryption is
  on-demand: the masked dots never trigger a decrypt, but the copy button does.
-->
<template>
	<tr class="secret-row" @click="$emit('open', secret.id)">
		<td class="secret-row__name">
			<img
				v-if="faviconUrl"
				:src="faviconUrl"
				class="secret-row__favicon"
				alt=""
				@error="faviconFailed = true">
			<KeyIcon v-else :size="20" />
			<span>{{ secret.name }}</span>
			<span v-if="secret.blocked" class="secret-row__blocked">
				{{ t('doriath', 'Locked') }}
			</span>
		</td>
		<td class="secret-row__url">
			{{ secret.url || '—' }}
		</td>
		<td class="secret-row__password">
			<span v-if="secret.blocked">••••••••</span>
			<span v-else>••••••••</span>
		</td>
		<td class="secret-row__actions" @click.stop>
			<CopyButton v-if="!secret.blocked" :value="copyKey" />
		</td>
	</tr>
</template>

<script>
import KeyIcon from 'vue-material-design-icons/Key.vue'
import CopyButton from './CopyButton.vue'
import { resolveFaviconUrl } from '../utils/favicon.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'SecretListItem',

	components: {
		KeyIcon,
		CopyButton,
	},

	props: {
		/** The secret (metadata + ciphertext blobs). */
		secret: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			faviconFailed: false,
		}
	},

	computed: {
		/**
		 * The favicon URL, or null to fall back to the type icon.
		 *
		 * @return {string|null} The favicon URL.
		 */
		faviconUrl() {
			if (this.faviconFailed) {
				return null
			}
			return resolveFaviconUrl(this.secret.url)
		},
	},

	methods: {
		/**
		 * Decrypt the key on demand for the copy button.
		 *
		 * @return {Promise<string|null>} The decrypted key.
		 */
		async copyKey() {
			const store = useSecretStore()
			return store.decryptField(this.secret.key)
		},
	},
}
</script>

<style scoped>
.secret-row {
	cursor: pointer;
}

.secret-row:hover {
	background-color: var(--color-background-hover);
}

.secret-row td {
	padding: 0.5rem;
	border-bottom: 1px solid var(--color-border);
}

.secret-row__name {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.secret-row__favicon {
	width: 20px;
	height: 20px;
}

.secret-row__blocked {
	font-size: 0.8em;
	color: var(--color-warning);
}

.secret-row__url {
	color: var(--color-text-maxcontrast);
}
</style>
