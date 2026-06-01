<template>
	<NcDialog :name="t('doriath', 'Request a share')"
		:open="open"
		size="normal"
		@update:open="$emit('update:open', $event)">
		<div class="share-request">
			<NcNoteCard type="info">
				{{ t('doriath', 'You hold a copy of this secret but cannot re-share it directly. Ask the owner to share it with another user.') }}
			</NcNoteCard>

			<NcNoteCard v-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<NcSelect v-model="selectedUser"
				:input-label="t('doriath', 'User to share with')"
				:placeholder="t('doriath', 'Search users')"
				:options="userOptions"
				:loading="searching"
				label="displayName"
				@search="onUserSearch" />
		</div>

		<template #actions>
			<NcButton :disabled="!selectedUser || loading" type="primary" @click="onSubmit">
				{{ loading ? t('doriath', 'Sending…') : t('doriath', 'Send request') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcButton, NcDialog, NcNoteCard, NcSelect } from '@nextcloud/vue'
import axios from '@nextcloud/axios'
import { generateOcsUrl } from '@nextcloud/router'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'ShareRequestForm',
	components: { NcButton, NcDialog, NcNoteCard, NcSelect },

	props: {
		/** @type {boolean} Whether the dialog is open */
		open: {
			type: Boolean,
			default: false,
		},
		/** @type {string} The source secret ID */
		secretId: {
			type: String,
			required: true,
		},
	},

	emits: ['update:open', 'requested'],

	data() {
		return {
			selectedUser: null,
			userOptions: [],
			searching: false,
			loading: false,
			error: null,
		}
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
		 * Submit the share request to the secret owner.
		 */
		async onSubmit() {
			this.loading = true
			this.error = null
			try {
				await useShareStore().submitShareRequest(this.secretId, this.selectedUser.id)
				this.$emit('requested')
				this.$emit('update:open', false)
			} catch (e) {
				this.error = e.message || t('doriath', 'Failed to submit request')
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped>
.share-request {
	display: flex;
	flex-direction: column;
	gap: 12px;
	padding: 8px 4px;
	min-width: 320px;
}
</style>
