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
		await expect(page.getByRole('heading', { level: 1 })).toHaveText('Tus turnos deberían adaptarse a tu vida.');
		await expect(page.getByText('Encuentra compañeros compatibles y organiza tus turnos de una forma mucho más sencilla.')).toBeVisible();
	});

	test('shows the three steps that explain the product', async ({ page }) => {
		await page.goto('/');

		await expect(page.getByText('1. Tu turno')).toBeVisible();
		await expect(page.getByText('2. Tu equipo')).toBeVisible();
		await expect(page.getByText('3. Más libertad')).toBeVisible();
		await expect(page.getByText('Turnin busca a alguien compatible.')).toBeVisible();
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

		const cta = page.getByRole('link', { name: 'Continuar con Google' });
		await expect(cta).toBeVisible();

		const box = await cta.boundingBox();
		expect(box).not.toBeNull();
		// 44px is the smallest target a thumb hits reliably.
		expect(box!.height).toBeGreaterThanOrEqual(44);
		expect(box!.width).toBeGreaterThan(120);
	});

	test('actually loaded Tailwind rather than falling back to unstyled HTML', async ({ page }) => {
		await page.goto('/');

		const cta = page.getByRole('link', { name: 'Continuar con Google' });

		// The principal action carries the concentrated brand gradient.
		const background = await cta.evaluate((el) => getComputedStyle(el).backgroundImage);
		expect(background).toContain('linear-gradient');

		// And the design tokens compiled, not just some stylesheet.
		const brand = await page.evaluate(() =>
			getComputedStyle(document.documentElement).getPropertyValue('--color-brand-700').trim(),
		);
		expect(brand).not.toBe('');
	});

	test('the call to action leads to registration', async ({ page }) => {
		await page.goto('/');

		await page.getByRole('link', { name: 'Empezar' }).click();
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
