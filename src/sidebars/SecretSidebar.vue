<template>
	<NcAppSidebar
		v-if="secret"
		:name="editMode ? editData.name : secret.name"
		:subname="editMode ? editData.url : (secret.url || '')"
		@close="close">
		<NcLoadingIcon v-if="secretStore.detailLoading" />

		<!-- Edit mode -->
		<div v-else-if="editMode" class="secret-sidebar">
			<NcNoteCard v-if="saveError" type="error">
				{{ saveError }}
			</NcNoteCard>

			<div class="secret-sidebar__field">
				<NcInputField
					v-model="editData.name"
					:label="t('doriath', 'Name')"
					required />
			</div>
			<div class="secret-sidebar__field">
				<NcInputField
					v-model="editData.url"
					:label="t('doriath', 'URL')" />
			</div>
			<div class="secret-sidebar__field">
				<NcInputField
					v-model="editData.login"
					:label="t('doriath', 'Login')" />
			</div>
			<div class="secret-sidebar__field">
				<NcPasswordField
					v-model="editData.key"
					:label="t('doriath', 'Password / Key')" />
			</div>
			<div class="secret-sidebar__field">
				<NcSelect
					v-model="editData.folderId"
					:options="folderOptions"
					label="label"
					:reduce="opt => opt.value"
					:placeholder="t('doriath', 'Folder')" />
			</div>

			<div class="secret-sidebar__actions">
				<NcButton type="tertiary" :disabled="saving" @click="cancelEdit">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton type="primary" :disabled="!editData.name || saving" @click="saveEdit">
					{{ saving ? t('doriath', 'Saving...') : t('doriath', 'Save') }}
				</NcButton>
			</div>
		</div>

		<!-- View mode -->
		<div v-else class="secret-sidebar">
			<NcNoteCard v-if="secret.possiblyCompromisedAt" type="warning">
				{{ t('doriath', 'This secret was migrated after a key compromise. The credential may have been exposed — consider rotating it with the service provider.') }}
			</NcNoteCard>

			<div v-if="secret.login" class="secret-sidebar__field">
				<label class="secret-sidebar__label">{{ t('doriath', 'Login') }}</label>
				<div class="secret-sidebar__value-row">
					<span>{{ secret.login }}</span>
					<CopyButton :value="secret.login" />
				</div>
			</div>

			<div v-if="secret.key" class="secret-sidebar__field">
				<label class="secret-sidebar__label">{{ t('doriath', 'Password / Key') }}</label>
				<div class="secret-sidebar__value-row">
					<PasswordField :value="secret.key" :label="t('doriath', 'Password / Key')" />
					<CopyButton :value="secret.key" />
				</div>
			</div>

			<template v-if="secret.additionalFields && typeof secret.additionalFields === 'object'">
				<div
					v-for="(val, field) in secret.additionalFields"
					:key="field"
					class="secret-sidebar__field">
					<label class="secret-sidebar__label">{{ field }}</label>
					<div class="secret-sidebar__value-row">
						<PasswordField :value="val" :label="field" />
						<CopyButton :value="val" />
					</div>
				</div>
			</template>

			<div v-if="secret.type" class="secret-sidebar__field">
				<label class="secret-sidebar__label">{{ t('doriath', 'Type') }}</label>
				<span>{{ secret.type }}</span>
			</div>

			<div class="secret-sidebar__field">
				<label class="secret-sidebar__label">{{ t('doriath', 'Created') }}</label>
				<span>{{ formatDate(secret.createdAt) }}</span>
			</div>

			<div class="secret-sidebar__actions">
				<NcButton type="secondary" @click="enterEdit">
					<template #icon>
						<PencilIcon :size="20" />
					</template>
					{{ t('doriath', 'Edit') }}
				</NcButton>
				<NcButton type="error" @click="handleDelete">
					<template #icon>
						<DeleteIcon :size="20" />
					</template>
					{{ t('doriath', 'Delete') }}
				</NcButton>
			</div>
		</div>
	</NcAppSidebar>
</template>

<script>
import { NcAppSidebar, NcButton, NcInputField, NcLoadingIcon, NcNoteCard, NcPasswordField, NcSelect } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CopyButton from '../components/CopyButton.vue'
import PasswordField from '../components/PasswordField.vue'
import { useFolderStore } from '../store/modules/folder.js'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'SecretSidebar',
	components: {
		NcAppSidebar,
		NcButton,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		NcPasswordField,
		NcSelect,
		CopyButton,
		DeleteIcon,
		PencilIcon,
		PasswordField,
	},

	data() {
		return {
			editMode: false,
			editData: {},
			saving: false,
			saveError: null,
		}
	},

	computed: {
		secretStore() {
			return useSecretStore()
		},
		folderStore() {
			return useFolderStore()
		},
		secret() {
			return this.secretStore.currentSecret
		},
		folderOptions() {
			return [
				{ value: null, label: t('doriath', '— No folder —') },
				...this.folderStore.folders.map(f => ({
					value: f.id,
					label: f.name,
				})),
			]
		},
	},

	watch: {
		// Reset edit mode when a different secret is selected.
		secret(newVal) {
			this.editMode = false
			this.editData = {}
			this.saveError = null

			// If the list requested edit mode, enter it once the secret is loaded.
			if (newVal && this.secretStore.editRequested) {
				this.secretStore.editRequested = false
				this.$nextTick(() => this.enterEdit())
			}
		},
	},

	methods: {
		close() {
			this.editMode = false
			this.secretStore.currentSecret = null
		},
		enterEdit() {
			this.editData = {
				name: this.secret.name ?? '',
				url: this.secret.url ?? '',
				login: this.secret.login ?? '',
				key: this.secret.key ?? '',
				folderId: this.secret.folderId ?? null,
			}
			this.saveError = null
			this.editMode = true
		},
		cancelEdit() {
			this.editMode = false
			this.editData = {}
			this.saveError = null
		},
		async saveEdit() {
			this.saving = true
			this.saveError = null

			try {
				await this.secretStore.updateSecret(this.secret.id, this.editData)
				await this.secretStore.fetchSecret(this.secret.id)
				await this.secretStore.refetchSecrets()
				this.editMode = false
			} catch (e) {
				this.saveError = e.response?.data?.message || e.message || t('doriath', 'Failed to save')
			} finally {
				this.saving = false
			}
		},
		async handleDelete() {
			if (!this.secret) return
			const id = this.secret.id
			this.secretStore.currentSecret = null
			await this.secretStore.deleteSecret(id)
			await this.secretStore.refetchSecrets()
		},
		formatDate(dateString) {
			if (!dateString) return '—'
			try {
				return new Date(dateString).toLocaleString()
			} catch {
				return dateString
			}
		},
	},
}
</script>

<style scoped>
.secret-sidebar {
	padding: 0 16px 16px;
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.secret-sidebar__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.secret-sidebar__label {
	font-size: 0.8rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.secret-sidebar__value-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.secret-sidebar__actions {
	display: flex;
	gap: 8px;
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}
</style>
