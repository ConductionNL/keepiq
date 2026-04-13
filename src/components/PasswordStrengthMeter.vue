<template>
	<div class="password-strength-meter">
		<div class="password-strength-meter__bar">
			<div
				class="password-strength-meter__fill"
				:class="`password-strength-meter__fill--${scoreClass}`"
				:style="{ width: `${(score / 4) * 100}%` }" />
		</div>
		<p class="password-strength-meter__feedback" :class="`password-strength-meter__feedback--${scoreClass}`">
			{{ feedbackText }}
		</p>
	</div>
</template>

<script>
import zxcvbn from 'zxcvbn'

export default {
	name: 'PasswordStrengthMeter',

	props: {
		password: {
			type: String,
			required: true,
		},
		minScore: {
			type: Number,
			default: 3,
		},
		minLength: {
			type: Number,
			default: 12,
		},
		enforcing: {
			type: Boolean,
			default: true,
		},
	},

	emits: ['strength-change'],

	data() {
		return {
			score: 0,
			feedback: null,
			debounceTimer: null,
		}
	},

	computed: {
		scoreClass() {
			const classes = ['danger', 'danger', 'warning', 'success', 'success']
			return classes[this.score] || 'danger'
		},

		feedbackText() {
			if (!this.password) return ''
			if (this.password.length < this.minLength) {
				return this.enforcing
					? t('doriath', 'At least {length} characters required', { length: this.minLength })
					: t('doriath', 'At least {length} characters recommended', { length: this.minLength })
			}
			if (this.feedback?.warning) return this.feedback.warning
			const labels = [
				t('doriath', 'Very weak'),
				t('doriath', 'Weak'),
				t('doriath', 'Fair'),
				t('doriath', 'Strong'),
				t('doriath', 'Very strong'),
			]
			return labels[this.score]
		},

		isValid() {
			return this.password.length >= this.minLength && this.score >= this.minScore
		},
	},

	watch: {
		password() {
			clearTimeout(this.debounceTimer)
			this.debounceTimer = setTimeout(() => this.evaluate(), 300)
		},
	},

	created() {
		this.evaluate()
	},

	methods: {
		countCharacterClasses(password) {
			let classes = 0
			if (/[a-z]/.test(password)) classes++
			if (/[A-Z]/.test(password)) classes++
			if (/[0-9]/.test(password)) classes++
			if (/[^a-zA-Z0-9]/.test(password)) classes++
			return classes
		},

		evaluate() {
			if (!this.password) {
				this.score = 0
				this.feedback = null
				this.$emit('strength-change', { isValid: false, score: 0 })
				return
			}
			const result = zxcvbn(this.password)
			let score = result.score
			let feedback = result.feedback

			const charClasses = this.countCharacterClasses(this.password)
			if (charClasses <= 1 && score > 1) {
				score = 1
				feedback = {
					warning: t('doriath', 'Use a mix of letters, numbers, and symbols'),
					suggestions: feedback.suggestions || [],
				}
			}

			this.score = score
			this.feedback = feedback
			this.$emit('strength-change', { isValid: this.isValid, score: this.score })
		},
	},
}
</script>

<style scoped>
.password-strength-meter__bar {
	height: 4px;
	background: var(--color-background-dark);
	border-radius: 2px;
	overflow: hidden;
}

.password-strength-meter__fill {
	height: 100%;
	transition: width 0.3s ease, background 0.3s ease;
}

.password-strength-meter__fill--danger { background: var(--color-error); }

.password-strength-meter__fill--warning { background: var(--color-warning); }

.password-strength-meter__fill--success { background: var(--color-success); }

.password-strength-meter__feedback {
	font-size: 0.85rem;
	margin: 0.25rem 0 0;
}

.password-strength-meter__feedback--danger { color: var(--color-error); }

.password-strength-meter__feedback--warning { color: var(--color-warning); }

.password-strength-meter__feedback--success { color: var(--color-success); }
</style>
