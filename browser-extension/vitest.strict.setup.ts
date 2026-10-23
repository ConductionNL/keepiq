import { configure } from '@testing-library/react'

// The dev build renders the popup in <StrictMode> (entrypoints/popup/main.tsx).
configure({ reactStrictMode: true })
