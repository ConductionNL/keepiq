<template>
	<NcAppSidebar
		v-if="secret"
		:name="secret.name"
		class="secret-sidebar-container"
		@close="close">
		<template #secondary-actions>
			<NcActionButton @click="handleEdit">
				<template #icon>
					<PencilIcon :size="20" />
				</template>
				{{ t('doriath', 'Edit') }}
			</NcActionButton>
			<NcActionButton @click="handleDelete">
				<template #icon>
					<DeleteIcon :size="20" />
				</template>
				{{ t('doriath', 'Delete') }}
			</NcActionButton>
		</template>

		<NcLoadingIcon v-if="secretStore.detailLoading" />

		<div v-else class="secret-sidebar">
			<NcNoteCard v-if="secret.possiblyCompromisedAt" type="warning">
				{{ t('doriath', 'This secret was migrated after a key compromise. The credential may have been exposed — consider rotating it with the service provider.') }}
			</NcNoteCard>

			<SecretFieldCard
				v-if="typeConfig.url.visible && secret.url"
				:label="typeConfig.url.label"
				:value="secret.url" />

			<SecretFieldCard
				v-if="typeConfig.login.visible && secret.login"
				:label="typeConfig.login.label"
				:value="secret.login" />

			<SecretFieldCard
				v-if="secret.key"
				:label="typeConfig.key.label"
				:value="secret.key"
				:sensitive="typeConfig.key.sensitive"
				:multiline="typeConfig.key.multiline" />

			<template v-if="secret.additionalFields && typeof secret.additionalFields === 'object'">
				<SecretFieldCard
					v-for="(val, field) in secret.additionalFields"
					:key="field"
					:label="String(field)"
					:value="normaliseAdditionalField(val).value"
					:sensitive="normaliseAdditionalField(val).type === 'hidden'"
					:multiline="normaliseAdditionalField(val).type === 'textarea'" />
			</template>

			<div v-if="typeLabel" class="secret-sidebar__meta">
				<label class="secret-sidebar__meta-label">{{ t('doriath', 'Type') }}</label>
				<span class="secret-sidebar__meta-value">{{ typeLabel }}</span>
			</div>

			<div v-if="createdDate" class="secret-sidebar__meta">
				<label class="secret-sidebar__meta-label">{{ t('doriath', 'Created') }}</label>
				<NcDateTime class="secret-sidebar__meta-value" :timestamp="createdDate" :relative-time="createdRelativeTime" />
			</div>

			<!-- Sharing section (visible when the current user owns the secret) -->
			<div v-if="isOwner" class="secret-sidebar__section">
				<div class="secret-sidebar__section-header">
					<h3 class="secret-sidebar__section-title">
						{{ t('doriath', 'Sharing') }}
					</h3>
					<NcButton type="tertiary" @click="shareDialogOpen = true">
						<template #icon>
							<ShareVariantIcon :size="20" />
						</template>
						{{ t('doriath', 'Share') }}
					</NcButton>
				</div>

				<RecipientList :secret-id="secret.id" :is-owner="isOwner" />
				<GroupShareList :secret-id="secret.id" :is-owner="isOwner" />

				<div class="secret-sidebar__subsection">
					<label class="secret-sidebar__label">{{ t('doriath', 'Delegation') }}</label>
					<DelegationManager :secret-id="secret.id" :is-owner="isOwner" />
				</div>
			</div>
		</div>

		<ShareDialog
			v-if="isOwner"
			:open.sync="shareDialogOpen"
			:secret-id="secret.id"
			@shared="onShared" />

		<CreateSecretDialog
			:open="editDialogOpen"
			:secret="secret"
			@update:open="editDialogOpen = $event"
			@updated="onUpdated" />
	</NcAppSidebar>
</template>

