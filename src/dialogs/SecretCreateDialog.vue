<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-secret dialog. Collects name + type + value (+ optional URL / login /
  folder) and creates the secret via the secret store, which RSA-encrypts the
  sensitive fields client-side before the POST (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog :name="t('doriath', 'New secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="locked" type="warning">
				{{ t('doriath', 'Unlock the vault before creating a secret.') }}
			</NcNoteCard>

			<NcTextField :value.sync="name"
				:label="t('doriath', 'Name')"
				:required="true" />

			<NcSelect v-model="typeId"
				:options="typeOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Type')"
				:clearable="false" />

			<!-- Card / identity composite payloads (card-identity-items §3.1):
			     per-type field sets serialized to the encrypted key on save. -->
			<template v-if="isCard">
				<NcPasswordField :value.sync="card.number"
					:label="t('doriath', 'Card number')"
					data-testid="card-number" />
				<NcNoteCard v-if="card.number !== '' && !luhnOk" type="warning" data-testid="card-luhn-hint">
					{{ t('doriath', 'This number does not pass the card checksum — double-check it (saving is not blocked).') }}
				</NcNoteCard>
				<NcTextField :value.sync="card.expiry"
					:label="t('doriath', 'Expiry (MM/YY)')"
					data-testid="card-expiry" />
				<NcPasswordField :value.sync="card.cvv"
					:label="t('doriath', 'CVV')"
					data-testid="card-cvv" />
				<NcPasswordField :value.sync="card.pin"
					:label="t('doriath', 'PIN (optional)')"
					data-testid="card-pin" />
				<NcTextField :value.sync="card.cardholder"
					:label="t('doriath', 'Cardholder name')"
					data-testid="card-cardholder" />
			</template>
			<template v-else-if="isIdentity">
				<NcTextField :value.sync="identity.firstName" :label="t('doriath', 'First name')" data-testid="identity-first-name" />
				<NcTextField :value.sync="identity.lastName" :label="t('doriath', 'Last name')" data-testid="identity-last-name" />
				<NcTextField :value.sync="identity.address" :label="t('doriath', 'Address')" data-testid="identity-address" />
				<NcTextField :value.sync="identity.phone" :label="t('doriath', 'Phone')" data-testid="identity-phone" />
				<NcTextField :value.sync="identity.email" :label="t('doriath', 'Email')" data-testid="identity-email" />
				<NcPasswordField :value.sync="identity.bsn"
					:label="t('doriath', 'BSN')"
					data-testid="identity-bsn" />
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

			<NcSelect v-model="selectedFolderId"
				:options="folderOptions"
				:reduce="opt => opt.value"
				:input-label="t('doriath', 'Folder')"
				:clearable="false" />

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
					<Plus v-else :size="20" />
				</template>
				{{ t('doriath', 'Create secret') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect, NcTextField } from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import Dice5 from 'vue-material-design-icons/Dice5.vue'
import KeyGeneratorModal from './KeyGeneratorModal.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSessionStore } from '../store/modules/session.js'
import {
	CARD_TYPE_NAME,
	IDENTITY_TYPE_NAME,
	serializeCard,
	serializeIdentity,
	luhnValid,
} from '../cardIdentity/cardIdentity.js'
import { fetchPolicy, evaluateScore, evaluateHibp } from '../policy/policy.js'

/**
 * Create a secret. The value (and optional login) are RSA-encrypted by the
 * store using the suite certificate before the request leaves the browser.
 * Emits `saved` with the created secret on success and `close` on dismiss.
 */
export default {
	name: 'SecretCreateDialog',

	components: {
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		NcTextField,
		Plus,
		Dice5,
		KeyGeneratorModal,
	},

	props: {
		/** The folder to create the secret in (defaults to the current view). */
		folderId: {
			type: String,
			default: null,
		},
		/** Optional callback fired with the created secret after success. */
		onSaved: {
			type: Function,
			default: null,
		},
	},

	data() {
		return {
			open: true,
			name: '',
			typeId: null,
			value: '',
			url: '',
			login: '',
			selectedFolderId: this.folderId,
			saving: false,
			error: '',
			generatorOpen: false,
			card: { number: '', expiry: '', cvv: '', pin: '', cardholder: '' },
			identity: { firstName: '', lastName: '', address: '', phone: '', email: '', bsn: '' },
			policy: null,
		}
	},

	computed: {
		locked() {
			return useSessionStore().isLocked
		},
		typeOptions() {
			return useSecretTypeStore().types.map(type => ({
				value: type.id,
				label: type.label || type.name,
			}))
		},
		folderOptions() {
			const roots = [{ value: null, label: t('doriath', 'Vault root') }]
			return roots.concat(
				useFolderStore().folders.map(folder => ({
					value: folder.id,
					label: folder.name,
				})),
			)
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
		/** Best-effort Luhn hint — never blocks saving (§3.2). */
		luhnOk() {
			return luhnValid(this.card.number)
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
		 * Org-policy score gate on the manual value (org-password-policies
		 * §4.2): exempt types skip; the reason renders and submit stays
		 * disabled until compliant. Never POSTs a non-compliant value.
		 *
		 * @return {{compliant: boolean, reason: string|null}}
		 */
		policyVerdict() {
			if (this.isCard || this.isIdentity) {
				return { compliant: true, reason: null }
			}
			return evaluateScore(this.policy, this.selectedTypeName, this.value)
		},
		canSubmit() {
			if (this.saving || this.locked || this.name.trim() === '') {
				return false
			}
			if (this.isCard) {
				return this.card.number !== ''
			}
			if (this.isIdentity) {
				return Object.values(this.identity).some(v => v !== '')
			}
			return this.value !== '' && this.policyVerdict.compliant
		},
	},

	async mounted() {
		this.policy = await fetchPolicy()
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		if (this.typeId === null && typeStore.types.length > 0) {
			const login = typeStore.types.find(type => type.name === 'login')
			this.typeId = login ? login.id : typeStore.types[0].id
		}
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders()
		}
	},

	methods: {
		t,

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
		 * Encrypt (in the store) and create the secret.
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
				// HIBP block (org-password-policies §4.2): k-anonymity check
				// BEFORE encryption; only the 5-char prefix leaves the browser.
				if (!this.isCard && !this.isIdentity) {
					const hibpReason = await evaluateHibp(this.policy, this.selectedTypeName, this.value)
					if (hibpReason !== null) {
						this.error = hibpReason
						this.saving = false
						return
					}
				}
				const created = await useSecretStore().createSecret({
					name: this.name.trim(),
					typeId: this.typeId,
					folderId: this.selectedFolderId,
					url: this.url || null,
					login: this.login || '',
					key: this.effectiveValue,
				})
				this.$emit('saved', created)
				if (this.onSaved) {
					this.onSaved(created)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || t('doriath', 'Failed to create secret')
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

.secret-form__value-row {
	display: flex;
	align-items: flex-end;
	gap: 8px;
}

.secret-form__value-field {
	flex: 1 1 auto;
}
</style>
