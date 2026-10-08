export function ErrorBanner({ children }: { children: string | null }) {
	if (!children) return null
	return <p className="banner banner--error" role="alert">{children}</p>
}
