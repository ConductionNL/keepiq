<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Create-secret dialog. Collects name + type + value (+ optional URL / login /
  folder) and creates the secret via the secret store, which RSA-encrypts the
  sensitive fields client-side before the POST (zero-knowledge, ADR-003).
-->
<template>
	<NcDialog
		:name="t('keepiq', 'New secret')"
		:open="open"
		size="normal"
		@update:open="onUpdateOpen">
		<div class="secret-form">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>
			<NcNoteCard v-if="locked" type="warning">
				{{ t('keepiq', 'Unlock the vault before creating a secret.') }}
			</NcNoteCard>

			<NcTextField
				v-model="name"
				:label="t('keepiq', 'Name')"
				:required="true" />

			<NcSelect
				v-model="typeId"
				:options="typeOptions"
				:inputLabel="t('keepiq', 'Type')"
				:reduce="(opt) => opt.value"
				:clearable="false" />

			<NcTextField v-model="login" :label="t('keepiq', 'Login (optional)')" />

			<SecretPayloadFields v-bind="payloadFields" v-on="payloadFieldEvents" />

			<AuthenticatorKeyField
				v-if="showAuthenticator"
				v-model:value="totpInput"
				mode="replace"
				:disabled="saving" />

			<NcTextField v-model="url" :label="t('keepiq', 'URL (optional)')" />

			<TypedFieldsForm
				v-if="typedFields.length > 0"
				:fields="typedFields"
				:values="typedValues"
				:missing="typedMissing"
				:disabled="saving"
				@update:values="onTypedValues" />

			<AdditionalFieldsEditor
				:members="additionalFields"
				:disabled="saving"
				@update:members="additionalFields = $event" />

			<SecretTagsField
				:modelValue="tags"
				:disabled="saving"
				@update:modelValue="tags = $event" />

			<!-- The shared destination picker (no "Vault root" option, by
			     design): a secret always lives in a vault, so creating one
			     at the root cannot be offered. Replaces a local NcSelect
			     that still listed the root. -->
			<!-- The team folder ownership policy (admin-vault-policies §4.3):
			     a covered type goes into one of the user's own team folders,
			     or into a team folder they can write to. -->
			<NcNoteCard
				v-if="ownershipApplies"
				type="info"
				data-testid="secret-create-ownership">
				{{
					t(
						'keepiq',
						'Your organisation keeps this type of secret in a team folder. Pick one of your team folders, or one you can write to.',
					)
				}}
			</NcNoteCard>
			<NcSelect
				v-if="ownershipApplies && contributable.length > 0"
				v-model="contributeTo"
				:options="contributable"
				label="folderName"
				:inputLabel="t('keepiq', 'Team folder you can write to')"
				data-testid="secret-create-contribute-to" />
			<DestinationSelect
				v-if="!contributeTo"
				v-model="selectedFolderId"
				mode="folders"
				:onlyIds="ownershipApplies ? ownTeamFolderIds : null"
				:label="t('keepiq', 'Folder')" />

			<NcNoteCard
				v-if="!policyVerdict.compliant"
				type="warning"
				data-testid="policy-blocked">
				{{ policyVerdict.reason }}
			</NcNoteCard>
		</div>

		<template #actions>
			<NcButton variant="tertiary" @click="onUpdateOpen(false)">
				{{ t('keepiq', 'Cancel') }}
			</NcButton>
			<NcButton variant="primary" :disabled="!canSubmit" @click="submit">
				<template #icon>
					<NcLoadingIcon v-if="saving" :size="20" />
					<Plus v-else :size="20" />
				</template>
				{{ t('keepiq', 'Create secret') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import {
	NcButton,
	NcDialog,
	NcLoadingIcon,
	NcNoteCard,
	NcSelect,
	NcTextField,
} from '@nextcloud/vue'
import Plus from 'vue-material-design-icons/Plus.vue'
import AdditionalFieldsEditor from '../components/AdditionalFieldsEditor.vue'
import AuthenticatorKeyField from '../components/AuthenticatorKeyField.vue'
import DestinationSelect from '../components/DestinationSelect.vue'
import SecretPayloadFields from '../components/SecretPayloadFields.vue'
import SecretTagsField from '../components/SecretTagsField.vue'
import TypedFieldsForm from '../components/TypedFieldsForm.vue'
import { secretPayloadForm } from '../mixins/secretPayloadForm.js'
import { evaluateHibp, evaluateScore, fetchPolicy } from '../policy/policy.js'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'
import { useSessionStore } from '../store/modules/session.js'
import { useTeamFolderStore } from '../store/modules/teamFolder.js'
import {
	resolveDefaultTypeId,
	useUserPreferencesStore,
} from '../store/modules/userPreferences.js'
import {
	authenticatorKeyError,
	normaliseAuthenticatorKey,
} from '../totp/authenticatorKey.js'
import { membersToObject } from '../utils/additionalFields.js'
import { isCreatableType, secretTypeLabel } from '../utils/secretTypes.js'
import { mergeTypedValues, missingRequired } from '../utils/typedFields.js'
import { valueFieldsSupported } from '../utils/valueFields.js'

/**
 * Every folder id at or below the given roots.
 *
 * @param {Array<{id: string, parentId: string|null}>} folders The user's folders.
 * @param {Array<string>} roots The root folder ids.
 * @return {Array<string>}
 */
function subtreeIds(folders, roots) {
	const ids = new Set(roots)
	let grew = true
	while (grew) {
		grew = false
		for (const folder of folders) {
			if (folder.parentId && ids.has(folder.parentId) && !ids.has(folder.id)) {
				ids.add(folder.id)
				grew = true
			}
		}
	}
	return [...ids]
}

/**
 * Create a secret. The value (and optional login) are RSA-encrypted by the
 * store using the suite certificate before the request leaves the browser.
 * Emits `saved` with the created secret on success and `close` on dismiss.
 */
export default {
	name: 'SecretCreateDialog',

	components: {
		AdditionalFieldsEditor,
		AuthenticatorKeyField,
		TypedFieldsForm,
		DestinationSelect,
		SecretPayloadFields,
		SecretTagsField,
		NcButton,
		NcDialog,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		NcTextField,
		Plus,
	},

	mixins: [secretPayloadForm],

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
			url: '',
			login: '',
			/** The authenticator key being entered on a login. */
			totpInput: '',
			additionalFields: [],
			tags: [],
			selectedFolderId: this.folderId,
			/** Team folders the user can write to but does not own (admin-vault-policies §4.3). */
			contributable: [],
			/** The chosen contributable team folder, or null. */
			contributeTo: null,
			/** Folder ids inside a team folder the user owns. */
			ownTeamFolderIds: [],
			saving: false,
			error: '',
			policy: null,
		}
	},

	computed: {
		/**
		 * @spec exclude Store-state passthrough: reads the session lock flag for display gating.
		 */
		locked() {
			return useSessionStore().isLocked
		},

		/**
		 * The types new secrets may be created as, as select options with
		 * translated labels. Authenticator and Passkey are left out: a login
		 * carries its own one-time code and passkeys.
		 *
		 * @return {Array<{value: string, label: string}>}
		 * @spec openspec/specs/secrets/spec.md#requirement-secret-types
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		typeOptions() {
			return useSecretTypeStore()
				.types.filter(isCreatableType)
				.map((type) => ({
					value: type.id,
					label: secretTypeLabel(type),
				}))
		},

		/**
		 * Whether the login's authenticator key can be entered: on a login,
		 * when the server stores the field.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		showAuthenticator() {
			return this.selectedTypeName === 'login' && valueFieldsSupported()
		},

		/**
		 * Why the entered authenticator key blocks the save, '' when it does not.
		 *
		 * @return {string}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		totpError() {
			return this.showAuthenticator
				? authenticatorKeyError(this.totpInput)
				: ''
		},

		/**
		 * The authenticator key to save with the login, '' for none.
		 *
		 * @return {string}
		 * @spec openspec/changes/vault-login-totp-codes/specs/login-one-time-codes/spec.md#requirement-a-login-can-carry-its-own-totp-seed
		 */
		totpToSave() {
			return this.showAuthenticator
				? normaliseAuthenticatorKey(this.totpInput)
				: ''
		},

		/**
		 * Whether the team folder ownership policy covers this user and type.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
		 */
		ownershipApplies() {
			return (
				this.policy?.vault_org_ownership === true
				&& (this.policy?.vault_org_ownership_types ?? []).includes(
					this.selectedTypeName,
				)
			)
		},

		/**
		 * Org-policy score gate on the manual value (org-password-policies
		 * §4.2): exempt types skip; the reason renders and submit stays
		 * disabled until compliant. Never POSTs a non-compliant value.
		 *
		 * @return {{compliant: boolean, reason: string|null}}
		 *
		 * @spec openspec/specs/org-password-policies/spec.md#requirement-client-side-save-enforcement
		 */
		policyVerdict() {
			if (this.isCard || this.isIdentity) {
				return { compliant: true, reason: null }
			}
			return evaluateScore(this.policy, this.selectedTypeName, this.value)
		},

		/**
		 * Whether Create may run: not busy, named, a folder chosen (secrets
		 * cannot live at the vault root), and the type's own required value
		 * present and policy-compliant.
		 *
		 * @return {boolean}
		 * @spec exclude Form-enablement guard; no domain behaviour.
		 */
		canSubmit() {
			if (this.saving || this.locked || this.name.trim() === '') {
				return false
			}
			if (!this.selectedFolderId && !this.contributeTo) {
				return false
			}
			if (this.totpError !== '') {
				return false
			}
			if (this.isCard) {
				return this.card.number !== ''
			}
			if (this.isIdentity) {
				return Object.values(this.identity).some((v) => v !== '')
			}
			return this.value !== '' && this.policyVerdict.compliant
		},
	},

	/**
	 * Load what the screen needs, including the user's saved defaults.
	 *
	 * @return {Promise<void>}
	 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
	 * @spec openspec/specs/vault-defaults/spec.md#requirement-default-item-type-and-view
	 */
	async mounted() {
		this.policy = await fetchPolicy()
		const typeStore = useSecretTypeStore()
		if (typeStore.types.length === 0) {
			await typeStore.fetchTypes()
		}
		if (this.typeId === null && typeStore.types.length > 0) {
			// The user's saved default type; Login when it no longer exists (vault-20).
			const prefs = useUserPreferencesStore()
			await prefs.ensureLoaded()
			this.typeId = resolveDefaultTypeId(
				prefs.defaultSecretType,
				typeStore.types,
			)
		}
		const folderStore = useFolderStore()
		if (folderStore.folders.length === 0) {
			await folderStore.fetchFolders()
		}
		if (this.policy?.vault_org_ownership === true) {
			await this.loadOwnershipTargets()
		}
	},

	methods: {
		t,

		/**
		 * Where a covered type may go: folders inside the user's own team
		 * folders, and the team folders they can write to.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/specs/vault-policies/spec.md#requirement-write-grade-members-save-new-secrets-into-a-team-folder
		 */
		async loadOwnershipTargets() {
			try {
				const teamFolderStore = useTeamFolderStore()
				await teamFolderStore.fetchTeamFolders()
				const roots = teamFolderStore.owned.map(
					(teamFolder) => teamFolder.folderId,
				)
				this.ownTeamFolderIds = subtreeIds(useFolderStore().folders, roots)
				this.contributable =
					(
						await axios.get(
							generateUrl(
								'/apps/keepiq/api/v1/team-folders/contributable',
							),
						)
					).data ?? []
			} catch {
				this.contributable = []
			}
		},

		/**
		 * The additional-fields object to encrypt: the free members plus the
		 * typed values.
		 *
		 * @return {object} The blob.
		 * @spec openspec/specs/admin-secret-types/spec.md#requirement-typed-fields-storage
		 */
		additionalBlob() {
			return mergeTypedValues(
				membersToObject(this.additionalFields),
				this.typedFields,
				this.typedValues,
			)
		},

		/**
		 * Encrypt (in the store) and create the secret.
		 *
		 * Additional fields travel as a plain name/value object; the store turns them
		 * into the single encrypted blob, which is the only place that knows the blob
		 * is the storage unit.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/specs/secrets-write-ui/spec.md#requirement-create-a-secret-from-the-ui
		 */
		async submit() {
			if (!this.canSubmit) {
				return
			}
			// A required field of the type blocks the save and is marked (admin-18).
			this.typedMissing = missingRequired(this.typedFields, this.typedValues)
			if (this.typedMissing.length > 0) {
				return
			}
			this.saving = true
			this.error = ''
			try {
				// HIBP block (org-password-policies §4.2): k-anonymity check
				// BEFORE encryption; only the 5-char prefix leaves the browser.
				if (!this.isCard && !this.isIdentity) {
					const hibpReason = await evaluateHibp(
						this.policy,
						this.selectedTypeName,
						this.value,
					)
					if (hibpReason !== null) {
						this.error = hibpReason
						this.saving = false
						return
					}
				}
				if (this.contributeTo) {
					// A team folder this user can write to but does not own
					// (admin-vault-policies D5): encrypted for owner and members.
					const result = await useSecretStore().contributeSecret(
						this.contributeTo.teamFolderId,
						{
							name: this.name.trim(),
							typeId: this.typeId,
							url: this.url || null,
							login: this.login || '',
							key: this.effectiveValue,
							...(Object.keys(this.additionalBlob()).length > 0
								? { additionalFields: this.additionalBlob() }
								: {}),
							...(this.totpToSave ? { totp: this.totpToSave } : {}),
						},
					)
					this.$emit('saved', result.secret)
					this.onUpdateOpen(false)
					return
				}
				const created = await useSecretStore().createSecret({
					name: this.name.trim(),
					typeId: this.typeId,
					folderId: this.selectedFolderId,
					url: this.url || null,
					login: this.login || '',
					key: this.effectiveValue,
					// Only when there ARE members: the store encrypts whatever it is
					// handed, so passing {} unconditionally would write an empty
					// ciphertext blob onto every secret ever created here.
					// The typed values ride in the same blob, named by field label,
					// so the server only ever sees their ciphertext (admin-18).
					...(Object.keys(this.additionalBlob()).length > 0
						? { additionalFields: this.additionalBlob() }
						: {}),
					// Only when entered, like the additional fields above.
					...(this.totpToSave ? { totp: this.totpToSave } : {}),
				})
				// Tags are stored apart from the value (vault-favourites-tags-and-last-used).
				if (this.tags.length > 0 && created?.id) {
					await useSecretStore().setTags(created.id, this.tags)
				}
				this.$emit('saved', created)
				if (this.onSaved) {
					this.onSaved(created)
				}
				this.onUpdateOpen(false)
			} catch (e) {
				this.error =
					e?.response?.data?.message
					|| e?.message
					|| t('keepiq', 'Failed to create secret')
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
</style>
