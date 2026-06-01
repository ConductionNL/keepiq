<template>
	<div class="group-share-list">
		<h4 class="group-share-list__title">
			{{ t('doriath', 'Shared with groups') }}
		</h4>

		<NcEmptyContent v-if="groupShares.length === 0"
			:name="t('doriath', 'No group shares')"
			:description="t('doriath', 'This secret has not been shared with any groups.')" />

		<ul v-else>
			<NcListItem v-for="groupShare in groupShares"
				:key="groupShare.id"
				:name="groupShare.groupId"
				:bold="false">
				<template #subname>
					{{ t('doriath', 'Group share') }}
				</template>
				<template #actions>
					<NcActionButton :aria-label="t('doriath', 'Revoke group share')"
						@click="onRevoke(groupShare.id)">
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
	name: 'GroupShareList',
	components: { NcActionButton, NcEmptyContent, NcListItem, Close },

	props: {
		/** @type {object[]} Group shares for the current secret */
		groupShares: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['revoked'],

	methods: {
		/**
		 * Revoke a group share and notify the parent.
		 *
		 * @param {string} groupShareId The group share ID
		 */
		async onRevoke(groupShareId) {
			await useShareStore().revokeGroupShare(groupShareId)
			this.$emit('revoked', groupShareId)
		},
	},
}
</script>

<style scoped>
.group-share-list__title {
	margin: 8px 0;
	font-weight: bold;
}
</style>
