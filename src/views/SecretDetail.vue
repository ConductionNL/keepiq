<template>
	<div class="secret-detail">
		<!-- Loading state -->
		<NcLoadingIcon v-if="secretStore.loading" class="secret-detail__loading" />

		<!-- Error state -->
		<NcNoteCard v-else-if="loadError" type="error">
			{{ loadError }}
		</NcNoteCard>

		<!-- Content -->
		<template v-else-if="secret">
			<div class="secret-detail__header">
				<NcButton type="tertiary" @click="$router.back()">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('doriath', 'Back') }}
				</NcButton>
				<h2 class="secret-detail__title">
					{{ secret.name }}
				</h2>
				<div class="secret-detail__actions">
					<NcButton v-if="!editMode" type="secondary" @click="enterEditMode">
						<template #icon>
							<PencilIcon :size="20" />
						</template>
						{{ t('doriath', 'Edit') }}
					</NcButton>
					<NcButton v-if="!editMode" type="error" @click="showDeleteConfirm = true">
						<template #icon>
							<DeleteIcon :size="20" />
						</template>
						{{ t('doriath', 'Delete') }}
					</NcButton>
				</div>
			</div>

			<!-- View mode -->
			<div v-if="!editMode" class="secret-detail__fields">
				<div class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'Name') }}</label>
					<span>{{ secret.name }}</span>
				</div>

				<div v-if="secret.url" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'URL') }}</label>
					<a
						:href="secret.url"
						target="_blank"
						rel="noopener noreferrer"
						class="secret-detail__link">
						{{ secret.url }}
					</a>
				</div>

				<div v-if="secret.type" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'Type') }}</label>
					<span>{{ secret.type }}</span>
				</div>

				<div v-if="secret.login" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'Login') }}</label>
					<div class="secret-detail__secret-row">
						<span>{{ secret.login }}</span>
						<CopyButton :value="secret.login" />
					</div>
				</div>

				<div v-if="secret.key" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'Password / Key') }}</label>
					<div class="secret-detail__secret-row">
						<PasswordField :value="secret.key" :label="t('doriath', 'Password / Key')" />
						<CopyButton :value="secret.key" />
					</div>
				</div>

				<template v-if="secret.additionalFields">
					<div
						v-for="(val, field) in secret.additionalFields"
						:key="field"
						class="secret-detail__field">
						<label class="secret-detail__field-label">{{ field }}</label>
						<div class="secret-detail__secret-row">
							<PasswordField :value="val" :label="field" />
							<CopyButton :value="val" />
						</div>
					</div>
				</template>

				<div v-if="secret.createdAt" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'Created') }}</label>
					<span>{{ formatDate(secret.createdAt) }}</span>
				</div>
			</div>

			<!-- Edit mode -->
			<form v-else class="secret-detail__edit-form" @submit.prevent="saveEdit">
				<NcNoteCard v-if="saveError" type="error">
					{{ saveError }}
				</NcNoteCard>

				<NcInputField
					v-model="editData.name"
					:label="t('doriath', 'Name')"
					required />
				<NcInputField
					v-model="editData.url"
					:label="t('doriath', 'URL')"
					type="url" />
				<NcInputField
					v-model="editData.login"
					:label="t('doriath', 'Login')" />
				<PasswordField
					v-model="editData.key"
					:label="t('doriath', 'Password / Key')"
					@input="editData.key = $event" />

				<div class="secret-detail__edit-actions">
					<NcButton type="tertiary" :disabled="saving" @click="cancelEdit">
						{{ t('doriath', 'Cancel') }}
					</NcButton>
					<NcButton
						native-type="submit"
						type="primary"
						:disabled="saving">
						{{ saving ? t('doriath', 'Saving...') : t('doriath', 'Save') }}
					</NcButton>
				</div>
			</form>
		</template>

		<!-- Delete confirmation dialog -->
		<NcDialog
			:open="showDeleteConfirm"
			:name="t('doriath', 'Delete secret')"
			@update:open="showDeleteConfirm = $event">
			<p>{{ t('doriath', 'Are you sure you want to delete "{name}"? This cannot be undone.', { name: secret ? secret.name : '' }) }}</p>
			<template #actions>
				<NcButton @click="showDeleteConfirm = false">
					{{ t('doriath', 'Cancel') }}
				</NcButton>
				<NcButton type="error" :disabled="deleting" @click="confirmDelete">
					{{ deleting ? t('doriath', 'Deleting...') : t('doriath', 'Delete') }}
				</NcButton>
			</template>
		</NcDialog>
	</div>
</template>

<script>
import { NcButton, NcDialog, NcInputField, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import CopyButton from '../components/CopyButton.vue'
import PasswordField from '../components/PasswordField.vue'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'SecretDetail',
	components: {
		NcButton,
		NcDialog,
		NcInputField,
		NcLoadingIcon,
		NcNoteCard,
		ArrowLeftIcon,
		DeleteIcon,
		PencilIcon,
		CopyButton,
		PasswordField,
	},
	props: {
		secretId: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			loadError: null,
			editMode: false,
			editData: {},
			saving: false,
			saveError: null,
			showDeleteConfirm: false,
			deleting: false,
		}
	},
	computed: {
		secretStore() {
			return useSecretStore()
		},
		secret() {
			return this.secretStore.currentSecret
		},
	},
	watch: {
		secretId(newId) {
			this.load(newId)
		},
	},
	created() {
		this.load(this.secretId)
	},
	methods: {
		async load(id) {
			this.loadError = null
			this.editMode = false
			try {
				await this.secretStore.fetchSecret(id)
			} catch (e) {
				this.loadError = e.message || t('doriath', 'Failed to load secret')
			}
		},
		enterEditMode() {
			const s = this.secret
			this.editData = {
				name: s.name ?? '',
				url: s.url ?? '',
				login: s.login ?? '',
				key: s.key ?? '',
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
				await this.secretStore.updateSecret(this.secretId, this.editData)
				await this.secretStore.fetchSecret(this.secretId)
				this.editMode = false
			} catch (e) {
				this.saveError = e.message || t('doriath', 'Failed to save secret')
			} finally {
				this.saving = false
			}
		},
		async confirmDelete() {
			this.deleting = true
			try {
				await this.secretStore.deleteSecret(this.secretId)
				this.$router.push({ path: '/secrets' })
			} catch (e) {
				this.showDeleteConfirm = false
				this.loadError = e.message || t('doriath', 'Failed to delete secret')
			} finally {
				this.deleting = false
			}
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
.secret-detail {
	padding: 8px 4px 24px;
	max-width: 800px;
}

.secret-detail__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.secret-detail__header {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 24px;
	flex-wrap: wrap;
}

.secret-detail__title {
	flex: 1;
	margin: 0;
	font-size: 1.25rem;
	font-weight: 600;
}

.secret-detail__actions {
	display: flex;
	gap: 8px;
}

.secret-detail__fields {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.secret-detail__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.secret-detail__field-label {
	font-size: 0.85rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.secret-detail__secret-row {
	display: flex;
	align-items: center;
	gap: 8px;
}

.secret-detail__link {
	color: var(--color-primary-element);
	text-decoration: none;
}

.secret-detail__link:hover {
	text-decoration: underline;
}

.secret-detail__edit-form {
	display: flex;
	flex-direction: column;
	gap: 16px;
}

.secret-detail__edit-actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
	margin-top: 8px;
}
</style>
