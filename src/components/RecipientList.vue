<template>
	<div class="recipient-list">
		<NcLoadingIcon v-if="shareStore.loading" />

		<p v-else-if="shareStore.shares.length === 0" class="recipient-list__empty">
			{{ t('doriath', 'Not shared with anyone yet.') }}
		</p>

		<ul v-else class="recipient-list__items">
			<li
				v-for="share in shareStore.shares"
				:key="share.id"
				class="recipient-list__item">
				<span class="recipient-list__user">{{ share.targetUserId }}</span>
				<NcButton
					v-if="isOwner"
					type="tertiary"
					:aria-label="t('doriath', 'Revoke share')"
					:disabled="revoking === share.id"
					@click="revoke(share.id)">
					<template #icon>
						<CloseIcon :size="16" />
					</template>
				</NcButton>
			</li>
		</ul>
	</div>
</template>

<script>
import { NcButton, NcLoadingIcon } from '@nextcloud/vue'
import CloseIcon from 'vue-material-design-icons/Close.vue'
import { useShareStore } from '../store/modules/share.js'

export default {
	name: 'RecipientList',

	components: {
		NcButton,
		NcLoadingIcon,
		CloseIcon,
	},

	props: {
		secretId: {
			type: String,
			required: true,
		},
		isOwner: {
			type: Boolean,
			default: false,
		},
	},

	data() {
		return {
			revoking: null,
		}
	},

	computed: {
		shareStore() {
			return useShareStore()
		},
	},

	watch: {
		secretId(newId) {
			this.shareStore.fetchShares(newId)
		},
	},

	mounted() {
		this.shareStore.fetchShares(this.secretId)
	},

	methods: {
		async revoke(shareId) {
			this.revoking = shareId
			try {
				await this.shareStore.revokeShare(shareId)
			} finally {
				this.revoking = null
			}
		},
	},
}
</script>

<style scoped>
.recipient-list__empty {
	color: var(--color-text-maxcontrast);
	font-size: 0.875rem;
	margin: 0;
}

.recipient-list__items {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.recipient-list__item {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 4px 0;
}

.recipient-list__user {
	font-size: 0.875rem;
}
</style>
