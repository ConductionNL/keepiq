/** Type name → icon, after the web app's `typeIconName`; logins get a globe as in Bitwarden. */
const TYPE_ICONS = {
	login: 'globe',
	card: 'card',
	identity: 'idCard',
	note: 'note',
	totp: 'clock',
	passkey: 'passkey',
	api_key: 'code',
	ssh_key: 'terminal',
	certificate: 'shield',
	database: 'database',
} as const

export type TypeIcon = (typeof TYPE_ICONS)[keyof typeof TYPE_ICONS] | 'key'

export function typeIcon(typeName: string | undefined): TypeIcon {
	return (typeName && TYPE_ICONS[typeName as keyof typeof TYPE_ICONS]) || 'key'
}
