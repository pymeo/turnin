import type { Page } from '@playwright/test';
import { expect, onboardWorker, openAddSheet, openCalendar, test } from './support/worker';

/*
 * "Se lo hago" → I send several real return shifts; the owner picks one.
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

async function publish(page: Page, date: string): Promise<string> {
	await page.goto('/app/changes?flow=release');
	const sheet = page.locator('[data-changes-target="sheet"]');
	await expect(sheet).toBeVisible();
	await sheet.locator(`[data-day="${date}"]`).click();
	const published = page.waitForResponse((response) => response.url().endsWith('/app/changes/publicar') && response.request().method() === 'POST');
	await sheet.getByRole('button', { name: 'Buscar compañero' }).click();
	const payload = await (await published).json() as { result?: { requestId?: string } };
	await expect(sheet).toContainText('Estamos buscando a alguien');
	if (!payload.result?.requestId) throw new Error('Publishing did not return the new request id.');

	return payload.result.requestId;
}

test.describe('choosing what to ask for in return', () => {
	test.describe.configure({ mode: 'serial', timeout: 420_000 });
	test.skip(
		() => !['mobile-390', 'tablet-768', 'desktop'].includes(test.info().project.name),
		'The complete agreement journey is reviewed on phone, tablet and desktop.',
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
			await paintDay(maria, day(19), 'Mañana', 'working');
			const requestId = await publish(pedro, day(19));

			await maria.goto('/app/changes/available');
			await maria.locator(`a[href="/app/changes/${requestId}/intercambio"]`).click();

			// ── The question, and the calendar that answers it ──────────────
			await expect(maria.getByRole('heading', { name: /¿Qué turnos tuyos te gustaría que .* hiciera\?/ })).toBeVisible();
			const calendar = maria.locator('[data-swap-composer-target="calendarPanel"]');
			await expect(calendar).toBeVisible();
			await expect(maria.locator('[data-swap-composer-target="listPanel"]')).toBeHidden();
			await expect(maria.locator('.shift-day')).toHaveCount(28);
			await expect(maria.locator('.shift-day[data-state="rest"]').first()).toContainText('Libre');
			await expect(maria.locator('.shift-day[data-state="unknown"]').first()).toContainText('Sin datos');
			await expect(maria.locator(`.shift-day[data-date="${day(19)}"][data-state="combined-offerable"]`)).toHaveCount(1);
			await expect(maria.locator(`.shift-day[data-date="${day(19)}"]`)).toContainText('Tu turno');

			// ── The suggestion helps inside the calendar, without another card ──
			await expect(maria.locator('.reco-card')).toHaveCount(0);
			await expect(maria.locator(`.shift-day[data-date="${day(10)}"] .shift-day-badge`)).toBeVisible();

			// Nothing scrolls sideways on either layout.
			const overflow = await maria.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
			expect(overflow).toBeLessThanOrEqual(1);
			await maria.screenshot({ path: testInfo.outputPath(`composer-${testInfo.project.name}.png`), fullPage: true });

			// ── One tap selects directly; three taps build the visible list ──
			const selectedDates = [day(8), day(9), day(10)];
			for (const date of selectedDates) {
				const cell = maria.locator(`.shift-day[data-date="${date}"]`);
				await cell.click();
				await expect(cell).toHaveAttribute('aria-pressed', 'true');
				await expect(cell).toContainText('ELEGIDO');
			}
			await expect(maria.locator('[data-swap-composer-target="count"]')).toHaveText('3 de 5 elegidos');
			await expect(maria.locator('[data-swap-composer-target="chosen"] li')).toHaveCount(3);
			await expect(maria.locator('input[name="offeredShifts[]"]:checked')).toHaveCount(3);
			await expect(maria.getByRole('button', { name: /Enviar 3 opciones/ })).toBeEnabled();

			// Moving between months keeps the choices and never exposes the
			// internal assignment UUID while their cards are outside the window.
			await maria.getByRole('link', { name: 'Ver el mes siguiente' }).click();
			await expect(maria).toHaveURL(/semana=4/);
			await expect(maria.locator('[data-swap-composer-target="count"]')).toHaveText('3 de 5 elegidos');
			await expect(maria.locator('[data-swap-composer-target="chosen"] li')).toHaveCount(3);
			await expect(maria.locator('[data-swap-composer-target="chosen"]')).not.toContainText(/01[a-z0-9-]{20,}/);
			await maria.getByRole('link', { name: 'Ver el mes anterior' }).click();
			await expect(maria).not.toHaveURL(/semana=4/);
			await expect(maria.locator('[data-swap-composer-target="count"]')).toHaveText('3 de 5 elegidos');

			// ── Calendar and list are two views of the same three choices ───
			await maria.getByRole('button', { name: 'Lista' }).click();
			await expect(maria.locator('[data-swap-composer-target="listPanel"]')).toBeVisible();
			await expect(maria.locator('input[name="offeredShifts[]"]:checked')).toHaveCount(3);
			await maria.screenshot({ path: testInfo.outputPath(`composer-list-${testInfo.project.name}.png`), fullPage: true });

			// ── María sends; Pedro chooses one; no third negotiation round ──
			await maria.getByRole('button', { name: /Enviar 3 opciones/ }).click();
			await maria.waitForURL(/\/app\/changes\/proposals/);

			await pedro.goto('/app/changes');
			await pedro.getByRole('button', { name: /Notificaciones/ }).click();
			const notificationPanel = pedro.locator('[data-notifications-target="panel"]');
			await expect(notificationPanel).toContainText('te propone un cambio');
			await pedro.screenshot({ path: testInfo.outputPath(`notifications-${testInfo.project.name}.png`), fullPage: true });
			await notificationPanel.locator('.notification-item').filter({ hasText: 'te propone un cambio' }).click();
			await expect(pedro.getByRole('heading', { name: /te hace el turno/ })).toBeVisible();
			await expect(pedro.locator('input[name="optionId"]')).toHaveCount(3);
			await pedro.locator('.trade-option').nth(1).click({ timeout: 10_000 });
			await expect(pedro.locator('input[name="optionId"]').nth(1)).toBeChecked();
			await pedro.getByRole('button', { name: 'Confirmar intercambio' }).click({ timeout: 10_000 });
			await pedro.waitForURL(/\/app\/changes\/agreements\//);
			const agreementPath = new URL(pedro.url()).pathname;
			await expect(pedro.getByRole('heading', { name: 'Nuestro cambio' })).toBeVisible();
			await expect(pedro.getByText('Cambio confirmado')).toBeVisible();
			await expect(pedro.getByRole('button', { name: /Compartir con responsable|Copiar enlace/ })).toBeVisible();
			const publicUrl = await pedro.locator('[data-agreement-share-url-value]').getAttribute('data-agreement-share-url-value');
			expect(publicUrl).toMatch(/\/cambio\/[A-Za-z0-9_-]{43}$/);
			await pedro.screenshot({ path: testInfo.outputPath(`agreement-${testInfo.project.name}.png`), fullPage: true });

			await maria.goto('/app/changes');
			await maria.getByRole('button', { name: /Notificaciones/ }).click();
			const mariaPanel = maria.locator('[data-notifications-target="panel"]');
			await expect(mariaPanel).toContainText('Cambio acordado');
			await mariaPanel.locator('.notification-item').filter({ hasText: 'Cambio acordado' }).click();
			await expect(maria).toHaveURL(new RegExp(`${agreementPath}$`));

			const anonymousContext = await browser.newContext({ baseURL });
			const anonymous = await anonymousContext.newPage();
			try {
				await anonymous.goto(new URL(publicUrl ?? '').pathname);
				await expect(anonymous.getByRole('heading', { name: 'Nuestro cambio' })).toBeVisible();
				await expect(anonymous.getByText('Consulta de solo lectura')).toBeVisible();
				await anonymous.screenshot({ path: testInfo.outputPath(`agreement-public-${testInfo.project.name}.png`), fullPage: true });
			} finally { await anonymousContext.close(); }

			await openCalendar(pedro, day(9).slice(0, 7));
			await expect(pedro.locator(`[data-day="${day(9)}"]`)).toHaveAttribute('data-state', 'working');
			await expect(pedro.locator(`[data-day="${day(9)}"]`)).toHaveAttribute('data-from-swap', 'true');
			await expect(pedro.locator(`[data-day="${day(9)}"]`)).toContainText(/Por Ana/i);
			await expect(pedro.locator(`[data-day="${day(19)}"]`)).toHaveAttribute('data-from-swap', 'true');
			await expect(pedro.locator(`[data-day="${day(19)}"]`)).toContainText(/Te lo hace Ana/i);
			await openCalendar(maria, day(19).slice(0, 7));
			await expect(maria.locator(`[data-day="${day(19)}"]`)).toHaveAttribute('data-state', 'working');
			await expect(maria.locator(`[data-day="${day(19)}"]`)).toHaveAttribute('data-from-swap', 'true');
			await expect(maria.locator(`[data-day="${day(19)}"] .calendar-segment-band`)).toHaveCount(2);
			await expect(maria.locator(`[data-day="${day(19)}"]`)).toContainText(/Por Ana/i);
			await maria.screenshot({ path: testInfo.outputPath(`calendar-two-shifts-${testInfo.project.name}.png`), fullPage: true });
		} finally {
			// Do not let teardown hide the precise failed interaction when the
			// browser has already been closed by a test timeout.
			await Promise.allSettled([pedroContext.close(), mariaContext.close()]);
		}
	});
});
