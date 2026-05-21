<template>
	<Fragment>
		<NcDialog
			:open="open"
			:name="isEditMode ? t('doriath', 'Edit secret') : t('doriath', 'Create secret')"
			@update:open="$emit('update:open', $event)">
			<div class="create-secret-form">
				<p class="create-secret-form__subtitle">
					{{ isEditMode
						? t('doriath', 'Update the credential details below.')
						: t('doriath', 'Store a new credential safely in your vault.')
					}}
				</p>

				<div class="create-secret-form__section">
					<h4 class="create-secret-form__section-label">
						{{ t('doriath', 'Credentials') }}
					</h4>
					<div :class="fieldClass('name')">
						<NcInputField
							v-model="newSecret.name"
							:label="typeConfig.name.label"
							:placeholder="typeConfig.name.placeholder"
							required />
					</div>
					<div :class="fieldClass('typeId')">
						<NcSelect
							v-model="newSecret.typeId"
							input-label="Type"
							:options="typeOptions"
							label="label"
							class="organize-max-width"
							:reduce="opt => opt.value"
							:placeholder="t('doriath', 'Type')"
							taggable
							:clearable="false"
							:create-option="createTypeOption"
							@option:created="onTypeCreated" />
					</div>
					<div v-if="typeConfig.url.visible" :class="fieldClass('url')">
						<NcInputField
							v-model="newSecret.url"
							:label="typeConfig.url.label"
							:placeholder="typeConfig.url.placeholder" />
					</div>
					<div v-if="typeConfig.login.visible" :class="fieldClass('login')">
						<NcInputField
							v-model="newSecret.login"
							:label="typeConfig.login.label"
							:placeholder="typeConfig.login.placeholder" />
					</div>
					<template v-if="typeConfig.key.multiline">
						<div :class="fieldClass('key')">
							<NcTextArea
								v-model="newSecret.key"
								:label="typeConfig.key.label"
								:placeholder="typeConfig.key.placeholder"
								resize="vertical" />
						</div>
					</template>
					<template v-else>
						<div :class="['create-secret-form__password-row', fieldClass('key')]">
							<NcPasswordField
								v-model="newSecret.key"
								:label="typeConfig.key.label"
								class="create-secret-form__password-field" />
							<NcButton
								v-if="typeConfig.key.showGenerator"
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
					</template>
					<PasswordStrengthMeter
						v-if="typeConfig.key.showStrengthMeter && newSecret.key"
						:password="newSecret.key"
						:enforcing="false" />
				</div>

				<div class="create-secret-form__section">
					<h4 class="create-secret-form__section-label">
						{{ t('doriath', 'Organize') }}
					</h4>
					<div :class="fieldClass('folderId')">
						<NcSelect
							v-model="newSecret.folderId"
							:options="folderOptions"
							label="label"
							class="organize-max-width"
							:reduce="opt => opt.value"
							:placeholder="t('doriath', 'Folder (optional)')" />
					</div>
				</div>

				<div class="create-secret-form__section">
					<h4 class="create-secret-form__section-label">
						{{ t('doriath', 'Additional fields') }}
					</h4>
					<div :class="fieldClass('additionalFields')">
						<AdditionalFieldsEditor v-model="newSecret.additionalFields" />
					</div>
				</div>

				<NcNoteCard v-if="formError" type="error">
					{{ formError }}
				</NcNoteCard>
				<NcNoteCard v-if="isEditMode && hasChanges && !formError" type="warning">
					{{ t('doriath', 'Properties have been modified. Changes will only take effect after the secret is saved.') }}
				</NcNoteCard>
				<div class="create-secret-form__actions">
					<NcButton type="tertiary" @click="$emit('update:open', false)">
						{{ t('doriath', 'Cancel') }}
					</NcButton>
					<NcButton
						type="primary"
						:disabled="!canSubmit || submitting"
						@click="handleSubmit">
						<template v-if="isEditMode">
							{{ submitting ? t('doriath', 'Saving...') : t('doriath', 'Save') }}
						</template>
						<template v-else>
							{{ submitting ? t('doriath', 'Creating...') : t('doriath', 'Create') }}
						</template>
					</NcButton>
				</div>
			</div>
		</NcDialog>

		<GeneratePasswordDialog
			:open="showGenerateDialog"
			@update:open="showGenerateDialog = $event"
			@accept="onGeneratedPassword" />
	</Fragment>
</template>

