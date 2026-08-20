<!--
  SPDX-License-Identifier: EUPL-1.2
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>

  Group-share form. The owner enters a Nextcloud group id; the parent
  view supplies the plaintext snapshot and the resolved member list +
  certificates. This component owns the per-member encryption loop and
  emits a `shared` event with the array of recipient envelopes the
  parent can hand to `useShareStore.createBatchShares`.

  @spec openspec/changes/implement-user-sharing/tasks.md#task-12.3
-->
<template>
	<section class="doriath-group-share-form" data-testid="group-share-form">
		<header>
			<h4>{{ t('doriath', 'Share with a group') }}</h4>
		</header>
		<form @submit.prevent="onSubmit">
			<label class="doriath-group-share-form__field">
				<span>{{ t('doriath', 'Group ID') }}</span>
				<input
					v-model.trim="groupId"
					type="text"
					required
					autocomplete="off"
					data-testid="group-share-form-group" />
			</label>

			<p
				v-if="memberCount > 0"
				class="doriath-group-share-form__hint"
				data-testid="group-share-form-hint">
				{{
					t(
						'doriath',
						'Will share with {count} member(s) with an active suite.',
						{ count: memberCount },
					)
				}}
			</p>

			<p
				v-if="error"
				class="doriath-group-share-form__error"
				data-testid="group-share-form-error">
				{{ error }}
			</p>

			<div class="doriath-group-share-form__actions">
				<button
					type="button"
					data-testid="group-share-form-cancel"
					@click="$emit('cancel')">
					{{ t('doriath', 'Cancel') }}
				</button>
				<button
					type="submit"
					class="primary"
					data-testid="group-share-form-submit"
					:disabled="busy || groupId === '' || memberCount === 0">
					{{
						busy
							? t('doriath', 'Sharing…')
							: t('doriath', 'Share with group')
					}}
				</button>
			</div>
		</form>
	</section>
</template>

<script>
import { useShareStore } from '../../store/modules/share.js'

export default {
	name: 'GroupShareForm',
	props: {
		secretId: {
			type: String,
			required: true,
		},

		plaintextSnapshot: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * Eligible members for the picked group. Each entry must include
		 * `userId` and `publicCertificate`; members without an active suite
		 * should already be filtered out by the parent / API.
		 */
		members: {
			type: Array,
			default: () => [],
		},
	},

	emits: ['cancel', 'shared'],
	data() {
		return {
			groupId: '',
			busy: false,
			error: null,
		}
	},

	computed: {
		memberCount() {
			return this.members.length
		},
	},

	methods: {
		async onSubmit() {
			this.error = null
			if (this.groupId === '') {
				this.error = t('doriath', 'Group is required')
				return
			}
			if (this.memberCount === 0) {
				this.error = t('doriath', 'No eligible members to share with')
				return
			}

			const store = useShareStore()
			this.busy = true
			try {
				const recipients = []
				for (const member of this.members) {
					const encryptedFields = await store.encryptForRecipient(
						this.plaintextSnapshot,
						member.publicCertificate,
					)
					recipients.push({
						targetUserId: member.userId,
						encryptedFields,
					})
				}
				this.$emit('shared', { groupId: this.groupId, recipients })
			} catch (e) {
				this.error =
					e?.message || t('doriath', 'Failed to encrypt for group')
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.doriath-group-share-form__field {
	display: flex;
	flex-direction: column;
	gap: 4px;
	margin-bottom: 12px;
}

.doriath-group-share-form__field input {
	padding: 8px;
	border: 1px solid var(--color-border-dark, #999);
	border-radius: var(--border-radius, 4px);
}

.doriath-group-share-form__hint {
	font-size: 13px;
	color: var(--color-text-maxcontrast, #777);
}

.doriath-group-share-form__error {
	color: var(--color-error-text);
	font-size: 13px;
}

.doriath-group-share-form__actions {
	display: flex;
	justify-content: flex-end;
	gap: 8px;
}

.doriath-group-share-form__actions .primary {
	background-color: var(--color-primary-element, #0082c9);
	color: var(--color-primary-element-text, #fff);
	border: 0;
	padding: 8px 16px;
	border-radius: var(--border-radius, 4px);
}
</style>
