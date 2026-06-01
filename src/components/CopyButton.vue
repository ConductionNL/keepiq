<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Copy-to-clipboard button. Copies a (decrypted) value to the clipboard,
  shows a brief confirmation, and clears the clipboard after a timeout so a
  copied secret does not linger.
-->
<template>
	<NcButton
		type="tertiary"
		:aria-label="t('doriath', 'Copy to clipboard')"
		@click="copy">
		<template #icon>
			<CheckIcon v-if="copied" :size="20" />
			<ContentCopyIcon v-else :size="20" />
		</template>
	</NcButton>
</template>

<script>
import { NcButton } from '@nextcloud/vue'
import ContentCopyIcon from 'vue-material-design-icons/ContentCopy.vue'
import CheckIcon from 'vue-material-design-icons/Check.vue'

const CLEAR_AFTER_MS = 30000

export default {
	name: 'CopyButton',

	components: {
		NcButton,
		ContentCopyIcon,
		CheckIcon,
	},

	props: {
		/**
		 * The plaintext value to copy. May be a function returning a Promise,
		 * to trigger on-demand decryption only when the user clicks.
		 */
		value: {
			type: [String, Function],
			required: true,
		},
	},

	data() {
		return {
			copied: false,
			clearTimer: null,
			resetTimer: null,
		}
	},

	beforeDestroy() {
		clearTimeout(this.clearTimer)
		clearTimeout(this.resetTimer)
	},

	methods: {
		/**
		 * Resolve the value (decrypting on demand if a function), copy it, and
		 * schedule the clipboard clear.
		 */
		async copy() {
			const text = typeof this.value === 'function' ? await this.value() : this.value
			if (!text) {
				return
			}

			await this.writeClipboard(text)
			this.copied = true
			this.$emit('copied')

			clearTimeout(this.resetTimer)
			this.resetTimer = setTimeout(() => {
				this.copied = false
			}, 2000)

			clearTimeout(this.clearTimer)
			this.clearTimer = setTimeout(() => {
				this.writeClipboard('')
			}, CLEAR_AFTER_MS)
		},

		/**
		 * Write text to the clipboard with a legacy fallback.
		 *
		 * @param {string} text The text to write.
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
			area.select()
			document.execCommand('copy')
			document.body.removeChild(area)
		},
	},
}
</script>
