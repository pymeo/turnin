import { defineConfig, devices } from '@playwright/test';

/*
 * Turnin is a mobile-first PWA, so the suite runs on a small phone, a normal
 * phone and a desktop. The assertions are about behaviour and usability —
 * "does this fit on the screen", "can a thumb hit this" — never pixels, which
 * would break on every copy change without ever catching a real problem.
 */
const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';

export default defineConfig({
	testDir: './tests/E2E',
	// Generated output belongs under var/, like every other tool's.
	outputDir: './var/playwright/results',
	fullyParallel: true,
	forbidOnly: !!process.env.CI,
	retries: process.env.CI ? 1 : 0,
	reporter: process.env.CI ? [['github'], ['html', { outputFolder: 'var/playwright/report', open: 'never' }]] : [['list']],

	use: {
		baseURL,
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	// No `webServer` block: the application is a Docker stack, and `make test-e2e`
	// brings it up before calling Playwright. Having Playwright shell out to
	// `docker compose` would not work from inside the Playwright container, where
	// this suite actually runs — see docs/TESTING.md.

	projects: [
		{
			// The narrowest screen we intend to support. If the layout survives
			// here it survives anywhere.
			name: 'mobile-375',
			use: { ...devices['iPhone SE'] },
		},
		{
			name: 'mobile-390',
			use: { ...devices['iPhone 13'] },
		},
		{
			name: 'mobile-430',
			use: { ...devices['iPhone 14 Pro Max'] },
		},
		{
			name: 'tablet-768',
			use: { viewport: { width: 768, height: 1024 } },
		},
		{
			name: 'desktop',
			use: { ...devices['Desktop Chrome'], viewport: { width: 1280, height: 800 } },
		},
	],
});
