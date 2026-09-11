import { cell, expect, openAddSheet, openCalendar, test } from './support/worker';

/*
 * The promise this slice makes: "me han dado el cuadrante, lo meto en Turnin".
 * These tests walk the three ways in — painting, a rotation and dictation — and
 * check the two rules that must never break: nothing is written without a
 * confirmation, and nothing is overwritten without being asked.
 *
 * Every test works on its own month so they can share one onboarded worker
 * without sharing a calendar.
 */
test.describe('personal shift calendar', () => {
	test('paints ten days, saves them once, and they survive a reload', async ({ page }) => {
		const month = '2026-11';
		const problems: string[] = [];
		page.on('console', (message) => { if (message.type() === 'error') problems.push(message.text()); });
		page.on('pageerror', (error) => problems.push(error.message));

		await openCalendar(page, month);
		await expect(cell(page, month, 3)).toHaveAttribute('data-state', 'unknown');

		await openAddSheet(page);
		await page.getByRole('button', { name: /Pintar calendario/ }).click();

		const palette = page.locator('[data-calendar-paint-target="panel"]');
		await expect(palette).toBeVisible();

		// Addressed by the code they paint with rather than by accessible name:
		// the name is recomputed on every poll, and these run on several
		// browsers at once.
		await palette.locator('[data-paint-code="M"]').click();
		for (const day of [2, 3, 4, 5, 6]) await cell(page, month, day).click();

		await palette.locator('[data-paint-code="N"]').click();
		for (const day of [9, 10, 11]) await cell(page, month, day).click();

		await palette.locator('[data-paint-code="L"]').click();
		for (const day of [14, 15]) await cell(page, month, day).click();

		// Still a draft: not one of those taps has been saved.
		await expect(page.locator('[data-calendar-paint-target="counter"]')).toContainText('10 días modificados');

		// Undo takes back the last day only.
		await page.getByRole('button', { name: /Deshacer/ }).click();
		await expect(page.locator('[data-calendar-paint-target="counter"]')).toContainText('9 días modificados');
		await cell(page, month, 15).click();

		await page.locator('[data-calendar-paint-target="save"]').click();

		const preview = page.locator('[data-schedule-draft-target="sheet"]');
		await expect(preview).toBeVisible();
		await expect(preview.locator('[data-schedule-draft-target="counts"]')).toContainText('10 días');
		await preview.locator('[data-schedule-draft-target="confirm"]').click();
		await expect(preview).not.toBeVisible();

		await expect(cell(page, month, 2)).toHaveAttribute('data-state', 'working');
		await expect(cell(page, month, 14)).toHaveAttribute('data-state', 'rest');

		await page.reload();
		await page.waitForSelector('[data-calendar-target="grid"]');
		await expect(cell(page, month, 2)).toHaveAttribute('data-state', 'working');
		await expect(cell(page, month, 9)).toHaveAttribute('data-state', 'working');
		await expect(cell(page, month, 14)).toHaveAttribute('data-state', 'rest');
		await expect(cell(page, month, 20)).toHaveAttribute('data-state', 'unknown');

		const overflow = await page.evaluate(() => ({
			scrollWidth: document.documentElement.scrollWidth,
			clientWidth: document.documentElement.clientWidth,
		}));
		expect(overflow.scrollWidth).toBeLessThanOrEqual(overflow.clientWidth);
		expect(problems).toEqual([]);
	});

	test('builds a rotation, applies three months, and refuses to overwrite an existing day', async ({ page }) => {
		const month = '2027-06';
		await openCalendar(page, month);

		// A day entered by hand first, so the rotation has something to collide with.
		await cell(page, month, 16).click();
		const daySheet = page.locator('[data-calendar-target="daySheet"]');
		await expect(daySheet).toBeVisible();
		await daySheet.locator('.picker-chip').filter({ hasText: 'Noche' }).click();
		await expect(daySheet).not.toBeVisible();
		await expect(cell(page, month, 16)).toHaveAttribute('data-state', 'working');

		await openAddSheet(page);
		await page.getByRole('button', { name: /Usar un patrón/ }).click();

		const patternSheet = page.locator('[data-calendar-target="patternSheet"]');
		await expect(patternSheet).toBeVisible();
		const tap = async (code: string, times: number) => {
			for (let index = 0; index < times; index += 1) {
				await patternSheet.locator(`.shift-palette [data-slot-code="${code}"]`).click();
			}
		};
		await tap('M', 2);
		await tap('T', 2);
		await tap('N', 2);
		await tap('L', 3);
		await expect(patternSheet.locator('[data-pattern-builder-target="length"]')).toContainText('9 días');

		await patternSheet.locator('[data-pattern-builder-target="continue"]').click();
		await patternSheet.locator('[data-pattern-builder-target="from"]').fill(`${month}-01`);
		await patternSheet.getByRole('button', { name: '3 meses' }).click();
		await patternSheet.locator('[data-pattern-builder-target="previewButton"]').click();

		const preview = page.locator('[data-schedule-draft-target="sheet"]');
		await expect(preview).toBeVisible();
		const conflicts = preview.locator('[data-schedule-draft-target="conflicts"]');
		await expect(conflicts).toBeVisible();
		await expect(conflicts).toContainText('1 día ya tiene información');
		// Keeping what the worker already entered is preselected.
		await expect(preview.locator('input[value="skip_existing"]')).toBeChecked();

		await preview.locator('[data-schedule-draft-target="confirm"]').click();
		await expect(preview).not.toBeVisible();

		// The hand-entered night survived the rotation.
		await expect(cell(page, month, 16)).toHaveAttribute('data-state', 'working');
		await expect(cell(page, month, 16).locator('.calendar-cell-code')).toHaveText('N');
		await expect(cell(page, month, 1).locator('.calendar-cell-code')).toHaveText('M');
		await expect(cell(page, month, 7).locator('.calendar-cell-code')).toHaveText('L');

		// And it carried on into the following months.
		await page.getByRole('button', { name: 'Mes siguiente' }).click();
		await expect(page.locator('[data-calendar-target="title"]')).toContainText('Julio 2027');
		await expect(page.locator('[data-day="2027-07-01"]')).toHaveAttribute('data-state', /working|rest/);
	});

	test('reads a dictated rota, previews it, and only writes it once confirmed', async ({ page }) => {
		const month = '2026-12';
		await openCalendar(page, month);

		await openAddSheet(page);
		await page.getByRole('button', { name: /Escribir cuadrante/ }).click();

		const voiceSheet = page.locator('[data-calendar-target="voiceSheet"]');
		await expect(voiceSheet).toBeVisible();
		await voiceSheet.locator('textarea').fill('1 y 2 mañana, 3 tarde, 4 noche, 5 y 6 libres');
		await voiceSheet.locator('[data-voice-schedule-target="interpretButton"]').click();

		const preview = page.locator('[data-schedule-draft-target="sheet"]');
		await expect(preview).toBeVisible();
		await expect(preview.locator('[data-schedule-draft-target="title"]')).toContainText('He entendido esto');
		await expect(preview.locator('[data-schedule-draft-target="counts"]')).toContainText('6 días');
		await expect(preview.locator('.preview-row')).toHaveCount(6);

		// Nothing has been written yet.
		await expect(cell(page, month, 1)).toHaveAttribute('data-state', 'unknown');

		await preview.locator('[data-schedule-draft-target="confirm"]').click();
		await expect(preview).not.toBeVisible();

		await expect(cell(page, month, 1).locator('.calendar-cell-code')).toHaveText('M');
		await expect(cell(page, month, 3).locator('.calendar-cell-code')).toHaveText('T');
		await expect(cell(page, month, 4).locator('.calendar-cell-code')).toHaveText('N');
		await expect(cell(page, month, 5).locator('.calendar-cell-code')).toHaveText('L');
	});

	test('a fragment it cannot understand is reported rather than guessed', async ({ page }) => {
		await openCalendar(page, '2027-02');

		await openAddSheet(page);
		await page.getByRole('button', { name: /Escribir cuadrante/ }).click();

		const voiceSheet = page.locator('[data-calendar-target="voiceSheet"]');
		await voiceSheet.locator('textarea').fill('1 mañana y el resto ya veremos');
		await voiceSheet.locator('[data-voice-schedule-target="interpretButton"]').click();

		await expect(voiceSheet.locator('[data-voice-schedule-target="unresolved"]')).toContainText('y el resto ya veremos');
	});
});

/*
 * A browser with no SpeechRecognition at all. The microphone is progressive
 * enhancement; typing is the feature, and it has to keep working.
 */
test.describe('dictation without a speech API', () => {
	test('hides the microphone and leaves typing available', async ({ page }) => {
		await page.addInitScript(() => {
			// @ts-expect-error deliberately removing the API under test
			delete window.SpeechRecognition;
			// @ts-expect-error deliberately removing the API under test
			delete window.webkitSpeechRecognition;
		});

		await openCalendar(page, '2027-01');

		await openAddSheet(page);
		await expect(page.getByRole('button', { name: /Dictar cuadrante/ })).toBeHidden();

		await page.getByRole('button', { name: /Escribir cuadrante/ }).click();
		const voiceSheet = page.locator('[data-calendar-target="voiceSheet"]');
		await expect(voiceSheet.locator('[data-voice-schedule-target="microphone"]')).toBeHidden();
		await voiceSheet.locator('textarea').fill('1 mañana');
		await voiceSheet.locator('[data-voice-schedule-target="interpretButton"]').click();

		await expect(page.locator('[data-schedule-draft-target="sheet"]')).toBeVisible();
	});
});
