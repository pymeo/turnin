import type { Page } from '@playwright/test';
import { expect, onboardWorker, openAddSheet, openCalendar, test } from './support/worker';

/*
 * "Me interesa" → which of my own shifts do I want covered.
 *
 * The screen this replaced was a vertical list of near-identical cards, so the
 * assertions here are about the things a list cannot show: the days around a
 * shift, a run of days off, and a suggestion that says why. It runs on the
 * narrowest phone we support and on a desktop, because the layout is genuinely
 * different on each and both have to survive.
 */
const GROUP = { category: 'TCAE', destination: 'Urgencias' };

/** Days counted from the Monday of the current week, like the screen does. */
function day(offset: number): string {
	const today = new Date();
	const monday = new Date(today);
	monday.setDate(today.getDate() - ((today.getDay() + 6) % 7) + offset);
	return `${monday.getFullYear()}-${String(monday.getMonth() + 1).padStart(2, '0')}-${String(monday.getDate()).padStart(2, '0')}`;
}

/**
 * A whole week in one visit. Setting seven days through the day sheet means
 * seven page loads, which is the difference between this test finishing and
 * this test timing out while the rest of the suite runs beside it.
 */
async function paintRota(page: Page, working: string[], rest: string[]): Promise<void> {
	const months = [...new Set([...working, ...rest].map((date) => date.slice(0, 7)))];
	for (const month of months) {
		const workingHere = working.filter((date) => date.startsWith(month));
		const restHere = rest.filter((date) => date.startsWith(month));
		await openCalendar(page, month);
		await openAddSheet(page);
		await page.getByRole('button', { name: /Pintar calendario/ }).click();
		const palette = page.locator('[data-calendar-paint-target="panel"]');
		await expect(palette).toBeVisible();

		// Addressed by the code they paint with: the accessible name is
		// recomputed on every poll. Same reason as calendar.spec.ts.
		if (workingHere.length) {
			await palette.locator('[data-paint-code="M"]').click();
			for (const date of workingHere) await page.locator(`[data-day="${date}"]`).click();
		}
		if (restHere.length) {
			await palette.locator('[data-paint-code="L"]').click();
			for (const date of restHere) await page.locator(`[data-day="${date}"]`).click();
		}

		await page.locator('[data-calendar-paint-target="save"]').click();
		const preview = page.locator('[data-schedule-draft-target="sheet"]');
		await expect(preview).toBeVisible();
		await preview.locator('[data-schedule-draft-target="confirm"]').click();
		await expect(preview).not.toBeVisible();
		for (const date of workingHere) await expect(page.locator(`[data-day="${date}"]`)).toHaveAttribute('data-state', 'working');
		for (const date of restHere) await expect(page.locator(`[data-day="${date}"]`)).toHaveAttribute('data-state', 'rest');
	}
}

async function paintDay(page: Page, date: string, label: string, expected: 'working' | 'rest'): Promise<void> {
	await page.goto(`/app/calendar?month=${date.slice(0, 7)}`);
	await page.waitForSelector('[data-calendar-target="grid"]');
	const cell = page.locator(`[data-day="${date}"]`);
	await cell.click();
	const sheet = page.locator('[data-calendar-target="daySheet"]');
	await expect(sheet).toBeVisible();
	await sheet.locator('.picker-chip').filter({ hasText: label }).first().click();
	await expect(sheet).not.toBeVisible();
	await expect(cell).toHaveAttribute('data-state', expected);
}

async function publish(page: Page, date: string): Promise<void> {
	await page.goto('/app/changes?flow=release');
	const sheet = page.locator('[data-changes-target="sheet"]');
	await expect(sheet).toBeVisible();
	await sheet.locator('.shift-choice').filter({ hasText: String(Number(date.slice(8))) }).first().click();
	await sheet.getByRole('button', { name: 'Buscar compañero' }).click();
	await expect(sheet).toContainText('Estamos buscando a alguien');
}

