/** The app icon from `../img/app-store.svg`, inline so it follows the accent token. */
export function Logo({ size = 24 }: { size?: number }) {
	return (
		<svg className="logo" width={size} height={size} viewBox="0 0 512 512" aria-hidden="true">
			<polygon points="256,26 455.19,141 455.19,371 256,486 56.81,371 56.81,141" fill="currentColor" />
			<g transform="translate(136, 116) scale(10)" fill="#ffffff">
				<rect x="5" y="11" width="14" height="10" rx="2" />
				<path fillRule="evenodd" d="M12 2A5 5 0 0 0 7 7v4h2V7a3 3 0 0 1 6 0v4h2V7A5 5 0 0 0 12 2Z" />
			</g>
		</svg>
	)
}
