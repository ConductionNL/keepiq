<template>
	<div class="secret-detail">
		<NcButton variant="tertiary" class="secret-detail__back" @click="goBack">
			<template #icon>
				<ArrowLeft :size="20" />
			</template>
			{{ t('doriath', 'Back to vault') }}
		</NcButton>

		<NcLoadingIcon v-if="loading" :size="32" class="secret-detail__loading" />

		<NcEmptyContent
			v-else-if="error"
			:name="t('doriath', 'Cannot open secret')"
			:description="error">
			<template #icon>
				<Lock />
			</template>
		</NcEmptyContent>

		<div v-else-if="secret" class="secret-detail__card">
			<h2 class="secret-detail__title">
				{{ secret.name }}
				<!-- Write-grade badge (folder-permission-grades §4.3): the
				     member knows an edit propagates to the whole team. -->
				<span
					v-if="teamWritable"
					class="secret-detail__team-badge"
					data-testid="team-writable-badge">
					{{ t('doriath', 'Editable — changes sync to the whole team') }}
				</span>
			</h2>

			<!--
			  Placed above the value, not below it: the point is that the user
			  reads this BEFORE they copy the password and carry on as if it were
			  still good. Not dismissible — it clears when the value is actually
			  replaced, which is what clears the flag server-side.
			-->
			<NcNoteCard
				v-if="secret.possiblyCompromisedAt"
				type="error"
				data-testid="secret-detail-possibly-compromised">
				<p>
					{{
						t(
							'doriath',
							'This value was in the vault when the encryption key was declared compromised, so it must be assumed exposed.',
						)
					}}
				</p>
				<p>
					{{
						t(
							'doriath',
							'Change it at its source, then save the new value here. Saving a new value clears this warning.',
						)
					}}
				</p>
			</NcNoteCard>

			<div v-if="secret.url" class="secret-detail__field">
				<span class="secret-detail__label">{{ t('doriath', 'URL') }}</span>
				<a :href="secret.url" target="_blank" rel="noopener noreferrer">{{
					secret.url
				}}</a>
			</div>

			<div v-if="secret.login" class="secret-detail__field">
				<span class="secret-detail__label">{{ t('doriath', 'Login') }}</span>
				<span class="secret-detail__value">{{ secret.login }}</span>
				<CopyButton
					:value="secret.login"
					:label="t('doriath', 'Copy login')" />
			</div>

			<div class="secret-detail__field">
				<span class="secret-detail__label">{{ keyLabel }}</span>
				<PasswordField :label="keyLabel" :resolve="resolveKey" />
			</div>

			<div
				v-if="isTotp"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'One-time code')
				}}</span>
				<TotpDisplay
					:seed="secret.key || ''"
					data-testid="secret-detail-totp" />
			</div>

			<div
				v-if="isPasskey"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Passkey')
				}}</span>
				<PasskeyDisplay
					:credentialJson="secret.key || ''"
					data-testid="secret-detail-passkey" />
			</div>

			<div
				v-if="isCard"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Payment card')
				}}</span>
				<CardDisplay
					:payloadJson="secret.key || ''"
					data-testid="secret-detail-card" />
			</div>

			<div
				v-if="isIdentity"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Identity')
				}}</span>
				<IdentityDisplay
					:payloadJson="secret.key || ''"
					data-testid="secret-detail-identity" />
			</div>

			<div class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Attachments')
				}}</span>
				<AttachmentPanel :secretId="secretId" :canManage="isOwner" />
			</div>

			<div
				v-if="isOwner"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Version history')
				}}</span>
				<VersionHistoryPanel
					:secretId="secretId"
					:canManage="isOwner"
					@restored="load" />
			</div>

			<div
				v-if="isOwner"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Rotation & expiry')
				}}</span>
				<RotationPanel :secretId="secretId" :canManage="isOwner" />
			</div>

			<div
				v-if="isOwner"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Honey tripwire')
				}}</span>
				<HoneyPanel :secretId="secretId" />
			</div>

			<div
				v-if="hasAdditionalFields"
				class="secret-detail__field secret-detail__field--block">
				<span class="secret-detail__label">{{
					t('doriath', 'Additional fields')
				}}</span>
				<dl class="secret-detail__extra">
					<template
						v-for="(value, key) in secret.additionalFields"
						:key="key">
						<dt>
							{{ key }}
						</dt>
						<dd>
							{{ value }}
						</dd>
					</template>
				</dl>
			</div>

			<div
				v-if="offlineReadOnly"
				class="secret-detail__offline-note"
				data-testid="secret-detail-offline-note">
				{{
					t(
						'doriath',
						'Read-only while offline — reconnect to edit, move, share, or delete.',
					)
				}}
			</div>
			<div v-else class="secret-detail__actions">
				<NcButton variant="primary" @click="openEdit">
					<template #icon>
						<Pencil :size="20" />
					</template>
					{{ t('doriath', 'Edit') }}
				</NcButton>
				<NcButton variant="secondary" @click="openMove">
					<template #icon>
						<FolderMove :size="20" />
					</template>
					{{ t('doriath', 'Move') }}
				</NcButton>
				<NcButton variant="secondary" @click="openShare">
					<template #icon>
						<ShareVariant :size="20" />
					</template>
					{{ t('doriath', 'Share') }}
				</NcButton>
				<NcButton variant="error" @click="remove">
					<template #icon>
						<Delete :size="20" />
					</template>
					{{ t('doriath', 'Delete secret') }}
				</NcButton>
			</div>

			<!--
			  Sharing sidebar — §12.6 integration. Renders the RecipientList,
			  ShareRequestForm (recipient role), and DelegationManager
			  stand-alone primitives the §12.x build cycle shipped. Tab
			  visibility derives from the current user's role:
			    - owner / delegate → ShareList + DelegationManager;
			    - recipient        → ShareRequestForm (request that the
			                          owner share with a third party).
			-->
			<section
				v-if="canSeeSharing"
				class="secret-detail__sharing"
				data-testid="secret-detail-sharing">
				<h3 class="secret-detail__sharing-heading">
					{{ t('doriath', 'Sharing') }}
				</h3>

				<ShareList
					v-if="isOwner"
					:secretId="secretId"
					data-testid="secret-detail-share-list" />

				<DelegationManager
					v-if="isOwner"
					:secretId="secretId"
					:canReclaim="true"
					data-testid="secret-detail-delegation-manager"
					@reclaimed="onReclaimed" />

				<ShareRequestForm
					v-if="isRecipient && !isOwner"
					:secretId="secretId"
					data-testid="secret-detail-share-request" />

				<!--
				  Vault-admin takeover (user-sharing spec.md § Ownership
				  Delegation). Only offered to a vault admin looking at
				  somebody else's secret — the owner has ShareList and
				  DelegationManager above instead. The server re-checks group
				  membership AND that the admin already holds a share, so this
				  condition decides what to SHOW, never what is allowed.
				-->
				<AdminHandoverPanel
					v-if="!isOwner"
					:secretId="secretId"
					data-testid="secret-detail-admin-handover" />
			</section>

			<!--
			  Requests section — implement-secret-requests §8.4. Owners see
			  a paginated list of pending/fulfilled/locked SecretRequests +
			  a "Request fill-in" button that opens SecretRequestCreateDialog
			  for write-without-read filling.
			-->
			<section
				v-if="isOwner"
				class="secret-detail__requests"
				data-testid="secret-detail-requests">
				<h3 class="secret-detail__requests-heading">
					{{ t('doriath', 'Requests') }}
				</h3>

				<div class="secret-detail__requests-actions">
					<!-- This action always targets THIS Secret, so the label says which
					     of the two things it does. Asking for a credential you do not
					     have yet starts from the vault, not from inside a Secret. -->
					<NcButton
						variant="secondary"
						data-testid="secret-detail-request-create"
						@click="openRequestCreate">
						{{
							secretHasValue
								? t('doriath', 'Ask for new values')
								: t('doriath', 'Ask someone to fill this in')
						}}
					</NcButton>
				</div>

				<SecretRequestList
					:secretId="secretId"
					data-testid="secret-detail-request-list" />

				<SecretRequestCreateDialog
					v-if="requestDialogOpen"
					:open="requestDialogOpen"
					:secret="secret"
					:isReRequest="secretHasValue"
					data-testid="secret-detail-request-dialog"
					@update:open="requestDialogOpen = $event"
					@created="onRequestCreated" />
			</section>

			<!--
			  Activity section — add-secret-audit-trail §5.2. The audit trail
			  for this secret, owner-scoped, newest first.
			-->
			<SecretActivityTab
				v-if="isOwner"
				:secretId="secretId"
				data-testid="secret-detail-activity" />
		</div>
	</div>
