/** Always mounted, so screen readers announce each new message in the live region. */
export function Toast({ message }: { message: string | null }) {
	return (
		<div className="toast" role="status">
			{message && <p className="toast__message">{message}</p>}
		</div>
	)
}
