import { test as base, type Page, type TestInfo } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';

const PROJECTS = ['mobile-375', 'mobile-390', 'mobile-430', 'tablet-768', 'desktop'];

function identityDocument(seed: number): string {
	const number = String(seed % 100_000_000).padStart(8, '0');
	const letters = 'TRWAGMYFPDXBNJZSQVHLCKE';
	return `${number}${letters[Number(number) % 23]}`;
}

/**
 * Registers an account and walks it through the real onboarding, because a
 * calendar belongs to a worker assignment and the assignment is what onboarding
 * creates. Doing it through the interface is the only way the test proves the
 * two slices actually fit together.
 */
export async function onboardWorker(page: Page, testInfo: TestInfo, label: string): Promise<void> {
	const offset = PROJECTS.indexOf(testInfo.project.name) + 1;
	const seed = (Date.now() + offset * 7919 + label.length * 104_729) % 100_000_000;
	const email = `${label}-${testInfo.project.name}-${seed}@example.test`;
	const password = 'E2e-calendar-2026';

	await page.goto('/register');
	await page.locator('input[name="email"]').fill(email);
	await page.locator('input[name="password"]').fill(password);
	await page.locator('input[name="password_repeat"]').fill(password);
	await page.getByRole('button', { name: 'Crear cuenta con email' }).click();

	await page.locator('input[name="_username"]').fill(email);
	await page.locator('input[name="_password"]').fill(password);
	await page.getByRole('button', { name: 'Iniciar sesión' }).click();
	await page.waitForURL(/\/onboarding$/);

	await page.locator('input[name="given_name"]').fill('Ana');
	await page.locator('input[name="family_name"]').fill('García');
	await page.getByRole('button', { name: 'Continuar' }).click();

	await page.locator('input[name="identity_document"]').fill(identityDocument(seed));
	await page.locator('input[name="phone"]').fill(`6${String(seed).padStart(8, '0').slice(-8)}`);
	await page.getByRole('button', { name: 'Continuar' }).click();

	const workplaceStep = page.locator('[data-step="workplace"]');
	await workplaceStep.getByRole('combobox').fill('Murcia');
	await workplaceStep.locator('[role="option"]').first().click();
	await workplaceStep.getByRole('button', { name: 'Continuar' }).click();

	const categoryStep = page.locator('[data-step="category"]');
	await categoryStep.getByRole('combobox').fill('TCAE');
	await categoryStep.locator('[role="option"]').first().click();
	await categoryStep.getByRole('button', { name: 'Continuar' }).click();

	// Whichever unit this centre suggests first: the calendar does not care
	// which destination the worker has, only that they have one.
	const destinationStep = page.locator('[data-step="destination"]');
	await destinationStep.locator('[role="option"]').first().click();
	await destinationStep.getByRole('button', { name: 'Continuar' }).click();

	// The label of this button depends on whether anything was picked
	// ("Omitir por ahora" / "Continuar"), so it is addressed by its target.
	const additionalStep = page.locator('[data-step="additional"]');
	await additionalStep.locator('[data-onboarding-target="additionalButton"]').click();

	await page.locator('[data-step="summary"]').getByRole('button', { name: 'Entrar en Turnin' }).click();
	await page.waitForURL(/\/app$/);
}

/**
 * One onboarded worker per Playwright worker, reused by every test it runs.
 *
 * Registering and onboarding is by far the most expensive thing these specs do,
 * and repeating it per test put five browsers through seven form steps at once.
 * Tests stay independent because each one works on its own month.
 */
export const test = base.extend<Record<string, never>, { workerStorageState: string }>({
	storageState: ({ workerStorageState }, use) => use(workerStorageState),

	workerStorageState: [
		async ({ browser }, use) => {
			const directory = path.resolve('var/playwright/auth');
			fs.mkdirSync(directory, { recursive: true });
			const file = path.join(directory, `worker-${test.info().parallelIndex}.json`);

			// A context built by hand inherits none of the project's options, and
			// `baseURL` is test-scoped so a worker fixture cannot ask for it.
			// Same source the config uses.
			const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
			const context = await browser.newContext({ baseURL, storageState: undefined });
			const page = await context.newPage();
			await onboardWorker(page, test.info(), `calendar${test.info().parallelIndex}`);
			await context.storageState({ path: file });
			await context.close();

			await use(file);
		},
		{ scope: 'worker' },
	],
});

export { expect } from '@playwright/test';

export async function openCalendar(page: Page, month: string): Promise<void> {
	await page.goto(`/app/calendar?month=${month}`);
	await page.waitForSelector('[data-calendar-target="grid"]');
}

/**
 * The dock CTA. The empty state has a button with the same words, which is the
 * point — there are two ways to reach the same sheet — so tests address the
 * permanent one.
 */
export async function openAddSheet(page: Page): Promise<void> {
	await page.locator('[data-calendar-target="addButton"]').click();
}

export function cell(page: Page, month: string, day: number) {
	return page.locator(`[data-day="${month}-${String(day).padStart(2, '0')}"]`);
}