</template>

<script>
import { NcButton, NcEmptyContent, NcLoadingIcon, NcNoteCard } from '@nextcloud/vue'
import ArrowLeft from 'vue-material-design-icons/ArrowLeft.vue'
import Delete from 'vue-material-design-icons/Delete.vue'
import FolderMove from 'vue-material-design-icons/FolderMove.vue'
import Lock from 'vue-material-design-icons/Lock.vue'
import Pencil from 'vue-material-design-icons/Pencil.vue'
import ShareVariant from 'vue-material-design-icons/ShareVariant.vue'
import AttachmentPanel from '../components/AttachmentPanel.vue'
import CardDisplay from '../components/CardDisplay.vue'
import CopyButton from '../components/CopyButton.vue'
import HoneyPanel from '../components/HoneyPanel.vue'
import IdentityDisplay from '../components/IdentityDisplay.vue'
import PasskeyDisplay from '../components/PasskeyDisplay.vue'
import PasswordField from '../components/PasswordField.vue'
import RotationPanel from '../components/RotationPanel.vue'
import SecretActivityTab from '../components/SecretActivityTab.vue'
import SecretRequestList from '../components/secretRequest/SecretRequestList.vue'
import AdminHandoverPanel from '../components/share/AdminHandoverPanel.vue'
import DelegationManager from '../components/share/DelegationManager.vue'
import ShareList from '../components/share/ShareList.vue'
import ShareRequestForm from '../components/share/ShareRequestForm.vue'
import TotpDisplay from '../components/TotpDisplay.vue'
import VersionHistoryPanel from '../components/VersionHistoryPanel.vue'
import SecretRequestCreateDialog from '../dialogs/SecretRequestCreateDialog.vue'
import { useOfflineStore } from '../store/modules/offline.js'
import { useSecretStore } from '../store/modules/secret.js'
import { useSecretTypeStore } from '../store/modules/secretType.js'

