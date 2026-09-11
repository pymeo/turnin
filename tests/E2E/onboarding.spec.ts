import { expect, test } from '@playwright/test';

function identityDocument(seed: number): string {
	const number = String(seed % 100_000_000).padStart(8, '0');
	const letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
	return `${number}${letters[Number(number) % 23]}`;
}

test.describe('progressive onboarding', () => {
	test('continues from the name and exposes common destinations without typing', async ({ page }, testInfo) => {
		const offset = ['mobile-375', 'mobile-390', 'mobile-430', 'tablet-768', 'desktop'].indexOf(testInfo.project.name) + 1;
		const seed = (Date.now() + offset * 7919) % 100_000_000;
		const email = `onboarding-${testInfo.project.name}-${seed}@example.test`;
		const password = 'E2e-onboarding-2026';
		const problems: string[] = [];
		page.on('console', (message) => {
			if (message.type() === 'error') problems.push(message.text());
		});
		page.on('pageerror', (error) => problems.push(error.message));

		await page.goto('/register');
		await page.locator('input[name="email"]').fill(email);
		await page.locator('input[name="password"]').fill(password);
		await page.locator('input[name="password_repeat"]').fill(password);
		await page.getByRole('button', { name: 'Crear cuenta con email' }).click();
		await expect(page).toHaveURL(/\/login$/);
		await page.locator('input[name="_username"]').fill(email);
		await page.locator('input[name="_password"]').fill(password);
		await page.getByRole('button', { name: 'Iniciar sesión' }).click();
		await expect(page).toHaveURL(/\/onboarding$/);

		await page.locator('input[name="given_name"]').fill('Ana');
		await page.locator('input[name="family_name"]').fill('García');
		await page.getByRole('button', { name: 'Continuar' }).click();
		await expect(page.getByRole('heading', { name: '¿Cómo identificamos tu cuenta?' })).toBeVisible();

		await page.locator('input[name="identity_document"]').fill(identityDocument(seed));
		await page.locator('input[name="phone"]').fill(`6${String(seed).padStart(8, '0').slice(-8)}`);
		await page.getByRole('button', { name: 'Continuar' }).click();
		await expect(page.getByRole('heading', { name: '¿Dónde trabajas?' })).toBeVisible();

		const workplaceStep = page.locator('[data-step="workplace"]');
		await workplaceStep.getByRole('combobox').fill('Murcia');
		const firstWorkplace = workplaceStep.locator('[role="option"]').first();
		await expect(firstWorkplace).toContainText(/Hospital/i);
		await expect(firstWorkplace).toContainText('Murcia');
		await expect(workplaceStep.locator('[role="option"]').filter({ hasText: /Arrixaca|Morales Meseguer/i }).first()).toBeVisible();
		await firstWorkplace.click();
		await workplaceStep.getByRole('button', { name: 'Continuar' }).click();

		const categoryStep = page.locator('[data-step="category"]');
		await categoryStep.getByRole('combobox').fill('TCAE');
		await categoryStep.locator('[role="option"]').first().click();
		await categoryStep.getByRole('button', { name: 'Continuar' }).click();

		const destinationStep = page.locator('[data-step="destination"]');
		await expect(destinationStep.getByRole('option', { name: 'Urgencias' })).toBeVisible();
		await expect(destinationStep.getByRole('option', { name: 'Equipo volante' })).toBeVisible();
		await destinationStep.getByRole('button', { name: /Ver todas las opciones/ }).click();
		const destinationDialog = destinationStep.getByRole('dialog');
		await expect(destinationDialog.getByRole('heading', { name: 'Hospitalización' })).toBeVisible();
		await expect(destinationDialog.getByRole('heading', { name: 'Apoyo' })).toBeVisible();
		await destinationDialog.getByRole('option', { name: 'Urgencias' }).click();
		await expect(destinationDialog).not.toBeVisible();
		await destinationStep.getByRole('button', { name: 'Continuar' }).click();

		const additionalStep = page.locator('[data-step="additional"]');
		await expect(additionalStep).toContainText('Urgencias');
		await additionalStep.getByRole('combobox').fill('observacion');
		await additionalStep.getByRole('option', { name: 'Observación' }).click();
		await additionalStep.getByRole('combobox').fill('volante');
		await additionalStep.getByRole('option', { name: 'Equipo volante' }).click();
		await expect(additionalStep.getByRole('button', { name: 'Quitar Observación' })).toBeVisible();
		await expect(additionalStep.getByRole('button', { name: 'Quitar Equipo volante' })).toBeVisible();
		await additionalStep.getByRole('button', { name: 'Continuar' }).click();

		const summaryStep = page.locator('[data-step="summary"]');
		await expect(summaryStep).toContainText('TCAE · Urgencias');
		await expect(summaryStep).toContainText('Observación');
		await expect(summaryStep).toContainText('Equipo volante');
		await summaryStep.getByRole('button', { name: 'Entrar en Turnin' }).click();
		await expect(page).toHaveURL(/\/app$/);

		const overflow = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
		}));
		expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth);
		expect(problems).toEqual([]);
	});
});
