import { expect, test } from '@playwright/test';

/*
 * Installability and offline behaviour. These are the parts of a PWA that break
 * silently: nobody notices a bad manifest until a user cannot add the app to
 * their home screen.
 */

test.describe('pwa', () => {
	test('serves a manifest that a browser will accept', async ({ request }) => {
		const response = await request.get('/manifest.webmanifest');

		expect(response.status()).toBe(200);
		expect(response.headers()['content-type']).toContain('application/manifest+json');

		const manifest = await response.json();

		expect(manifest.name).toBeTruthy();
		expect(manifest.short_name).toBeTruthy();
		expect(manifest.start_url).toBe('/');
		expect(manifest.display).toBe('standalone');
		expect(manifest.theme_color).toBeTruthy();
		expect(manifest.background_color).toBeTruthy();

		// Android needs a 192 and a 512, plus a maskable one to avoid the
		// white-circle-with-a-shrunken-logo look.
		const sizes = manifest.icons.map((icon: { sizes: string }) => icon.sizes);
		expect(sizes).toContain('192x192');
		expect(sizes).toContain('512x512');
		expect(manifest.icons.some((icon: { purpose?: string }) => icon.purpose === 'maskable')).toBe(true);
	});

	test('every manifest icon actually exists', async ({ request }) => {
		const manifest = await (await request.get('/manifest.webmanifest')).json();

		for (const icon of manifest.icons as Array<{ src: string; type: string }>) {
			const response = await request.get(icon.src);
			expect(response.status(), `${icon.src} is referenced by the manifest`).toBe(200);
			expect(response.headers()['content-type']).toContain('image/');
		}
	});

	test('the page links the manifest and declares a mobile viewport', async ({ page }) => {
		await page.goto('/');

		await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', '/manifest.webmanifest');
		await expect(page.locator('meta[name="viewport"]')).toHaveAttribute(
			'content',
			/viewport-fit=cover/,
		);
		await expect(page.locator('link[rel="apple-touch-icon"]')).toHaveCount(1);
	});

	test('registers a service worker scoped to the whole origin', async ({ page }) => {
		await page.goto('/');

		const scope = await page.evaluate(async () => {
			const registration = await navigator.serviceWorker.ready;

			return registration.scope;
		});

		expect(scope).toMatch(/\/$/);
	});

	test('the service worker is never cached by the browser', async ({ request }) => {
		const response = await request.get('/sw.js');

		expect(response.status()).toBe(200);
		// A cached service worker cannot be replaced on the next deploy.
		expect(response.headers()['cache-control']).toContain('no-cache');
	});

	test('the offline fallback stands on its own', async ({ page }) => {
		// It must not depend on the asset pipeline: the network is gone when it
		// is needed.
		const requests: string[] = [];
		page.on('request', (request) => requests.push(request.url()));

		const response = await page.goto('/offline.html');

		expect(response?.status()).toBe(200);
		await expect(page.getByRole('heading', { name: 'Sin conexión' })).toBeVisible();

		const externalRequests = requests.filter((url) => !url.endsWith('/offline.html') && !url.startsWith('data:'));
		expect(externalRequests, 'the offline page must be self-contained').toEqual([]);
	});
});
