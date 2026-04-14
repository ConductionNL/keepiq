/**
 * Central configuration mapping secret type slugs to field definitions.
 *
 * Both CreateSecretDialog and SecretSidebar/SecretDetail consume this
 * so that labels, visibility, and input behaviour stay consistent.
 */

/**
 * Build the full type→field-config map.
 *
 * @param {Function} t  The Nextcloud translation function (usually `this.t`)
 * @return {object}     Map of type slug → field config
 */
function buildTypeFieldConfigs(t) {
	return {
		login: {
			name: {
				visible: true,
				label: t('doriath', 'Name'),
				placeholder: t('doriath', 'e.g. GitHub, AWS Console'),
				required: true,
				multiline: false,
				sensitive: false,
			},
			url: {
				visible: true,
				label: t('doriath', 'URL'),
				placeholder: t('doriath', 'e.g. https://github.com'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			login: {
				visible: true,
				label: t('doriath', 'Username / Login'),
				placeholder: t('doriath', 'e.g. user@example.com'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			key: {
				visible: true,
				label: t('doriath', 'Password'),
				placeholder: '',
				required: true,
				multiline: false,
				sensitive: true,
				showStrengthMeter: true,
				showGenerator: true,
			},
		},

		api_key: {
			name: {
				visible: true,
				label: t('doriath', 'Name'),
				placeholder: t('doriath', 'e.g. OpenAI, Stripe'),
				required: true,
				multiline: false,
				sensitive: false,
			},
			url: {
				visible: true,
				label: t('doriath', 'URL'),
				placeholder: t('doriath', 'e.g. https://api.openai.com'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			login: {
				visible: true,
				label: t('doriath', 'Service / Account'),
				placeholder: t('doriath', 'e.g. production, team-account'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			key: {
				visible: true,
				label: t('doriath', 'API Key'),
				placeholder: '',
				required: true,
				multiline: false,
				sensitive: true,
				showStrengthMeter: false,
				showGenerator: false,
			},
		},

		ssh_key: {
			name: {
				visible: true,
				label: t('doriath', 'Name'),
				placeholder: t('doriath', 'e.g. Production Server, GitHub Deploy'),
				required: true,
				multiline: false,
				sensitive: false,
			},
			url: {
				visible: true,
				label: t('doriath', 'Host'),
				placeholder: t('doriath', 'e.g. server.example.com'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			login: {
				visible: true,
				label: t('doriath', 'Username'),
				placeholder: t('doriath', 'e.g. root, deploy'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			key: {
				visible: true,
				label: t('doriath', 'Private Key'),
				placeholder: '',
				required: true,
				multiline: true,
				sensitive: true,
				showStrengthMeter: false,
				showGenerator: false,
			},
		},

		certificate: {
			name: {
				visible: true,
				label: t('doriath', 'Name'),
				placeholder: t('doriath', 'e.g. wildcard.example.com, Client Auth'),
				required: true,
				multiline: false,
				sensitive: false,
			},
			url: {
				visible: false,
				label: '',
				placeholder: '',
				required: false,
				multiline: false,
				sensitive: false,
			},
			login: {
				visible: true,
				label: t('doriath', 'Subject / CN'),
				placeholder: t('doriath', 'e.g. *.example.com'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			key: {
				visible: true,
				label: t('doriath', 'Certificate / Private Key'),
				placeholder: '',
				required: true,
				multiline: true,
				sensitive: true,
				showStrengthMeter: false,
				showGenerator: false,
			},
		},

		note: {
			name: {
				visible: true,
				label: t('doriath', 'Name'),
				placeholder: t('doriath', 'e.g. Recovery codes, Wi-Fi password'),
				required: true,
				multiline: false,
				sensitive: false,
			},
			url: {
				visible: false,
				label: '',
				placeholder: '',
				required: false,
				multiline: false,
				sensitive: false,
			},
			login: {
				visible: false,
				label: '',
				placeholder: '',
				required: false,
				multiline: false,
				sensitive: false,
			},
			key: {
				visible: true,
				label: t('doriath', 'Note'),
				placeholder: '',
				required: true,
				multiline: true,
				sensitive: true,
				showStrengthMeter: false,
				showGenerator: false,
			},
		},

		database: {
			name: {
				visible: true,
				label: t('doriath', 'Name'),
				placeholder: t('doriath', 'e.g. Production MySQL, Staging Redis'),
				required: true,
				multiline: false,
				sensitive: false,
			},
			url: {
				visible: true,
				label: t('doriath', 'Host / Connection String'),
				placeholder: t('doriath', 'e.g. db.example.com:3306'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			login: {
				visible: true,
				label: t('doriath', 'Username'),
				placeholder: t('doriath', 'e.g. admin, app_user'),
				required: false,
				multiline: false,
				sensitive: false,
			},
			key: {
				visible: true,
				label: t('doriath', 'Password'),
				placeholder: '',
				required: true,
				multiline: false,
				sensitive: true,
				showStrengthMeter: true,
				showGenerator: true,
			},
		},
	}
}

/**
 * Resolve a typeId (UUID) to its field configuration.
 *
 * @param {object[]} types  Array of type objects from secretTypeStore (each has `id` and `name`)
 * @param {string|null} typeId  The UUID of the selected type, or null
 * @param {Function} t  The Nextcloud translation function
 * @return {object}  Field config for the resolved type (falls back to `login`)
 */
export function getTypeFieldConfig(types, typeId, t) {
	const configs = buildTypeFieldConfigs(t)
	if (!typeId) return configs.login
	const typeObj = types.find(tp => tp.id === typeId)
	const slug = typeObj?.name
	return configs[slug] || configs.login
}