/**
 * The secret detail view. Encrypted fields are decrypted client-side on load
 * (login + additionalFields) while the key stays masked until revealed.
 */
export default {
	name: 'SecretDetail',

	components: {
		NcButton,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		ArrowLeft,
		Delete,
		Lock,
		Pencil,
		FolderMove,
		ShareVariant,
		CopyButton,
		PasswordField,
		TotpDisplay,
		PasskeyDisplay,
		AttachmentPanel,
		VersionHistoryPanel,
		RotationPanel,
		HoneyPanel,
		CardDisplay,
		IdentityDisplay,
		AdminHandoverPanel,
		DelegationManager,
		ShareList,
		ShareRequestForm,
		SecretRequestCreateDialog,
		SecretRequestList,
		SecretActivityTab,
	},

	inject: {
		/**
		 * Open a registry-registered modal. Provided by CnAppRoot; defaults to a
		 * no-op so the view still mounts in isolation.
		 */
		cnOpenModal: { default: () => () => {} },
	},

	data() {
		return {
			secret: null,
			loading: true,
			error: '',
			requestDialogOpen: false,
			teamWritable: false,
		}
	},

	computed: {
		/**
		 * Whether this Secret already holds a value.
		 *
		 * Decides both the label and whether the request is a re-request: asking
		 * for values that exist overwrites them in place, which is exactly what a
		 * re-request is for. An empty placeholder awaiting its first fill is not
		 * an overwrite, so it must not be labelled or treated as one.
		 *
		 * @return {boolean} True when a value is present.
		 *
		 * @spec openspec/changes/request-first-secret-requests/specs/secret-requests/spec.md#requirement-create-secret-request
		 */
		secretHasValue() {
			return String(this.secret?.key || '') !== ''
		},

		secretId() {
			return this.$route.params.id
		},

		hasAdditionalFields() {
			return (
				this.secret
				&& this.secret.additionalFields
				&& typeof this.secret.additionalFields === 'object'
			)
		},

		keyLabel() {
			const typeStore = useSecretTypeStore()
			const type = this.secret ? typeStore.typesById[this.secret.typeId] : null
			if (type && type.name === 'note') {
				return t('doriath', 'Note')
			}
			return t('doriath', 'Key')
		},

		/**
		 * True when this secret is a `totp` type — its decrypted `key` holds an
		 * authenticator seed and the client renders a live one-time code.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/add-totp-secrets/specs/secrets/spec.md#requirement-client-side-totp-code-generation
		 */
		isTotp() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'totp'
		},

		/**
		 * Whether this secret is a `passkey` credential: the encrypted `key`
		 * holds the canonical CXF-aligned credential JSON and the client
		 * renders the passkey presentation with the private key masked.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/passkey-item-type/specs/passkey-item-type/spec.md#requirement-passkey-listing-filtering-and-site-associated-presentation
		 */
		isPasskey() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'passkey'
		},

		/**
		 * Whether this secret is a `card` type — the decrypted `key` holds
		 * a composite payment-card payload rendered with per-field masking
		 * (card-identity-items §4).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		isCard() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'card'
		},

		/**
		 * Whether this secret is an `identity` type (card-identity-items §4).
		 *
		 * @return {boolean}
		 * @spec openspec/specs/card-identity-items/spec.md#requirement-type-specific-presentation-and-masked-reveal
		 */
		isIdentity() {
			if (!this.secret) {
				return false
			}
			const type = useSecretTypeStore().typesById[this.secret.typeId]
			return Boolean(type) && type.name === 'identity'
		},

		/**
		 * The current Nextcloud user ID, or null when unauthenticated.
		 *
		 * @return {string|null}
		 */
		currentUserId() {
			return window.OC?.currentUser ?? null
		},

		/**
		 * True when the current user owns the secret. Owners see the
		 * recipient list + delegation manager.
		 *
		 * @return {boolean}
		 */
		isOwner() {
			if (this.secret === null || this.currentUserId === null) {
				return false
			}
			// Backend serializes ownerType / ownerId on the Secret entity;
			// fallback to userId for legacy responses.
			const owner =
				this.secret.ownerId ?? this.secret.owner_id ?? this.secret.userId
			return owner === this.currentUserId
		},

		/**
		 * Whether the vault is served from the offline cache (read-only) —
		 * all write actions on the detail are hidden (offline-readonly-cache §4.2).
		 *
		 * @return {boolean}
		 */
		offlineReadOnly() {
			return useOfflineStore().readOnly
		},

		/**
		 * True when the current user is a non-owner recipient — they
		 * see the share-request form so they can ask the owner to
		 * share with a third party.
		 *
		 * @return {boolean}
		 */
		isRecipient() {
			return this.secret !== null && this.isOwner === false
		},

		/**
		 * Show the sharing section whenever the role is known (owner
		 * or recipient).
		 *
		 * @return {boolean}
		 */
		canSeeSharing() {
			return this.isOwner === true || this.isRecipient === true
		},
	},

	async mounted() {
		await useSecretTypeStore().fetchTypes()
		await this.load()
	},

	methods: {
		t,

		/**
		 * Load and decrypt the secret.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loading = true
			this.error = ''
			try {
				this.secret = await useSecretStore().fetchSecret(this.secretId)
				// Write-grade badge (folder-permission-grades §4.3) — a
				// copy the user may team-write shows the sync warning.
				try {
					const { useShareStore } =
						await import('../store/modules/share.js')
					const context = await useShareStore().fetchWriteContext(
						this.secretId,
					)
					this.teamWritable =
						context.effectiveGrade === 'write'
						&& context.sourceSecretId !== this.secretId
				} catch {
					this.teamWritable = false
				}
			} catch (e) {
				if (e?.response?.status === 403) {
					this.error = t(
						'doriath',
						'This secret is locked because its encryption suite was revoked.',
					)
				} else {
					this.error =
						e?.response?.data?.message
						|| t('doriath', 'Failed to load secret')
				}
			} finally {
				this.loading = false
			}
		},

		/**
		 * Resolve the decrypted key for the password field.
		 *
		 * @return {Promise<string>}
		 */
		async resolveKey() {
			return this.secret ? this.secret.key || '' : ''
		},

		/**
		 * Open the edit dialog for this secret and reload on success.
		 *
		 * @return {void}
		 */
		openEdit() {
			this.cnOpenModal('secret-edit', {
				secretId: this.secretId,
				onSaved: () => this.load(),
			})
		},

		/**
		 * Open the move dialog for this secret and reload on success.
		 *
		 * @return {void}
		 */
		openMove() {
			this.cnOpenModal('secret-move', {
				secretId: this.secretId,
				currentFolderId: this.secret ? this.secret.folderId || null : null,
				onSaved: () => this.load(),
			})
		},

		/**
		 * Open the share dialog for this secret.
		 *
		 * @return {void}
		 */
		openShare() {
			this.cnOpenModal('secret-share', {
				secretId: this.secretId,
			})
		},

		/**
		 * Delete the secret and return to the vault.
		 *
		 * @return {Promise<void>}
		 */
		async remove() {
			await useSecretStore().deleteSecret(this.secretId)
			this.goBack()
		},

		/**
		 * Navigate back to the vault list.
		 *
		 * @return {void}
		 */
		goBack() {
			this.$router.push('/secrets')
		},

		/**
		 * Refresh the secret detail after a delegation reclaim so the
		 * sidebar caches stay consistent.
		 *
		 * @return {void}
		 */
		onReclaimed() {
			this.load()
		},

		/**
		 * Open the SecretRequestCreateDialog (§8.4 Requests section).
		 *
		 * @return {void}
		 */
		openRequestCreate() {
			this.requestDialogOpen = true
		},

		/**
		 * Close the dialog after the new request was created — the
		 * SecretRequestList re-fetches on its own via the store, so
		 * no extra refresh is required.
		 *
		 * @return {void}
		 */
		onRequestCreated() {
			this.requestDialogOpen = false
		},
	},
}
</script>

