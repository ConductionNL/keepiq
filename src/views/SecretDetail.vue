<template>
	<div class="secret-detail">
		<CnDetailPage
			:title="secret?.name || ''"
			:loading="secretStore.loading"
			:error="!!loadError"
			:error-message="loadError || ''"
			:on-retry="() => load(secretId)">
			<!-- Header actions -->
			<template #header-actions>
				<NcButton type="tertiary" @click="$router.back()">
					<template #icon>
						<ArrowLeftIcon :size="20" />
					</template>
					{{ t('doriath', 'Back') }}
				</NcButton>
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
			</template>

			<!-- Error actions -->
			<template #error-actions>
				<NcButton @click="$router.push({ path: '/secrets' })">
					{{ t('doriath', 'Back to secrets') }}
				</NcButton>
			</template>

			<!-- View mode -->
			<div v-if="!editMode" class="secret-detail__fields">
				<div class="secret-detail__field">
					<label class="secret-detail__field-label">{{ t('doriath', 'Name') }}</label>
					<span>{{ secret.name }}</span>
				</div>

				<div v-if="typeConfig.url.visible && secret.url" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ typeConfig.url.label }}</label>
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

				<div v-if="typeConfig.login.visible && secret.login" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ typeConfig.login.label }}</label>
					<div class="secret-detail__secret-row">
						<span>{{ secret.login }}</span>
						<CopyButton :value="secret.login" />
					</div>
				</div>

				<div v-if="secret.key" class="secret-detail__field">
					<label class="secret-detail__field-label">{{ typeConfig.key.label }}</label>
					<template v-if="typeConfig.key.multiline">
						<div class="secret-detail__secret-row">
							<pre v-if="keyRevealed" class="secret-detail__pre">{{ secret.key }}</pre>
							<span v-else>{{ '\u2022'.repeat(16) }}</span>
							<NcButton
								type="tertiary"
								:title="keyRevealed ? t('doriath', 'Hide') : t('doriath', 'Show')"
								@click="keyRevealed = !keyRevealed">
								<template #icon>
									<EyeOffIcon v-if="keyRevealed" :size="16" />
									<EyeIcon v-else :size="16" />
								</template>
							</NcButton>
							<CopyButton :value="secret.key" />
						</div>
					</template>
					<div v-else class="secret-detail__secret-row">
						<PasswordField :value="secret.key" :label="typeConfig.key.label" />
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
					:label="typeConfig.name.label"
					required />
				<NcInputField
					v-if="typeConfig.url.visible"
					v-model="editData.url"
					:label="typeConfig.url.label"
					:placeholder="typeConfig.url.placeholder"
					type="url" />
				<NcInputField
					v-if="typeConfig.login.visible"
					v-model="editData.login"
					:label="typeConfig.login.label"
					:placeholder="typeConfig.login.placeholder" />
				<NcTextArea
					v-if="typeConfig.key.multiline"
					v-model="editData.key"
					:label="typeConfig.key.label"
					resize="vertical" />
				<PasswordField
					v-else
					v-model="editData.key"
					:label="typeConfig.key.label"
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
		</CnDetailPage>

		<!-- Delete confirmation dialog -->
		<DeleteSecretDialog
			:open="showDeleteConfirm"
			:secret-id="secretId"
			:secret-name="secret ? secret.name : ''"
			@update:open="showDeleteConfirm = $event"
			@deleted="$router.push({ path: '/secrets' })"
			@error="loadError = $event" />
	</div>
</template>

<script>
import { NcButton, NcInputField, NcNoteCard, NcTextArea } from '@nextcloud/vue'
// eslint-disable-next-line import/named
import { CnDetailPage } from '@conduction/nextcloud-vue'
import ArrowLeftIcon from 'vue-material-design-icons/ArrowLeft.vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import EyeIcon from 'vue-material-design-icons/Eye.vue'
import EyeOffIcon from 'vue-material-design-icons/EyeOff.vue'
import CopyButton from '../components/CopyButton.vue'
import DeleteSecretDialog from '../dialog/DeleteSecretDialog.vue'
import PasswordField from '../components/PasswordField.vue'
import { getTypeFieldConfig } from '../utils/secretTypeFields.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

export default {
	name: 'SecretDetail',
	components: {
		NcButton,
		NcInputField,
		NcNoteCard,
		NcTextArea,
		CnDetailPage,
		ArrowLeftIcon,
		DeleteIcon,
		DeleteSecretDialog,
		EyeIcon,
		EyeOffIcon,
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
			keyRevealed: false,
		}
	},
	computed: {
		secretStore() {
			return useSecretStore()
		},
		secretTypeStore() {
			return useSecretTypeStore()
		},
		secret() {
			return this.secretStore.currentSecret
		},
		typeConfig() {
			return getTypeFieldConfig(this.secretTypeStore.types, this.secret?.typeId, this.t)
		},
	},
	watch: {
		secretId(newId) {
			this.load(newId)
		},
	},
	created() {
		this.load(this.secretId)
		this.secretTypeStore.fetchTypes()
	},
	methods: {
		async load(id) {
			this.loadError = null
			this.editMode = false
			this.keyRevealed = false
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

.secret-detail__pre {
	margin: 0;
	white-space: pre-wrap;
	word-break: break-word;
	font-family: var(--font-monospace, monospace);
	font-size: 0.8125rem;
	line-height: 1.5;
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
