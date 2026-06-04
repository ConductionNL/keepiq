<template>
	<NcButton :type="buttonType"
		:aria-label="label"
		:title="label"
		@click="onCopy">
		<template #icon>
			<Check v-if="copied" :size="20" />
			<ContentCopy v-else :size="20" />
		</template>
		<slot />
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ContentCopy from 'vue-material-design-icons/ContentCopy.vue'
import Check from 'vue-material-design-icons/Check.vue'

/**
 * A copy-to-clipboard button with a transient confirmation and an
 * auto-clearing clipboard. The value to copy may be supplied directly or
 * produced lazily by a `resolve` async function (used to trigger on-demand
 * decryption only when the user actually copies).
 */
export default {
	name: 'CopyButton',

	components: {
		NcButton,
		ContentCopy,
		Check,
	},

	props: {
		/** The plaintext value to copy (ignored when `resolve` is set). */
		value: {
			type: String,
			default: '',
		},
		/** An async resolver that returns the value to copy. */
		resolve: {
			type: Function,
			default: null,
		},
		/** The accessible label for the button. */
		label: {
			type: String,
			default() {
				return t('doriath', 'Copy to clipboard')
			},
		},
		/** The NcButton type. */
		buttonType: {
			type: String,
			default: 'tertiary',
		},
		/** Seconds after which the clipboard is cleared (0 disables clearing). */
		clearAfter: {
			type: Number,
			default: 30,
		},
	},

	data() {
		return {
			copied: false,
			timer: null,
		}
	},

	beforeUnmount() {
		if (this.timer) {
			clearTimeout(this.timer)
		}
	},

	methods: {
		t,

		/**
		 * Resolve the value, write it to the clipboard, confirm, and schedule
		 * the clipboard auto-clear.
		 *
		 * @return {Promise<void>}
		 */
		async onCopy() {
			let text = this.value
			if (this.resolve) {
				text = await this.resolve()
			}
			await this.writeClipboard(text)

			this.copied = true
			this.$emit('copied')
			setTimeout(() => {
				this.copied = false
			}, 1500)

			if (this.clearAfter > 0) {
				if (this.timer) {
					clearTimeout(this.timer)
				}
				this.timer = setTimeout(() => {
					this.writeClipboard('')
				}, this.clearAfter * 1000)
			}
		},

		/**
		 * Write text to the clipboard with a legacy fallback.
		 *
		 * @param {string} text The text to write.
		 * @return {Promise<void>}
		 */
		async writeClipboard(text) {
			if (navigator.clipboard && navigator.clipboard.writeText) {
				try {
					await navigator.clipboard.writeText(text)
					return
				} catch {
					// Fall through to the legacy path.
				}
			}
			const area = document.createElement('textarea')
			area.value = text
			area.style.position = 'fixed'
			area.style.opacity = '0'
			document.body.appendChild(area)
			area.focus()
			area.select()
			try {
				document.execCommand('copy')
			} finally {
				document.body.removeChild(area)
			}
		},
	},
}
</script>
