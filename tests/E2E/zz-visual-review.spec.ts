import fs from 'node:fs';
import { cell, expect, openAddSheet, openCalendar, test } from './support/worker';

/* Temporary: captures the calendar screens at every supported width. */
const WIDTHS = [375, 390, 430, 768, 1440];
const OUT = 'var/playwright/screens';

test.describe.configure({ mode: 'serial' });

test('captures the calendar at every width', async ({ page }) => {
	test.setTimeout(300_000);
	fs.mkdirSync(OUT, { recursive: true });
	const month = '2027-09';
	const shot = async (name: string, width: number) => {
		await page.screenshot({ path: `${OUT}/${name}-${width}.png`, fullPage: false });
	};

	for (const width of WIDTHS) {
		await page.setViewportSize({ width, height: width < 500 ? 844 : 900 });

		// Empty month.
		await openCalendar(page, month);
		await shot('01-empty', width);

		// Add sheet.
		await openAddSheet(page);
		await expect(page.locator('[data-calendar-target="addSheet"]')).toBeVisible();
		await shot('02-add', width);

		// Paint mode.
		await page.getByRole('button', { name: /Pintar calendario/ }).click();
		const palette = page.locator('[data-calendar-paint-target="panel"]');
		await expect(palette).toBeVisible();
		await palette.locator('[data-paint-code="M"]').click();
		for (const day of [1, 2, 3, 4]) await cell(page, month, day).click();
		await palette.locator('[data-paint-code="N"]').click();
		for (const day of [6, 7]) await cell(page, month, day).click();
		await palette.locator('[data-paint-code="L"]').click();
		for (const day of [9, 10, 11]) await cell(page, month, day).click();
		await shot('03-paint', width);

		// Preview.
		await page.locator('[data-calendar-paint-target="save"]').click();
		const preview = page.locator('[data-schedule-draft-target="sheet"]');
		await expect(preview).toBeVisible();
		await shot('04-preview', width);
		await preview.locator('[data-schedule-draft-target="confirm"]').click();
		await expect(preview).not.toBeVisible();

		// A filled month, once the reload from the server has landed.
		await expect(page.locator('[data-calendar-target="summary"]')).toContainText('de trabajo');
		await shot('05-filled', width);

		// Day sheet on a day that already has a shift.
		await cell(page, month, 1).click();
		await expect(page.locator('[data-calendar-target="daySheet"]')).toBeVisible();
		await shot('06-day-sheet', width);
		await page.keyboard.press('Escape');

		// Pattern builder.
		await openAddSheet(page);
		await page.getByRole('button', { name: /Usar un patrón/ }).click();
		const patternSheet = page.locator('[data-calendar-target="patternSheet"]');
		await expect(patternSheet).toBeVisible();
		for (const code of ['M', 'M', 'T', 'T', 'N', 'N', 'L', 'L', 'L']) {
			await patternSheet.locator(`.shift-palette [data-slot-code="${code}"]`).click();
		}
		await shot('07-pattern', width);
		await patternSheet.locator('[data-pattern-builder-target="continue"]').click();
		await shot('08-pattern-range', width);
		await page.keyboard.press('Escape');

		// Dictation.
		await openAddSheet(page);
		await page.getByRole('button', { name: /Escribir cuadrante/ }).click();
		const voiceSheet = page.locator('[data-calendar-target="voiceSheet"]');
		await expect(voiceSheet).toBeVisible();
		await voiceSheet.locator('textarea').fill('1 y 2 mañana, 3 tarde, del 4 al 6 noche, 7 libre');
		await shot('09-voice', width);
		await page.keyboard.press('Escape');

		// Shift preset management.
		await page.goto('/app/calendar/turnos');
		await expect(page.locator('[data-shift-presets-target="list"]')).toBeVisible();
		await shot('10-presets', width);

		// Conflicts: re-apply a rotation over the month just painted.
		await openCalendar(page, month);
		await openAddSheet(page);
		await page.getByRole('button', { name: /Usar un patrón/ }).click();
		for (const code of ['M', 'T', 'N', 'L']) {
			await patternSheet.locator(`.shift-palette [data-slot-code="${code}"]`).click();
		}
		await patternSheet.locator('[data-pattern-builder-target="continue"]').click();
		await patternSheet.locator('[data-pattern-builder-target="from"]').fill(`${month}-01`);
		await patternSheet.getByRole('button', { name: 'Este mes' }).click();
		await patternSheet.locator('[data-pattern-builder-target="previewButton"]').click();
		await expect(preview).toBeVisible();
		await expect(preview.locator('[data-schedule-draft-target="conflicts"]')).toBeVisible();
		await shot('11-conflicts', width);
		await page.keyboard.press('Escape');

		// Leave the month clean for the next width.
		await page.evaluate(async (m) => {
			const token = document.querySelector('[data-calendar-csrf-value]')?.getAttribute('data-calendar-csrf-value');
			const entries = Array.from({ length: 30 }, (_, index) => ({
				date: `${m}-${String(index + 1).padStart(2, '0')}`,
				intent: 'clear',
				presetIds: [],
			}));
			await fetch('/app/calendar/apply', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token ?? '' },
				body: JSON.stringify({ entries, source: 'manual', policy: 'replace_existing' }),
			});
		}, month);
	}
});
