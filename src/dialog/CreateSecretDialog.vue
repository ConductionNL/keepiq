<template>
	<div>
		<NcDialog
			:open="open"
			:name="t('doriath', 'Create secret')"
			@update:open="$emit('update:open', $event)">
			<div class="create-secret-form">
				<p class="create-secret-form__subtitle">
					{{ t('doriath', 'Store a new credential safely in your vault.') }}
				</p>

				<div class="create-secret-form__section">
					<h4 class="create-secret-form__section-label">
						{{ t('doriath', 'Credentials') }}
					</h4>
					<NcInputField
						v-model="newSecret.name"
						:label="t('doriath', 'Name')"
						:placeholder="t('doriath', 'e.g. GitHub, AWS Console')"
						required />
					<NcInputField
						v-model="newSecret.url"
						:label="t('doriath', 'URL')"
						:placeholder="t('doriath', 'e.g. https://github.com')" />
					<NcInputField
						v-model="newSecret.login"
						:label="t('doriath', 'Username / Login')"
						:placeholder="t('doriath', 'e.g. user@example.com')" />
					<div class="create-secret-form__password-row">
						<NcPasswordField
							v-model="newSecret.key"
							:label="t('doriath', 'Password / Key')"
							class="create-secret-form__password-field" />
						<NcButton
							type="tertiary"
							:aria-label="t('doriath', 'Generate password')"
							:title="t('doriath', 'Generate password')"
							class="create-secret-form__generate-btn"
							@click="showGenerateDialog = true">
							<template #icon>
								<DiceMultipleOutlineIcon :size="20" />
							</template>
						</NcButton>
					</div>
					<PasswordStrengthMeter
						v-if="newSecret.key"
						:password="newSecret.key" />
				</div>

				<div class="create-secret-form__section">
					<h4 class="create-secret-form__section-label">
						{{ t('doriath', 'Organize') }}
					</h4>
					<NcSelect
						v-model="newSecret.typeId"
						:options="typeOptions"
						label="label"
						:reduce="opt => opt.value"
						:placeholder="t('doriath', 'Type')" />
					<NcSelect
						v-model="newSecret.folderId"
						:options="folderOptions"
						label="label"
						:reduce="opt => opt.value"
						:placeholder="t('doriath', 'Folder (optional)')" />
				</div>

				<NcNoteCard v-if="createError" type="error">
					{{ createError }}
				</NcNoteCard>
				<div class="create-secret-form__actions">
					<NcButton type="tertiary" @click="$emit('update:open', false)">
						{{ t('doriath', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!newSecret.name || !newSecret.key || creating"
						@click="handleCreate">
						{{ creating ? t('doriath', 'Creating...') : t('doriath', 'Create') }}
					</NcButton>
				</div>
			</div>
		</NcDialog>

		<GeneratePasswordDialog
			:open="showGenerateDialog"
			@update:open="showGenerateDialog = $event"
			@accept="onGeneratedPassword" />
	</div>
</template>

<script>
import { NcButton, NcDialog, NcInputField, NcNoteCard, NcPasswordField, NcSelect } from '@nextcloud/vue'
import DiceMultipleOutlineIcon from 'vue-material-design-icons/DiceMultipleOutline.vue'
import GeneratePasswordDialog from './GeneratePasswordDialog.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

export default {
	name: 'CreateSecretDialog',
	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		DiceMultipleOutlineIcon,
		GeneratePasswordDialog,
		PasswordStrengthMeter,
	},
	props: {
		open: {
			type: Boolean,
			default: false,
		},
		folderId: {
			type: String,
			default: null,
		},
	},
	emits: ['update:open', 'created'],
	data() {
		return {
			creating: false,
			createError: null,
			showGenerateDialog: false,
			newSecret: {
				name: '',
				folderId: null,
				url: '',
				login: '',
				key: '',
				typeId: null,
			},
		}
	},
	computed: {
		secretStore() {
			return useSecretStore()
		},
		secretTypeStore() {
			return useSecretTypeStore()
		},
		folderStore() {
			return useFolderStore()
		},
		folderOptions() {
			const foldersById = {}
			for (const f of this.folderStore.folders) {
				foldersById[f.id] = f
			}

			const getPath = (folder) => {
				const parts = [folder.name]
				let current = folder
				while (current.parentId && foldersById[current.parentId]) {
					current = foldersById[current.parentId]
					parts.unshift(current.name)
				}
				return parts.join(' / ')
			}

			return this.folderStore.folders.map(f => ({
				value: f.id,
				label: getPath(f),
			}))
		},
		typeOptions() {
			return this.secretTypeStore.types.map(t => ({
				value: t.id,
				label: t.label,
			}))
		},
	},
	watch: {
		open(val) {
			if (val) {
				this.newSecret = {
					name: '',
					url: '',
					login: '',
					key: '',
					typeId: null,
					folderId: this.folderId ?? null,
				}
				this.createError = null
			}
		},
	},
	methods: {
		onGeneratedPassword(password) {
			this.newSecret.key = password
		},
		async handleCreate() {
			this.creating = true
			this.createError = null

			try {
				const data = {
					name: this.newSecret.name,
					key: this.newSecret.key,
				}
				if (this.newSecret.url) data.url = this.newSecret.url
				if (this.newSecret.login) data.login = this.newSecret.login
				if (this.newSecret.typeId) data.typeId = this.newSecret.typeId
				if (this.newSecret.folderId) data.folderId = this.newSecret.folderId

				const created = await this.secretStore.createSecret(data)
				this.$emit('update:open', false)
				this.$emit('created', created)
			} catch (e) {
				this.createError = e.response?.data?.message || e.message || t('doriath', 'Failed to create secret')
			} finally {
				this.creating = false
			}
		},
	},
}
</script>

<style scoped>
.create-secret-form {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 0 0 4px;
}

.create-secret-form__subtitle {
	color: var(--color-text-maxcontrast);
	font-size: 0.9rem;
	margin: 0 0 8px;
}

.create-secret-form__section {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 12px 0;
}

.create-secret-form__section + .create-secret-form__section {
	border-top: 1px solid var(--color-border);
}

.create-secret-form__section-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin: 0;
}

.create-secret-form__password-row {
	display: flex;
	align-items: flex-start;
	gap: 4px;
}

.create-secret-form__password-field {
	flex: 1;
}

.create-secret-form__generate-btn {
	flex-shrink: 0;
	margin-top: 6px;
}

.create-secret-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 4px;
	padding-top: 12px;
	border-top: 1px solid var(--color-border);
}
</style>
