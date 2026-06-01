<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Secret vault list view. Renders the current user's secrets with search,
  folder filter, sortable columns and 50-per-page pagination. Registered as a
  custom page (`kind: "page"`) in src/registry.js and referenced from
  src/manifest.json. Encrypted blobs are never decrypted here — the list shows
  only plaintext metadata plus a masked-key indicator; copy/reveal decrypt on
  demand via the row components.
-->
<template>
	<NcAppContent>
		<div class="secret-list">
			<div class="secret-list__toolbar">
				<NcTextField
					:value.sync="searchTerm"
					:label="t('doriath', 'Search secrets')"
					trailing-button-icon="close"
					:show-trailing-button="searchTerm !== ''"
					@update:value="onSearch"
					@trailing-button-click="clearSearch" />

				<NcSelect
					v-model="sortField"
					:options="sortOptions"
					:input-label="t('doriath', 'Sort by')"
					label="label"
					:reduce="opt => opt.value"
					:clearable="false"
					@input="reload" />
			</div>

			<NcLoadingIcon v-if="store.loading" :size="44" class="secret-list__loading" />

			<NcEmptyContent
				v-else-if="store.secrets.length === 0"
				:name="t('doriath', 'No secrets yet')"
				:description="t('doriath', 'Create your first secret to get started.')">
				<template #icon>
					<KeyIcon :size="64" />
				</template>
			</NcEmptyContent>

			<table v-else class="secret-list__table">
				<thead>
					<tr>
						<th>{{ t('doriath', 'Name') }}</th>
						<th>{{ t('doriath', 'URL') }}</th>
						<th>{{ t('doriath', 'Password') }}</th>
						<th />
					</tr>
				</thead>
				<tbody>
					<SecretListItem
						v-for="secret in store.secrets"
						:key="secret.id"
						:secret="secret"
						@open="openSecret"
						@deleted="reload" />
				</tbody>
			</table>

			<CnPagination
				v-if="totalPages > 1"
				:page="store.page"
				:total-pages="totalPages"
				@update:page="onPage" />
		</div>
	</NcAppContent>
</template>

<script>
import { NcAppContent, NcEmptyContent, NcLoadingIcon, NcSelect, NcTextField } from '@nextcloud/vue'
// eslint-disable-next-line import/named
import { CnPagination } from '@conduction/nextcloud-vue'
import KeyIcon from 'vue-material-design-icons/Key.vue'
import SecretListItem from '../components/SecretListItem.vue'
import { useSecretStore } from '../store/modules/secret.js'

export default {
	name: 'SecretList',

	components: {
		NcAppContent,
		NcEmptyContent,
		NcLoadingIcon,
		NcSelect,
		NcTextField,
		CnPagination,
		KeyIcon,
		SecretListItem,
	},

	props: {
		/** Optional folder ID prop (when used directly, not via the route). */
		folderIdProp: {
			type: String,
			default: null,
		},
	},

	data() {
		return {
			searchTerm: '',
			sortField: 'name',
			searchDebounce: null,
		}
	},

	computed: {
		/**
		 * The shared secret store.
		 *
		 * @return {object} The Pinia secret store.
		 */
		store() {
			return useSecretStore()
		},
		/**
		 * The active folder filter: the route's :id param (FolderView) or the
		 * prop, or null for the unscoped vault list.
		 *
		 * @return {string|null} The folder ID.
		 */
		folderId() {
			return this.$route?.params?.id || this.folderIdProp || null
		},
		/**
		 * Total number of pages for the current result set.
		 *
		 * @return {number} The page count.
		 */
		totalPages() {
			return Math.max(1, Math.ceil(this.store.totalCount / this.store.limit))
		},
		/**
		 * Sort-field options.
		 *
		 * @return {Array} The select options.
		 */
		sortOptions() {
			return [
				{ value: 'name', label: t('doriath', 'Name') },
				{ value: 'url', label: t('doriath', 'URL') },
				{ value: 'created_at', label: t('doriath', 'Created') },
				{ value: 'updated_at', label: t('doriath', 'Updated') },
			]
		},
	},

	watch: {
		folderId() {
			this.reload()
		},
	},

	created() {
		this.reload()
	},

	methods: {
		/**
		 * Reload the list with current filters/sort.
		 */
		reload() {
			this.store.fetchSecrets({
				filters: { folderId: this.folderId },
				sort: { field: this.sortField, direction: 'asc' },
				page: 1,
			})
		},

		/**
		 * Debounced search handler.
		 *
		 * @param {string} term The search term.
		 */
		onSearch(term) {
			clearTimeout(this.searchDebounce)
			this.searchDebounce = setTimeout(() => {
				if (term && term.trim() !== '') {
					this.store.searchSecrets(term)
				} else {
					this.reload()
				}
			}, 250)
		},

		/**
		 * Clear the search and reload the full list.
		 */
		clearSearch() {
			this.searchTerm = ''
			this.reload()
		},

		/**
		 * Navigate to a page.
		 *
		 * @param {number} page The target page.
		 */
		onPage(page) {
			this.store.fetchSecrets({ page })
		},

		/**
		 * Open the detail view for a secret.
		 *
		 * @param {string} id The secret ID.
		 */
		openSecret(id) {
			this.$router.push({ name: 'SecretDetail', params: { id } })
		},
	},
}
</script>

<style scoped>
.secret-list {
	padding: 1rem;
}

.secret-list__toolbar {
	display: flex;
	gap: 1rem;
	align-items: flex-end;
	margin-bottom: 1rem;
}

.secret-list__table {
	width: 100%;
	border-collapse: collapse;
}

.secret-list__table th {
	text-align: left;
	padding: 0.5rem;
	border-bottom: 1px solid var(--color-border);
	color: var(--color-text-maxcontrast);
}

.secret-list__loading {
	margin: 3rem auto;
}
</style>
