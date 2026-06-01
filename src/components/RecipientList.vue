<template>
	<div class="recipient-list">
		<h4 class="recipient-list__title">
			{{ t('doriath', 'Shared with') }}
		</h4>

		<NcEmptyContent v-if="shares.length === 0"
			:name="t('doriath', 'Not shared yet')"
			:description="t('doriath', 'This secret has not been shared with any users.')" />

		<ul v-else>
			<NcListItem v-for="share in shares"
				:key="share.id"
				:name="share.targetUserId"
				:bold="false">
				<template #subname>
					{{ share.groupShareId ? t('doriath', 'Via group share') : t('doriath', 'Direct share') }}
				</template>
				<template #actions>
					<NcActionButton :aria-label="t('doriath', 'Revoke share')"
						@click="onRevoke(share.id)">
						<template #icon>
							<Close :size="20" />
						</template>
						{{ t('doriath', 'Revoke') }}
					</NcActionButton>
				</template>
			</NcListItem>
		</ul>
	</div>
</template>

<script>
import { NcActionButton, NcEmptyContent, NcListItem } from '@nextcloud/vue'
import Close from 'vue-material-design-icons/Close.vue'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'RecipientList',
	components: { NcActionButton, NcEmptyContent, NcListItem, Close },

	props: {
		/** @type {object[]} Shares for the current secret */
		shares: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['revoked'],

	methods: {
		/**
		 * Revoke a share and notify the parent.
		 *
		 * @param {string} shareId The share ID
		 */
		async onRevoke(shareId) {
			await useShareStore().revokeShare(shareId)
			this.$emit('revoked', shareId)
		},
	},
}
</script>

<style scoped>
.recipient-list__title {
	margin: 8px 0;
	font-weight: bold;
}
</style>
