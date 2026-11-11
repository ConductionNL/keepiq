/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

import { markRaw } from 'vue'
import Safe from 'vue-material-design-icons/Safe.vue'

/**
 * The methods a folder dialog using the mixin provides.
 *
 * @typedef {object} FolderDialogHost
 * @property {string} name The name being entered.
 * @property {function(object): void} [onFolderSaved] Runs with the saved folder before the dialog closes; defaults to emitting `saved`.
 */

/**
 * What the folder create and edit dialogs share: the open, saving and
 * error state, the Save guard, the picker's default glyph and translated
 * labels, the close event, and running one store write with its error.
 *
 * The host provides `name` and may override `onFolderSaved`.
 */
export default {
	emits: ['saved', 'close'],

	data() {
		return {
			open: true,
			saving: false,
			error: '',
			/** The vaults' default glyph, for the picker's Default cell. */
			safeIcon: markRaw(Safe),
		}
	},

	computed: {
		/**
		 * Whether Save may run: not already saving, and a name is present.
		 *
		 * @return {boolean}
		 * @spec openspec/specs/secrets-write-ui/spec.md#scenario-create-a-folder
		 */
		canSubmit() {
			return !this.saving && this.name.trim() !== ''
		},
	},

	methods: {
		t,

		/**
		 * Translate a picker label through keepiq's catalog (the picker's
		 * labels are library-side English source strings).
		 *
		 * @param {string} label The English source label.
		 * @return {string} The translated label.
		 * @spec exclude Pure i18n pass-through.
		 */
		translateLabel(label) {
			return t('keepiq', label)
		},

		/**
		 * Forward the open-state change; emit `close` when dismissed.
		 *
		 * @param {boolean} value The new open state.
		 * @return {void}
		 * @spec exclude Dialog open-state plumbing; no domain behaviour.
		 */
		onUpdateOpen(value) {
			this.open = value
			if (!value) {
				this.$emit('close')
			}
		},

		/**
		 * Tell the parent of the saved folder.
		 *
		 * @param {object} folder The saved folder.
		 * @return {void}
		 * @spec exclude Event re-emitter; no domain behaviour.
		 */
		onFolderSaved(folder) {
			this.$emit('saved', folder)
		},

		/**
		 * Run one store write while the dialog shows it saving: on success
		 * report the folder and close; on failure show the server's message,
		 * the error's own, or the fallback text, and stay open.
		 *
		 * @param {function(): Promise<object>} write The store write; resolves with the folder.
		 * @param {string} failure The text to show when the failure carries none.
		 * @return {Promise<void>}
		 * @spec openspec/specs/secrets/spec.md#requirement-folder-management
		 */
		async saveFolder(write, failure) {
			this.saving = true
			this.error = ''
			try {
				this.onFolderSaved(await write())
				this.onUpdateOpen(false)
			} catch (e) {
				this.error = e?.response?.data?.message || e?.message || failure
			} finally {
				this.saving = false
			}
		},
	},
}