<style scoped>
.secret-detail {
	padding: 16px;
	max-width: 720px;
	margin: 0 auto;
}

.secret-detail__field {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 10px 0;
	border-bottom: 1px solid var(--color-border);
}

.secret-detail__field--block {
	display: block;
}

.secret-detail__label {
	width: 140px;
	flex: 0 0 auto;
	color: var(--color-text-maxcontrast);
}

.secret-detail__value {
	flex: 1 1 auto;
}

.secret-detail__actions {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin-top: 24px;
}

.secret-detail__sharing {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.secret-detail__sharing-heading {
	margin: 0 0 12px;
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.secret-detail__requests {
	margin-top: 24px;
	padding-top: 16px;
	border-top: 1px solid var(--color-border);
}

.secret-detail__requests-heading {
	margin: 0 0 12px;
	font-size: 1.1rem;
	color: var(--color-main-text);
}

.secret-detail__requests-actions {
	margin-bottom: 12px;
}

.secret-detail__team-badge {
	margin-inline-start: 8px;
	padding: 2px 10px;
	border-radius: var(--border-radius-pill, 12px);
	font-size: 12px;
	font-weight: 600;
	background-color: var(--color-primary-element-light, #dbe9f5);
	color: var(--color-main-text, #222);
	vertical-align: middle;
}
</style>
