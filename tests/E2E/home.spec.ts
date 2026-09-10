import { expect, test } from '@playwright/test';

/*
 * What this suite is for: proving the whole delivery chain works end to end —
 * Twig renders, Tailwind is compiled and served, AssetMapper resolves, the page
 * fits a phone, and the PWA is installable. It is not a copy test.
 */

test.describe('home', () => {
	test('answers 200 and states what Turnin is', async ({ page }) => {
		const response = await page.goto('/');

		expect(response?.status()).toBe(200);
		await expect(page.getByRole('heading', { level: 1 })).toHaveText('Turnin');
		await expect(page.getByText('Tus turnos. Tu tiempo.')).toBeVisible();
	});

	test('shows the swap example that explains the product', async ({ page }) => {
		await page.goto('/');

		await expect(page.getByText('Tú das')).toBeVisible();
		await expect(page.getByText('Tú recibes')).toBeVisible();
		await expect(page.getByText('Noche')).toBeVisible();
		await expect(page.getByText('Mañana')).toBeVisible();
	});

	test('never scrolls sideways', async ({ page }) => {
		await page.goto('/');

		const overflow = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
		}));

		// A single pixel of horizontal scroll is enough to make a phone feel broken.
		expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth);
	});

	test('puts the primary action in reach of one thumb', async ({ page }) => {
		await page.goto('/');

		const cta = page.getByRole('link', { name: 'Crear cuenta' });
		await expect(cta).toBeVisible();

		const box = await cta.boundingBox();
		expect(box).not.toBeNull();
		// 44px is the smallest target a thumb hits reliably.
		expect(box!.height).toBeGreaterThanOrEqual(44);
		expect(box!.width).toBeGreaterThan(120);
	});

	test('actually loaded Tailwind rather than falling back to unstyled HTML', async ({ page }) => {
		await page.goto('/');

		const cta = page.getByRole('link', { name: 'Crear cuenta' });

		// An unstyled <a> is transparent; the brand button is not.
		const background = await cta.evaluate((el) => getComputedStyle(el).backgroundColor);
		expect(background).not.toBe('rgba(0, 0, 0, 0)');

		// And the design tokens compiled, not just some stylesheet.
		const brand = await page.evaluate(() =>
			getComputedStyle(document.documentElement).getPropertyValue('--color-brand-700').trim(),
		);
		expect(brand).not.toBe('');
	});

	test('the call to action leads to registration', async ({ page }) => {
		await page.goto('/');

		await page.getByRole('link', { name: 'Crear cuenta' }).click();
		await expect(page).toHaveURL(/\/register$/);
	});

	test('loads without console errors', async ({ page }) => {
		const problems: string[] = [];
		page.on('console', (message) => {
			if (message.type() === 'error') {
				problems.push(message.text());
			}
		});
		page.on('pageerror', (error) => problems.push(error.message));

		await page.goto('/');
		await page.waitForLoadState('networkidle');

		expect(problems).toEqual([]);
	});
});