<script>
import { NcButton, NcDialog, NcInputField, NcNoteCard, NcPasswordField, NcSelect, NcTextArea } from '@nextcloud/vue'
import DiceMultipleOutlineIcon from 'vue-material-design-icons/DiceMultipleOutline.vue'
import GeneratePasswordDialog from './GeneratePasswordDialog.vue'
import AdditionalFieldsEditor from '../components/AdditionalFieldsEditor.vue'
import PasswordStrengthMeter from '../components/PasswordStrengthMeter.vue'
import { getTypeFieldConfig } from '../utils/secretTypeFields.js'
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
		NcTextArea,
		DiceMultipleOutlineIcon,
		GeneratePasswordDialog,
		AdditionalFieldsEditor,
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
		secret: {
			type: Object,
			default: null,
		},
	},
	emits: ['update:open', 'created', 'updated'],
	data() {
		return {
			submitting: false,
			formError: null,
			showGenerateDialog: false,
			originalValues: null,
			newSecret: {
				name: '',
				folderId: null,
				url: '',
				login: '',
				key: '',
				typeId: null,
				additionalFields: {},
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
		typeConfig() {
			return getTypeFieldConfig(this.secretTypeStore.types, this.newSecret.typeId, this.t)
		},
		isEditMode() {
			return !!this.secret
		},
		hasChanges() {
			if (!this.originalValues) return true
			return ['name', 'url', 'login', 'key', 'typeId', 'folderId', 'additionalFields']
				.some(f => this.isFieldModified(f))
		},
		canSubmit() {
			if (!this.newSecret.name) return false
			if (!this.isEditMode && !this.newSecret.key) return false
			if (this.isEditMode && !this.hasChanges) return false
			return true
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
		async open(val) {
			if (val) {
				this.formError = null
				await this.secretTypeStore.fetchTypes()
				const loginType = this.secretTypeStore.types.find(t => t.name === 'login')
				if (this.secret) {
					const additionalFields = (this.secret.additionalFields && typeof this.secret.additionalFields === 'object')
						? JSON.parse(JSON.stringify(this.secret.additionalFields))
						: {}
					const values = {
						name: this.secret.name ?? '',
						url: this.secret.url ?? '',
						login: this.secret.login ?? '',
						key: this.secret.key ?? '',
						typeId: this.secret.typeId ?? loginType?.id ?? null,
						folderId: this.secret.folderId ?? null,
						additionalFields,
					}
					this.newSecret = { ...values, additionalFields: JSON.parse(JSON.stringify(additionalFields)) }
					this.originalValues = values
				} else {
					this.newSecret = {
						name: '',
						url: '',
						login: '',
						key: '',
						typeId: loginType?.id ?? null,
						folderId: this.folderId ?? null,
						additionalFields: {},
					}
					this.originalValues = null
				}
			}
		},
	},
	methods: {
		isFieldModified(field) {
			if (!this.originalValues) return false
			const a = this.newSecret[field]
			const b = this.originalValues[field]
			if (a !== null && typeof a === 'object') {
				return JSON.stringify(a ?? {}) !== JSON.stringify(b ?? {})
			}
			return a !== b
		},
		fieldClass(field) {
			return {
				'create-secret-form__field': true,
				'create-secret-form__field--modified': this.isFieldModified(field),
			}
		},
		createTypeOption(label) {
			return { value: label, label }
		},
		async onTypeCreated(option) {
			try {
				const slug = option.label.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_|_$/g, '')
				const created = await this.secretTypeStore.createType(slug, option.label, 'personal')
				this.newSecret.typeId = created.id
			} catch (e) {
				this.formError = e.response?.data?.message || e.message || t('doriath', 'Failed to create type')
			}
		},
		onGeneratedPassword(password) {
			this.newSecret.key = password
		},
		async handleSubmit() {
			this.submitting = true
			this.formError = null

			try {
				if (this.isEditMode) {
					await this.handleUpdate()
				} else {
					await this.handleCreate()
				}
			} catch (e) {
				this.formError = e.response?.data?.message || e.message || t('doriath', 'Something went wrong')
			} finally {
				this.submitting = false
			}
		},
		async handleCreate() {
			const data = {
				name: this.newSecret.name,
				key: this.newSecret.key,
			}
			if (this.newSecret.url) data.url = this.newSecret.url
			if (this.newSecret.login) data.login = this.newSecret.login
			if (this.newSecret.typeId) data.typeId = this.newSecret.typeId
			if (this.newSecret.folderId) data.folderId = this.newSecret.folderId
			if (this.newSecret.additionalFields && Object.keys(this.newSecret.additionalFields).length > 0) {
				data.additionalFields = this.newSecret.additionalFields
			}

			const created = await this.secretStore.createSecret(data)
			this.$emit('update:open', false)
			this.$emit('created', created)
		},
		async handleUpdate() {
			const data = {}

			if (this.isFieldModified('name')) data.name = this.newSecret.name
			if (this.isFieldModified('url')) data.url = this.newSecret.url
			if (this.isFieldModified('login')) data.login = this.newSecret.login
			if (this.isFieldModified('key')) data.key = this.newSecret.key
			if (this.isFieldModified('typeId')) data.typeId = this.newSecret.typeId
			if (this.isFieldModified('folderId')) data.folderId = this.newSecret.folderId
			if (this.isFieldModified('additionalFields')) data.additionalFields = this.newSecret.additionalFields

			await this.secretStore.updateSecret(this.secret.id, data)
			this.$emit('update:open', false)
			this.$emit('updated')
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

.create-secret-form__field {
	border-left: 3px solid transparent;
	padding-left: 8px;
	transition: border-color 0.15s ease;
}

.create-secret-form__field--modified {
	border-left-color: var(--color-primary-element);
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
	padding-bottom: 8px;
	border-top: 1px solid var(--color-border);
}

.organize-max-width {
	width: 100%;
}
</style>
