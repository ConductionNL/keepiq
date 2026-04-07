<template>
	<div class="secret-list">
		<div class="secret-list__toolbar">
			<NcInputField
				v-model="searchTerm"
				:label="t('doriath', 'Search secrets')"
				type="search"
				class="secret-list__search"
				@input="onSearch" />
			<NcButton type="primary" @click="showCreateDialog = true">
				<template #icon>
					<PlusIcon :size="20" />
				</template>
				{{ t('doriath', 'New secret') }}
			</NcButton>
		</div>

		<!-- Create Secret Dialog -->
		<CreateSecretDialog
			:open="showCreateDialog"
			:folder-id="folderId"
			@update:open="showCreateDialog = $event"
			@created="onSecretCreated" />

		<NcLoadingIcon v-if="secretStore.loading" class="secret-list__loading" />

		<NcEmptyContent
			v-else-if="!secretStore.loading && secretStore.secrets.length === 0"
			:name="t('doriath', 'No secrets found')"
			:description="searchTerm ? t('doriath', 'Try a different search term') : t('doriath', 'Add your first secret using the button above')">
			<template #icon>
				<KeyVariantIcon :size="64" />
			</template>
		</NcEmptyContent>

		<table v-else class="secret-list__table">
			<thead>
				<tr>
					<th class="secret-list__col-icon" />
					<th>{{ t('doriath', 'Name') }}</th>
					<th class="secret-list__col-url">
						{{ t('doriath', 'URL') }}
					</th>
					<th class="secret-list__col-type">
						{{ t('doriath', 'Type') }}
					</th>
					<th class="secret-list__col-date">
						{{ t('doriath', 'Created') }}
					</th>
				</tr>
			</thead>
			<tbody>
				<tr
					v-for="secret in secretStore.secrets"
					:key="secret.id"
					class="secret-list__row"
					tabindex="0"
					@click="openSecret(secret.id)"
					@keyup.enter="openSecret(secret.id)">
					<td class="secret-list__col-icon">
						<img
							v-if="getFavicon(secret.url)"
							:src="getFavicon(secret.url)"
							:alt="secret.name"
							class="secret-list__favicon"
							@error="$event.target.style.display = 'none'">
						<KeyVariantIcon v-else :size="20" />
					</td>
					<td class="secret-list__name">
						{{ secret.name }}
						<AlertIcon
							v-if="secret.possiblyCompromisedAt"
							:size="16"
							class="secret-list__compromised-icon"
							:title="t('doriath', 'Possibly compromised — consider rotating this credential')" />
					</td>
					<td class="secret-list__col-url">
						<a
							v-if="secret.url"
							:href="secret.url"
							class="secret-list__url"
							target="_blank"
							rel="noopener noreferrer"
							@click.stop>
							{{ secret.url }}
						</a>
						<span v-else class="secret-list__empty-cell">—</span>
					</td>
					<td class="secret-list__col-type">
						{{ secret.type || '—' }}
					</td>
					<td class="secret-list__col-date">
						{{ formatDate(secret.createdAt) }}
					</td>
				</tr>
			</tbody>
		</table>

		<div v-if="totalPages > 1" class="secret-list__pagination">
			<NcButton
				:disabled="secretStore.page <= 1"
				type="tertiary"
				@click="changePage(secretStore.page - 1)">
				{{ t('doriath', 'Previous') }}
			</NcButton>
			<span class="secret-list__page-info">
				{{ t('doriath', 'Page {page} of {total}', { page: secretStore.page, total: totalPages }) }}
			</span>
			<NcButton
				:disabled="secretStore.page >= totalPages"
				type="tertiary"
				@click="changePage(secretStore.page + 1)">
				{{ t('doriath', 'Next') }}
			</NcButton>
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcInputField, NcLoadingIcon } from '@nextcloud/vue'
import AlertIcon from 'vue-material-design-icons/Alert.vue'
import KeyVariantIcon from 'vue-material-design-icons/KeyVariant.vue'
import PlusIcon from 'vue-material-design-icons/Plus.vue'
import CreateSecretDialog from '../dialog/CreateSecretDialog.vue'
import { useSecretStore } from '../store/modules/secret.js'
import { useSettingsStore } from '../store/modules/settings.js'
import { getFaviconUrl } from '../utils/favicon.js'

