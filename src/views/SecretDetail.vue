<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Secret detail view. Loads a single secret, decrypts its fields with the
  in-memory session key, and renders a type-specific field layout. Registered
  as a custom page (`kind: "page"`) in src/registry.js. Deep-linked from the
  unified-search provider via the lock screen's returnUrl.
-->
<template>
	<NcAppContent>
		<div class="secret-detail">
			<NcLoadingIcon v-if="loading" :size="44" class="secret-detail__loading" />

			<NcEmptyContent
				v-else-if="error"
				:name="t('doriath', 'Unable to open secret')"
				:description="error">
				<template #icon>
					<LockIcon :size="64" />
				</template>
			</NcEmptyContent>

			<div v-else-if="secret" class="secret-detail__body">
				<h2 class="secret-detail__title">
					{{ secret.name }}
				</h2>

				<div v-if="secret.url" class="secret-detail__field">
					<label>{{ t('doriath', 'URL') }}</label>
					<a :href="secret.url" target="_blank" rel="noopener noreferrer">{{ secret.url }}</a>
				</div>

				<div v-if="secret.login" class="secret-detail__field">
					<label>{{ t('doriath', 'Login') }}</label>
					<div class="secret-detail__field-value">
						<span>{{ secret.login }}</span>
						<CopyButton :value="secret.login" />
					</div>
				</div>

				<div class="secret-detail__field">
					<PasswordField
						:label="keyLabel"
						:value="secret.key || ''" />
					<CopyButton :value="secret.key || ''" />
				</div>

				<div v-if="additionalEntries.length" class="secret-detail__field">
					<label>{{ t('doriath', 'Additional fields') }}</label>
					<div v-for="entry in additionalEntries" :key="entry.key" class="secret-detail__additional">
						<span class="secret-detail__additional-key">{{ entry.key }}</span>
						<span>{{ entry.value }}</span>
					</div>
				</div>

				<div class="secret-detail__actions">
					<NcButton type="error" @click="remove">
						{{ t('doriath', 'Delete secret') }}
					</NcButton>
				</div>
			</div>
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcButton, NcEmptyContent, NcLoadingIcon } from '@nextcloud/vue'
import LockIcon from 'vue-material-design-icons/Lock.vue'
import PasswordField from '../components/PasswordField.vue'
import CopyButton from '../components/CopyButton.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

export default {
	name: 'SecretDetail',

	components: {
		NcAppContent,
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		LockIcon,
		PasswordField,
		CopyButton,
	},

	props: {
		/** The secret ID from the route. */
		id: {
			type: String,
			required: true,
		},
	},

	data() {
		return {
			secret: null,
			loading: true,
			error: null,
		}
	},

	computed: {
		/**
		 * The decrypted additional-fields object as key/value entries.
		 *
		 * @return {Array} The entries.
		 */
		additionalEntries() {
			if (!this.secret || !this.secret.additionalFields) {
				return []
			}
			return Object.entries(this.secret.additionalFields).map(([key, value]) => ({ key, value }))
		},
		/**
		 * The label for the key field, type-specific where possible.
		 *
		 * @return {string} The key label.
		 */
		keyLabel() {
			const typeStore = useSecretTypeStore()
			const type = typeStore.types.find(t => t.id === this.secret?.typeId)
			if (type && type.name === 'note') {
				return t('doriath', 'Note')
			}
			if (type && type.name === 'ssh_key') {
				return t('doriath', 'Private key')
			}
			return t('doriath', 'Password')
		},
	},

	created() {
		this.load()
	},

	methods: {
		/**
		 * Load and decrypt the secret.
		 */
		async load() {
			this.loading = true
			this.error = null
			try {
				const store = useSecretStore()
				this.secret = await store.fetchSecret(this.id)
			} catch (e) {
				this.error = e.response?.data?.message || e.message || t('doriath', 'Failed to load secret')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Delete the secret and return to the list.
		 */
		async remove() {
			const store = useSecretStore()
			await store.deleteSecret(this.id)
			this.$router.push({ name: 'SecretList' })
		},
	},
}
</script>

<style scoped>
.secret-detail {
	padding: 1rem;
	max-width: 640px;
}

.secret-detail__field {
	margin-bottom: 1rem;
	display: flex;
	align-items: flex-end;
	gap: 0.5rem;
}

.secret-detail__field label {
	display: block;
	color: var(--color-text-maxcontrast);
	margin-bottom: 0.25rem;
}

.secret-detail__field-value {
	display: flex;
	align-items: center;
	gap: 0.5rem;
}

.secret-detail__additional {
	display: flex;
	gap: 0.5rem;
}

.secret-detail__additional-key {
	font-weight: bold;
}

.secret-detail__loading {
	margin: 3rem auto;
}
</style>