test.describe('choosing what to ask for in return', () => {
	test.describe.configure({ mode: 'serial', timeout: 420_000 });
	test.skip(
		() => !['mobile-375', 'desktop'].includes(test.info().project.name),
		'The journey runs on the narrowest phone and on a desktop: those are the two layouts.',
	);

	test('María picks a shift off her own calendar and the list agrees with it', async ({ browser }, testInfo) => {
		const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
		const pedroContext = await browser.newContext({ baseURL });
		const mariaContext = await browser.newContext({ baseURL });
		const pedro = await pedroContext.newPage();
		const maria = await mariaContext.newPage();
		try {
			await onboardWorker(pedro, testInfo, 'composer-pedro', GROUP);
			await onboardWorker(maria, testInfo, 'composer-maria', GROUP);

			// María works Monday to Thursday next week and is off the weekend,
			// so handing the Thursday over buys her four days in a row.
			await paintRota(maria, [7, 8, 9, 10].map(day), [11, 12, 13].map(day));

			await paintDay(pedro, day(19), 'Noche', 'working');
			await publish(pedro, day(19));

			await maria.goto('/app/changes/available');
			await maria.getByRole('link', { name: 'Me interesa' }).first().click();

			// ── The question, and the calendar that answers it ──────────────
			await expect(maria.getByRole('heading', { name: /¿Qué turno quieres que .* haga por ti\?/ })).toBeVisible();
			const calendar = maria.locator('[data-swap-composer-target="calendarPanel"]');
			await expect(calendar).toBeVisible();
			await expect(maria.locator('[data-swap-composer-target="listPanel"]')).toBeHidden();
			await expect(maria.locator('.shift-day')).toHaveCount(28);
			await expect(maria.locator('.shift-day[data-state="rest"]').first()).toContainText('Libre');
			await expect(maria.locator('.shift-day[data-state="unknown"]').first()).toContainText('Sin datos');
			await expect(maria.locator(`.shift-day[data-date="${day(19)}"][data-state="incoming"]`)).toHaveCount(1);

			// ── The suggestion, and why ─────────────────────────────────────
			await expect(maria.locator('.reco-card')).toHaveCount(1);
			await expect(maria.locator('.reco-card')).toContainText('4 días seguidos libres');
			await expect(maria.locator(`.shift-day[data-date="${day(10)}"] .shift-day-badge`)).toBeVisible();

			// Nothing scrolls sideways on either layout.
			const overflow = await maria.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
			expect(overflow).toBeLessThanOrEqual(1);
			await maria.screenshot({ path: testInfo.outputPath(`composer-${testInfo.project.name}.png`), fullPage: true });

			// ── Choosing a day opens its detail; it does not send anything ──
			await maria.locator(`.shift-day[data-date="${day(10)}"]`).click();
			const sheet = maria.locator('[data-swap-composer-target="sheet"]');
			await expect(sheet).toBeVisible();
			await expect(sheet).toContainText('Diferencia para ti');
			await sheet.screenshot({ path: testInfo.outputPath(`composer-sheet-${testInfo.project.name}.png`) });
			await sheet.getByRole('button', { name: 'Elegir este turno' }).click();
			await expect(sheet).not.toBeVisible();
			await expect(maria.locator('[data-swap-composer-target="recap"]')).toContainText('Propones');

			// ── The list is the same choice, not a second one ───────────────
			const chosen = await maria.locator(`.shift-day[data-date="${day(10)}"]`).getAttribute('data-shift-key');
			expect(chosen).toBeTruthy();
			await maria.getByRole('button', { name: 'Lista' }).click();
			await expect(maria.locator('[data-swap-composer-target="listPanel"]')).toBeVisible();
			await expect(maria.locator(`input[name="offeredShift"][value="${chosen}"]`)).toBeChecked();
			await maria.screenshot({ path: testInfo.outputPath(`composer-list-${testInfo.project.name}.png`), fullPage: true });

			// Choosing from the list drives exactly the same state, and moving
			// back to the calendar shows the new day pressed.
			const fromList = await maria.locator(`.shift-day[data-date="${day(8)}"]`).getAttribute('data-shift-key');
			// The label, not the radio: the input is screen-reader-only, which is
			// also how a person picks it.
			await maria.locator(`.trade-option[data-shift-key="${fromList}"]`).click();
			await expect(maria.locator(`input[name="offeredShift"][value="${fromList}"]`)).toBeChecked();
			await expect(maria.locator('[data-swap-composer-target="recap"]')).toContainText('Propones');
			await maria.getByRole('button', { name: 'Calendario' }).click();
			await expect(maria.locator(`.shift-day[data-date="${day(8)}"]`)).toHaveAttribute('aria-pressed', 'true');
			await expect(maria.locator(`.shift-day[data-date="${day(10)}"]`)).toHaveAttribute('aria-pressed', 'false');

			// Back to the one with the rest block before continuing.
			await maria.locator(`.shift-day[data-date="${day(10)}"]`).click();
			await sheet.getByRole('button', { name: 'Elegir este turno' }).click();
			await expect(sheet).not.toBeVisible();

			// ── And the existing confirmation flow still follows ────────────
			await maria.getByRole('button', { name: 'Continuar con este intercambio' }).click();
			await maria.waitForURL(/\/app\/changes\/proposals/);
			await expect(maria.locator('body')).toContainText('Tú entregas');
			await expect(maria.locator('.proposal-card').first()).toContainText('08:00–15:00');
		} finally {
			await pedroContext.close();
			await mariaContext.close();
		}
	});
});
