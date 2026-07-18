<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Edit-secret dialog. Loads + decrypts the secret, lets the user change name /
  type / URL / login / value, and PUTs only the changed fields via the store.
  Sensitive fields (value / login) are re-encrypted client-side; metadata-only
  edits skip encryption (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog :name="t('doriath', 'Edit secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<NcLoadingIcon v-if="loading" :size="32" class="secret-form__loading" />

		<div v-else class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcTextField :value.sync="name"
				:label="t('doriath', 'Name')"
				:required="true" />

			<NcSelect v-model="typeId"
				:options="typeOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Type')"
				:clearable="false" />

			<!-- Card / identity composite payloads (card-identity-items §3.1). -->
			<template v-if="isCard">
				<NcPasswordField :value.sync="card.number" :label="t('doriath', 'Card number')" data-testid="card-number" />
				<NcTextField :value.sync="card.expiry" :label="t('doriath', 'Expiry (MM/YY)')" data-testid="card-expiry" />
				<NcPasswordField :value.sync="card.cvv" :label="t('doriath', 'CVV')" data-testid="card-cvv" />
				<NcPasswordField :value.sync="card.pin" :label="t('doriath', 'PIN (optional)')" data-testid="card-pin" />
				<NcTextField :value.sync="card.cardholder" :label="t('doriath', 'Cardholder name')" data-testid="card-cardholder" />
			</template>
			<template v-else-if="isIdentity">
				<NcTextField :value.sync="identity.firstName" :label="t('doriath', 'First name')" data-testid="identity-first-name" />
				<NcTextField :value.sync="identity.lastName" :label="t('doriath', 'Last name')" data-testid="identity-last-name" />
				<NcTextField :value.sync="identity.address" :label="t('doriath', 'Address')" data-testid="identity-address" />
				<NcTextField :value.sync="identity.phone" :label="t('doriath', 'Phone')" data-testid="identity-phone" />
				<NcTextField :value.sync="identity.email" :label="t('doriath', 'Email')" data-testid="identity-email" />
				<NcPasswordField :value.sync="identity.bsn" :label="t('doriath', 'BSN')" data-testid="identity-bsn" />
			</template>
			<div v-else class="secret-form__value-row">
				<NcPasswordField :value.sync="value"
					class="secret-form__value-field"
					:label="valueLabel" />
				<NcButton type="tertiary-no-background"
					:title="t('doriath', 'Generate a strong key')"
					:aria-label="t('doriath', 'Generate a strong key')"
					@click="openGenerator">
					<template #icon>
						<Dice5 :size="20" />
					</template>
				</NcButton>
			</div>

			<KeyGeneratorModal v-if="generatorOpen"
				:open="generatorOpen"
				@update:open="generatorOpen = $event"
				@generated="onGenerated" />

			<NcTextField :value.sync="url"
				:label="t('doriath', 'URL (optional)')" />

			<NcTextField :value.sync="login"
				:label="t('doriath', 'Login (optional)')" />

			<NcNoteCard v-if="!policyVerdict.compliant" type="warning" data-testid="policy-blocked">
				{{ policyVerdict.reason }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton type="tertiary" @click="onUpdateOpen(false)">
				{{ t('doriath', 'Cancel') }}
			</NcButton>
			<NcButton type="primary"
				:disabled="!canSubmit"
				@click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<ContentSave v-else :size="20" />
				</template>
				{{ t('doriath', 'Save') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import ContentSave from 'vue-material-design-icons/ContentSave.vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import KeyGeneratorModal from './KeyGeneratorModal.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import {
	CARD_TYPE_NAME,
	IDENTITY_TYPE_NAME,
	CARD_FIELDS,
	IDENTITY_FIELDS,
	serializeCard,
	serializeIdentity,
	parsePayload,
} from '../cardIdentity/cardIdentity.js'
import { fetchPolicy, evaluateScore, evaluateHibp } from '../policy/policy.js'

/**
 * Edit a secret. Loads + decrypts on mount; on save sends only changed fields,
 * re-encrypting sensitive ones via the store. Emits `saved` on success and
 * `close` on dismiss.
 */
export default {
	name: 'SecretEditDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
		ContentSave,
		Dice5,
		KeyGeneratorModal,
	},

	props: {
		/** The ID of the secret to edit. */
		secretId: {
			type: String,
			required: true,
		},
		/** Optional callback fired with the updated secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			loading: true,
			saving: false,
			error: '',
			original: null,
			name: '',
			typeId: null,
			value: '',
			url: '',
			login: '',
			generatorOpen: false,
			card: { number: '', expiry: '', cvv: '', pin: '', cardholder: '' },
			identity: { firstName: '', lastName: '', address: '', phone: '', email: '', bsn: '' },
			policy: null,
		}
	},

	computed: {
		typeOptions() {
			return useSecretTypeStore().types.map(type => ({
				value: type.id,
				label: type.label || type.name,
			}))
		},
		valueLabel() {
			const type = useSecretTypeStore().typesById[this.typeId]
			return type && type.name === 'note'
				? t('doriath', 'Note')
				: t('doriath', 'Secret value')
		},
		/** The selected type's system name (card-identity-items §3.1). */
		selectedTypeName() {
			return useSecretTypeStore().typesById[this.typeId]?.name ?? ''
		},
		isCard() {
			return this.selectedTypeName === CARD_TYPE_NAME
		},
		isIdentity() {
			return this.selectedTypeName === IDENTITY_TYPE_NAME
		},
		/** The value serialized for the encrypted key field. */
		effectiveValue() {
			if (this.isCard) {
				return serializeCard(this.card)
			}
			if (this.isIdentity) {
				return serializeIdentity(this.identity)
			}
			return this.value
		},
		/**
		 * Org-policy score gate on a CHANGED manual value (§4.2) — an
		 * unchanged value is never re-gated.
		 *
		 * @return {{compliant: boolean, reason: string|null}}
		 */
		policyVerdict() {
			if (this.isCard || this.isIdentity || this.value === (this.original?.key || '')) {
				return { compliant: true, reason: null }
			}
			return evaluateScore(this.policy, this.selectedTypeName, this.value)
		},
		canSubmit() {
			return !this.loading && !this.saving && this.name.trim() !== '' && this.policyVerdict.compliant
		},
	},

	async mounted() {
		this.policy = await fetchPolicy()
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load + decrypt the secret and seed the form fields.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				const secret = await useSecretStore().fetchSecret(this.secretId)
				this.original = secret
				this.name = secret.name || ''
				this.typeId = secret.typeId || null
				this.value = secret.key || ''
				this.url = secret.url || ''
				this.login = secret.login || ''

				// Seed the per-type composite fields from the decrypted
				// payload (card-identity-items §3.1); a legacy plain value
				// falls back to the generic field untouched.
				const payload = parsePayload(secret.key || '')
				if (payload !== null && this.isCard) {
					for (const field of CARD_FIELDS) {
						this.card[field] = payload[field] != null ? String(payload[field]) : ''
					}
				}
				if (payload !== null && this.isIdentity) {
					for (const field of IDENTITY_FIELDS) {
						this.identity[field] = payload[field] != null ? String(payload[field]) : ''
					}
				}
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to load secret')
			} finally {
				this.loading = false
			}
		},

		/**
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Open the key generator dialog.
		 *
		 * @return {void}
		 */
		openGenerator() {
			this.generatorOpen = true
		},

		/**
		 * Receive the generated key from the modal and copy it into the value field.
		 *
		 * @param {string} key The generated key.
		 * @return {void}
		 */
		onGenerated(key) {
			if (typeof key === 'string' && key.length > 0) {
				this.value = key
			}
		},

		/**
		 * Compute the changed-fields diff and PUT it via the store.
		 *
		 * @return {Promise<void>}
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				// HIBP block on a changed manual value (§4.2) — before encryption.
				if (!this.isCard && !this.isIdentity && this.value !== (this.original?.key || '')) {
					const hibpReason = await evaluateHibp(this.policy, this.selectedTypeName, this.value)
					if (hibpReason !== null) {
						this.error = hibpReason
						this.saving = false
						return
					}
				}
				const diff = {}
				const o = this.original
				if (this.name.trim() !== (o.name || '')) {
					diff.name = this.name.trim()
				}
				if ((this.typeId || null) !== (o.typeId || null)) {
					diff.typeId = this.typeId
				}
				if ((this.url || '') !== (o.url || '')) {
					diff.url = this.url || null
				}
				if ((this.effectiveValue || '') !== (o.key || '')) {
					diff.key = this.effectiveValue
				}
				if ((this.login || '') !== (o.login || '')) {
					diff.login = this.login
				}

				let updated = this.original
				if (Object.keys(diff).length > 0) {
					updated = await useSecretStore().updateSecret(this.secretId, diff)
				}
				this.$emit('saved', updated)
				if (this.onSaved) {
					this.onSaved(updated)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to save secret')
			} finally {
				this.saving = false
			}
		},
	},
}
</script>

<style scoped>
.secret-form {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 4px 0;
}

.secret-form__loading {
	display: flex;
	justify-content: center;
	margin: 24px 0;
}

.secret-form__value-row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.secret-form__value-field {
	flex: 1 1 auto;
}
</style>
