<template>
	<NcDialog :name="t('doriath', 'Share secret')"
		:open="open"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="share-dialog">
			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<fieldset class="share-dialog__mode">
				<NcCheckboxRadioSwitch :model-value="mode"
					value="user"
					name="share-mode"
					type="radio"
					@update:model-value="mode = $event">
					{{ t('doriath', 'Share with a user') }}
				</NcCheckboxRadioSwitch>
				<NcCheckboxRadioSwitch :model-value="mode"
					value="group"
					name="share-mode"
					type="radio"
					@update:model-value="mode = $event">
					{{ t('doriath', 'Share with a group') }}
				</NcCheckboxRadioSwitch>
			</fieldset>

			<NcSelect v-if="mode === 'user'"
				v-model="selectedUser"
				:input-label="t('doriath', 'Recipient')"
				:placeholder="t('doriath', 'Search users')"
				:options="userOptions"
				:loading="searching"
				label="displayName"
				@search="onUserSearch" />

			<NcSelect v-else
				v-model="selectedGroup"
				:input-label="t('doriath', 'Group')"
				:placeholder="t('doriath', 'Search groups')"
				:options="groupOptions"
				:loading="searching"
				label="displayName"
				@search="onGroupSearch" />
		</div>

		<template #actions>
			<NcButton :disabled="!canShare || loading" type="primary" @click="onShare">
				{{ loading ? t('doriath', 'Sharing…') : t('doriath', 'Share') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'ShareDialog',
	components: { NcButton, NcCheckboxRadioSwitch, NcDialog, NcNoteCard, NcSelect },

	props: {
		/** @type {boolean} Whether the dialog is open */
		open: {
			type: Boolean,
			default: false,
		},
		/** @type {string} The source secret ID being shared */
		secretId: {
			type: String,
			required: true,
		},
		/** @type {object} The decrypted secret fields + metadata to share */
		plaintext: {
			type: Object,
			default: () => ({}),
		},
	},

	emits: ['update:open', 'shared'],

	data() {
		return {
			mode: 'user',
			selectedUser: null,
			selectedGroup: null,
			userOptions: [],
			groupOptions: [],
			searching: false,
			loading: false,
			error: null,
		}
	},

	computed: {
		/**
		 * Whether a recipient has been selected for the active mode.
		 *
		 * @return {boolean} True when a share can be created.
		 */
		canShare() {
			return this.mode === 'user' ? !!this.selectedUser : !!this.selectedGroup
		},
	},

	methods: {
		/**
		 * Autocomplete Nextcloud users via the sharee OCS API.
		 *
		 * @param {string} query The search term
		 */
		async onUserSearch(query) {
			if (!query) {
				return
			}
			this.searching = true
			try {
				const response = await axios.get(
					generateOcsUrl('apps/files_sharing/api/v1/sharees'),
					{ params: { search: query, itemType: 'file', shareType: [0], perPage: 20, format: 'json' } },
				)
				const users = response.data.ocs.data.users || []
				this.userOptions = users.map(u => ({ id: u.value.shareWith, displayName: u.label }))
			} finally {
				this.searching = false
			}
		},

		/**
		 * Autocomplete Nextcloud groups via the sharee OCS API.
		 *
		 * @param {string} query The search term
		 */
		async onGroupSearch(query) {
			if (!query) {
				return
			}
			this.searching = true
			try {
				const response = await axios.get(
					generateOcsUrl('apps/files_sharing/api/v1/sharees'),
					{ params: { search: query, itemType: 'file', shareType: [1], perPage: 20, format: 'json' } },
				)
				const groups = response.data.ocs.data.groups || []
				this.groupOptions = groups.map(g => ({ id: g.value.shareWith, displayName: g.label }))
			} finally {
				this.searching = false
			}
		},

		/**
		 * Trigger the client-side share flow for the selected recipient.
		 */
		async onShare() {
			this.loading = true
			this.error = null
			const store = useShareStore()
			try {
				if (this.mode === 'user') {
					await store.createShare(this.secretId, this.selectedUser.id, this.plaintext)
				} else {
					await store.createGroupShare(this.secretId, this.selectedGroup.id, this.plaintext)
				}
				this.$emit('shared')
				this.$emit('update:open', false)
			} catch (e) {
				this.error = e.message || t('doriath', 'Failed to share secret')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.share-dialog {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 320px;
}

.share-dialog__mode {
	display: flex;
	gap: 16px;
}
</style>
