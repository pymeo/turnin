import { expect, test } from '@playwright/test';

test.describe('identity entry points', () => {
	for (const path of ['/login', '/register']) {
		test(`${path} keeps Google primary and remains usable on every viewport`, async ({ page }) => {
			await page.goto(path);

			const google = page.getByRole('link', { name: 'Continuar con Google' });
			await expect(google).toBeVisible();
			await expect(google).toHaveAttribute('href', '/auth/google');

			const box = await google.boundingBox();
			expect(box).not.toBeNull();
			expect(box!.height).toBeGreaterThanOrEqual(44);

			const overflow = await page.evaluate(() => ({
				scrollWidth: document.documentElement.scrollWidth,
				clientWidth: document.documentElement.clientWidth,
			}));
			expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth);
		});
	}

	test('Google remains one shared login and registration flow', async ({ page }) => {
		await page.goto('/login');
		const loginTarget = await page.getByRole('link', { name: 'Continuar con Google' }).getAttribute('href');
		await page.goto('/register');
		const registrationTarget = await page.getByRole('link', { name: 'Continuar con Google' }).getAttribute('href');

		expect(registrationTarget).toBe(loginTarget);
	});
});