<script>
import { NcActionButton, NcAppSidebar, NcButton, NcDateTime, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import DeleteIcon from 'vue-material-design-icons/Delete.vue'
import PencilIcon from 'vue-material-design-icons/Pencil.vue'
import ShareVariantIcon from 'vue-material-design-icons/ShareVariant.vue'
import CreateSecretDialog from '../dialog/CreateSecretDialog.vue'
import DelegationManager from '../components/DelegationManager.vue'
import { getTypeFieldConfig } from '../utils/secretTypeFields.js'
import GroupShareList from '../components/GroupShareList.vue'
import RecipientList from '../components/RecipientList.vue'
import SecretFieldCard from '../components/SecretFieldCard.vue'
import ShareDialog from '../components/ShareDialog.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

export default {
	name: 'SecretSidebar',
	components: {
		NcActionButton,
		NcAppSidebar,
		NcButton,
		NcDateTime,
		NcLoadingIcon,
		NcNoteCard,
		CreateSecretDialog,
		DelegationManager,
		DeleteIcon,
		GroupShareList,
		PencilIcon,
		RecipientList,
		SecretFieldCard,
		ShareDialog,
		ShareVariantIcon,
	},

	data() {
		return {
			editDialogOpen: false,
			shareDialogOpen: false,
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
		/**
		 * True when the logged-in user is the owner of the current secret.
		 *
		 * @return {boolean}
		 */
		isOwner() {
			if (!this.secret) return false
			const ncUser = window.OC?.currentUser ?? null
			if (ncUser && this.secret.ownerId) {
				return ncUser === this.secret.ownerId
			}
			return true
		},
		typeLabel() {
			if (!this.secret?.typeId) return null
			const match = this.secretTypeStore.types.find(t => t.id === this.secret.typeId)
			return match?.label ?? null
		},
		createdDate() {
			if (!this.secret?.createdAt) return null
			const d = new Date(this.secret.createdAt)
			return isNaN(d.getTime()) ? null : d
		},
		createdRelativeTime() {
			if (!this.createdDate) return false
			const weekMs = 7 * 24 * 60 * 60 * 1000
			return (Date.now() - this.createdDate.getTime()) < weekMs ? 'long' : false
		},
	},

	watch: {
		secret() {
			if (this.secret?.typeId && !this.secretTypeStore.types.length) {
				this.secretTypeStore.fetchTypes()
			}
		},
	},

	methods: {
		normaliseAdditionalField(val) {
			if (val == null) return { type: 'text', value: '' }
			if (typeof val === 'string') return { type: 'hidden', value: val }
			const type = ['text', 'hidden', 'textarea'].includes(val.type) ? val.type : 'text'
			return { type, value: typeof val.value === 'string' ? val.value : '' }
		},
		close() {
			this.secretStore.currentSecret = null
		},
		handleEdit() {
			this.editDialogOpen = true
		},
		async handleDelete() {
			if (!this.secret) return
			const id = this.secret.id
			this.secretStore.currentSecret = null
			await this.secretStore.deleteSecret(id)
			await this.secretStore.refetchSecrets()
		},
		async onUpdated() {
			await this.secretStore.fetchSecret(this.secret.id)
			await this.secretStore.refetchSecrets()
		},
		onShared() {
			// Shares list is refreshed inside ShareDialog after a successful share.
		},
	},
}
</script>

<style scoped>
.secret-sidebar-container :global(.app-sidebar-header__mainname) {
	/* make sure the title lines up with the sidebar content */
	padding-inline-start: 8px !important;
}

.secret-sidebar {
	padding: 8px 16px 16px;
	display: flex;
	flex-direction: column;
	gap: 12px;
}

.secret-sidebar__meta {
	display: flex;
	gap: 8px;
	align-items: baseline;
}

.secret-sidebar__meta-label {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
	flex-shrink: 0;
}

.secret-sidebar__meta-value {
	font-size: 0.875rem;
	color: var(--color-main-text);
}

.secret-sidebar__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.secret-sidebar__label {
	font-size: 0.75rem;
	font-weight: 600;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.secret-sidebar__section {
	display: flex;
	flex-direction: column;
	gap: 16px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.secret-sidebar__section-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
}

.secret-sidebar__section-title {
	font-size: 0.875rem;
	font-weight: 600;
	margin: 0;
	color: var(--color-text-maxcontrast);
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.secret-sidebar__subsection {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding-top: 8px;
	border-top: 1px solid var(--color-border);
}
</style>
