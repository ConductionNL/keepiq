/** The recipient page shows this as plain text, so the labels stay English. */
export function serializeCredential(username: string, password: string): string {
	return `Username: ${username}\nPassword: ${password}`
}