export default {
	name: 'SecretList',
	components: {
		NcButton,
		NcEmptyContent,
		NcInputField,
		NcLoadingIcon,
		AlertIcon,
		CreateSecretDialog,
		KeyVariantIcon,
		PlusIcon,
	},
	props: {
		folderId: {
			type: String,
			default: null,
		},
		rootOnly: {
			type: Boolean,
			default: false,
		},
	},
	data() {
		return {
			searchTerm: '',
			searchTimer: null,
			showCreateDialog: false,
		}
	},
	computed: {
		secretStore() {
			return useSecretStore()
		},
		settingsStore() {
			return useSettingsStore()
		},
		totalPages() {
			return Math.ceil(this.secretStore.totalCount / 50)
		},
	},
	watch: {
		folderId() {
			this.loadSecrets()
		},
	},
	async created() {
		await this.loadSecrets()
	},
	beforeDestroy() {
		clearTimeout(this.searchTimer)
	},
	methods: {
		async loadSecrets() {
			this.secretStore.page = 1
			this.searchTerm = ''
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
		},
		async openSecret(id) {
			console.debug('Doriath: openSecret called with id:', id)
			try {
				await this.secretStore.fetchSecret(id)
				console.debug('Doriath: fetchSecret completed, currentSecret:', this.secretStore.currentSecret?.name)
			} catch (e) {
				console.error('Doriath: openSecret failed:', e)
			}
		},
		onSearch() {
			clearTimeout(this.searchTimer)
			this.searchTimer = setTimeout(async () => {
				if (this.searchTerm.trim()) {
					await this.secretStore.searchSecrets(this.searchTerm.trim(), 1)
				} else {
					await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
				}
			}, 300)
		},
		async changePage(page) {
			this.secretStore.page = page
			if (this.searchTerm.trim()) {
				await this.secretStore.searchSecrets(this.searchTerm.trim(), page)
			} else {
				await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
			}
		},
		async onSecretCreated(created) {
			await this.secretStore.fetchSecrets(this.folderId, this.rootOnly)
			await this.openSecret(created.id)
		},
		getFavicon(url) {
			const faviconServiceUrl = this.settingsStore?.settings?.faviconServiceUrl ?? null
			return getFaviconUrl(url, faviconServiceUrl)
		},
		formatDate(dateString) {
			if (!dateString) return '—'
			try {
				return new Date(dateString).toLocaleDateString()
			} catch {
				return dateString
			}
		},
	},
}
</script>

<style scoped>
.secret-list__compromised-icon {
	color: var(--color-warning);
	vertical-align: middle;
	margin-left: 4px;
}

.secret-list {
	padding: 8px 4px 24px;
	max-width: 1200px;
}

.secret-list__toolbar {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 16px;
}

.secret-list__search {
	max-width: 320px;
}

.secret-list__loading {
	display: flex;
	justify-content: center;
	padding: 48px 0;
}

.secret-list__table {
	width: 100%;
	border-collapse: collapse;
}

.secret-list__table th {
	text-align: left;
	padding: 8px 12px;
	font-weight: 600;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
	font-size: 0.85rem;
	text-transform: uppercase;
	letter-spacing: 0.05em;
}

.secret-list__row {
	cursor: pointer;
	border-bottom: 1px solid var(--color-border-dark);
	transition: background 0.1s;
}

.secret-list__row:hover,
.secret-list__row:focus {
	background: var(--color-background-hover);
	outline: none;
}

.secret-list__row td {
	padding: 10px 12px;
	vertical-align: middle;
}

.secret-list__col-icon {
	width: 36px;
	text-align: center;
}

.secret-list__favicon {
	width: 16px;
	height: 16px;
	object-fit: contain;
}

.secret-list__col-url {
	max-width: 200px;
}

.secret-list__url {
	display: block;
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	color: var(--color-primary-element);
	text-decoration: none;
}

.secret-list__url:hover {
	text-decoration: underline;
}

.secret-list__col-type {
	width: 120px;
}

.secret-list__col-date {
	width: 120px;
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

.secret-list__empty-cell {
	color: var(--color-text-maxcontrast);
}

.secret-list__pagination {
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 16px;
	margin-top: 24px;
}

.secret-list__page-info {
	color: var(--color-text-maxcontrast);
	font-size: 0.9em;
}

</style>
