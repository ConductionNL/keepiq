<template>
	<NcButton
		:title="copied ? t('doriath', 'Copied!') : t('doriath', 'Copy to clipboard')"
		type="tertiary"
		@click="copyValue">
		<template #icon>
			<CheckIcon v-if="copied" :size="16" />
			<ClipboardOutlineIcon v-else :size="16" />
		</template>
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'
import ClipboardOutlineIcon from 'vue-material-design-icons/ClipboardOutline.vue'

export default {
	name: 'CopyButton',
	components: {
		NcButton,
		CheckIcon,
		ClipboardOutlineIcon,
	},
	props: {
		value: {
			type: String,
			required: true,
		},
	},
	data() {
		return {
			copied: false,
			clearTimer: null,
			revertTimer: null,
		}
	},
	beforeDestroy() {
		clearTimeout(this.clearTimer)
		clearTimeout(this.revertTimer)
	},
	methods: {
		async copyValue() {
			try {
				await navigator.clipboard.writeText(this.value)
				this.copied = true

				// Revert icon after 2 seconds
				this.revertTimer = setTimeout(() => {
					this.copied = false
				}, 2000)

				// Clear clipboard after 30 seconds
				this.clearTimer = setTimeout(async () => {
					try {
						await navigator.clipboard.writeText('')
					} catch {
						// Ignore errors when clearing clipboard
					}
				}, 30000)
			} catch {
				// Clipboard API not available or permission denied
			}
		},
	},
}
</script>
