import type { Page } from '@playwright/test';
import { expect, onboardWorker, test } from './support/worker';

const GROUP = { category: 'TCAE', destination: 'Urgencias' };

async function setShift(page: Page, date: string, label: string): Promise<void> {
	await page.goto(`/app/calendar?month=${date.slice(0, 7)}`);
	await page.waitForSelector('[data-calendar-target="grid"]');
	const day = page.locator(`[data-day="${date}"]`);
	await day.click();
	const sheet = page.locator('[data-calendar-target="daySheet"]');
	await expect(sheet).toBeVisible();
	await sheet.locator('.picker-chip').filter({ hasText: label }).click();
	await expect(sheet).not.toBeVisible();
	await expect(day).toHaveAttribute('data-state', 'working');
}

async function publishThroughGuide(page: Page, date: string): Promise<string> {
	await page.goto('/app/changes?flow=release');
	const sheet = page.locator('[data-changes-target="sheet"]');
	await expect(sheet).toBeVisible();
	await sheet.locator('.shift-choice').filter({ hasText: String(Number(date.slice(8))) }).click();
	await sheet.getByRole('button', { name: 'Buscar compañero' }).click();
	await expect(sheet).toContainText('Estamos buscando a alguien');
	await page.goto('/app/changes/mine');
	const requestId = await page.locator('.activity-card[data-request-id]').first().getAttribute('data-request-id');
	expect(requestId).toBeTruthy();
	return requestId ?? '';
}

async function chooseAvailability(page: Page, dates: string[], kinds: string[], destinations: string[] = []): Promise<void> {
	await page.goto(`/app/changes?flow=availability&date=${dates[0]}`);
	const sheet = page.locator('[data-changes-target="sheet"]');
	await expect(sheet).toBeVisible();
	for (const date of dates.slice(1)) await sheet.locator(`[data-date="${date}"]`).click();
	await sheet.getByRole('button', { name: 'Continuar' }).click();
	for (const kind of kinds) await sheet.locator(`[data-kind="${kind}"]`).click();
	await sheet.getByRole('button', { name: 'Continuar' }).click();
	if (destinations.length) {
		for (const destination of destinations) await sheet.locator('.place-choice').filter({ hasText: destination }).click();
		await sheet.getByRole('button', { name: 'Continuar' }).click();
	}
	await expect(sheet).toContainText('Revisa tu disponibilidad');
	await sheet.getByRole('button', { name: 'Guardar disponibilidad' }).click();
	await expect(sheet).toContainText('Disponibilidad guardada');
}

test.describe('guided changes', () => {
	test.describe.configure({ mode: 'serial', timeout: 120_000 });
	test.skip(() => test.info().project.name !== 'mobile-375', 'The complete data journeys run once; responsive rendering has its own project matrix.');

	test('A — Pedro publishes a shift once even after repeated confirmation', async ({ page }) => {
		const date = '2026-10-17';
		await setShift(page, date, 'Tarde');
		await page.goto('/app/changes?flow=release');
		const root = page.locator('[data-changes-upcoming-value]');
		const shifts = JSON.parse((await root.getAttribute('data-changes-upcoming-value')) ?? '[]');
		const shift = shifts.find((candidate: { date: string }) => candidate.date === date);
		expect(shift).toBeTruthy();
		const csrf = (await root.getAttribute('data-changes-csrf-value')) ?? '';
		await page.locator('.shift-choice').filter({ hasText: '17' }).click();
		await page.getByRole('button', { name: 'Buscar compañero' }).click();
		await expect(page.locator('[data-changes-target="sheet"]')).toContainText('Estamos buscando a alguien');

		const ids = await page.evaluate(async ({ csrfToken, payload }) => {
			const send = async () => (await (await fetch('/app/changes/publicar', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken }, body: JSON.stringify(payload) })).json()).result.requestId;
			return [await send(), await send()];
		}, { csrfToken: csrf, payload: { assignmentId: shift.assignmentId, date, swapPoolId: shift.swapPoolId } });
		expect(ids[0]).toBe(ids[1]);
		await page.goto('/app/changes/mine');
		await expect(page.locator('.activity-card[data-request-id]')).toHaveCount(1);
		await expect(page.locator('.activity-card[data-request-id]')).toContainText('Buscando compañero');
	});

	test('B/C — María saves two days grouped and edits one without duplicated cards', async ({ browser }, testInfo) => {
		const context = await browser.newContext({ baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080' });
		const maria = await context.newPage();
		try {
			await onboardWorker(maria, testInfo, 'availability-maria', { ...GROUP, additionalDestinations: ['UCI'] });
			await chooseAvailability(maria, ['2026-09-19', '2026-09-22'], ['morning', 'evening'], ['Urgencias', 'UCI']);
			await maria.goto('/app/changes/mine');
			await expect(maria.locator('[data-availability-date]')).toHaveCount(2);
			for (const date of ['2026-09-19', '2026-09-22']) {
				const card = maria.locator(`[data-availability-date="${date}"]`);
				await expect(card).toContainText('Mañana · Tarde');
				await expect(card).toContainText('Urgencias');
				await expect(card).toContainText('UCI');
			}

			await maria.locator('[data-availability-date="2026-09-19"]').getByRole('link', { name: 'Editar' }).click();
			const sheet = maria.locator('[data-changes-target="sheet"]');
			await sheet.locator('[data-kind="evening"]').click();
			await sheet.getByRole('button', { name: 'Continuar' }).click();
			await sheet.getByRole('button', { name: 'Continuar' }).click();
			await sheet.getByRole('button', { name: 'Guardar disponibilidad' }).click();
			await maria.goto('/app/changes/mine');
			const edited = maria.locator('[data-availability-date="2026-09-19"]');
			await expect(edited).toContainText('Mañana');
			await expect(edited).not.toContainText('Tarde');
			await expect(edited).toContainText('Urgencias');
			await expect(edited).toContainText('UCI');
		} finally { await context.close(); }
	});

	test('D — the same date in another pool never mixes incompatible workers', async ({ browser }, testInfo) => {
		const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
		const pedroContext = await browser.newContext({ baseURL }); const mariaContext = await browser.newContext({ baseURL });
		const pedro = await pedroContext.newPage(); const maria = await mariaContext.newPage();
		try {
			await onboardWorker(pedro, testInfo, 'pool-pedro', { category: 'TCAE', destination: 'UCI' });
			await onboardWorker(maria, testInfo, 'pool-maria', GROUP);
			await setShift(pedro, '2026-11-12', 'Noche'); const requestId = await publishThroughGuide(pedro, '2026-11-12');
			await maria.goto('/app/changes/available');
			await expect(maria.locator(`.activity-card[data-request-id="${requestId}"]`)).toHaveCount(0);
		} finally { await pedroContext.close(); await mariaContext.close(); }
	});

	test('E/F — morning is not a night match; changing to night makes it compatible', async ({ browser }, testInfo) => {
		test.setTimeout(120_000);
		const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
		const pedroContext = await browser.newContext({ baseURL }); const mariaContext = await browser.newContext({ baseURL });
		const pedro = await pedroContext.newPage(); const maria = await mariaContext.newPage(); const date = '2026-11-19';
		try {
			await onboardWorker(pedro, testInfo, 'kind-pedro', GROUP); await onboardWorker(maria, testInfo, 'kind-maria', GROUP);
			await setShift(pedro, date, 'Noche'); const requestId = await publishThroughGuide(pedro, date);
			await chooseAvailability(maria, [date], ['morning']);
			await pedro.goto('/app/changes/mine'); await expect(pedro.locator(`.activity-card[data-request-id="${requestId}"]`)).toContainText('0 personas disponibles');
			await maria.goto('/app/changes/available'); const offered = maria.locator(`.activity-card[data-request-id="${requestId}"]`);
			await expect(offered).toBeVisible(); await expect(offered).not.toContainText('Encaja con tu disponibilidad');

			await maria.goto('/app/changes/mine'); await maria.locator(`[data-availability-date="${date}"]`).getByRole('link', { name: 'Editar' }).click();
			const sheet = maria.locator('[data-changes-target="sheet"]');
			await sheet.locator('[data-kind="morning"]').click(); await sheet.locator('[data-kind="night"]').click();
			await sheet.getByRole('button', { name: 'Continuar' }).click(); await sheet.getByRole('button', { name: 'Guardar disponibilidad' }).click();
			await pedro.goto('/app/changes/mine'); await expect(pedro.locator(`.activity-card[data-request-id="${requestId}"]`)).toContainText('1 persona disponible');
			await maria.goto('/app/changes/available'); await expect(maria.locator(`.activity-card[data-request-id="${requestId}"]`)).toContainText('Encaja con tu disponibilidad');
		} finally { await pedroContext.close(); await mariaContext.close(); }
	});

	test('G — a forged foreign pool id changes no data', async ({ browser }, testInfo) => {
		const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
		const mariaContext = await browser.newContext({ baseURL }); const otherContext = await browser.newContext({ baseURL });
		const maria = await mariaContext.newPage(); const other = await otherContext.newPage();
		try {
			await onboardWorker(maria, testInfo, 'secure-maria', GROUP); await onboardWorker(other, testInfo, 'secure-other', { category: 'TCAE', destination: 'UCI' });
			await maria.goto('/app/changes'); await other.goto('/app/changes');
			const foreignGroups = JSON.parse((await other.locator('[data-changes-groups-value]').getAttribute('data-changes-groups-value')) ?? '[]');
			const csrf = (await maria.locator('[data-changes-csrf-value]').getAttribute('data-changes-csrf-value')) ?? '';
			const status = await maria.evaluate(async ({ token, foreignPool }) => (await fetch('/app/changes/disponible', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token }, body: JSON.stringify({ dates: ['2026-12-02'], shiftKinds: ['morning'], swapPoolIds: [foreignPool] }) })).status, { token: csrf, foreignPool: foreignGroups[0].poolId });
			expect(status).toBe(422);
			await maria.goto('/app/changes/mine'); await expect(maria.locator('[data-availability-date]')).toHaveCount(0);
		} finally { await mariaContext.close(); await otherContext.close(); }
	});
});

test('changes dashboard remains readable across the viewport matrix', async ({ page }, testInfo) => {
	await page.goto('/app/changes');
	await expect(page.getByRole('heading', { name: '¿Qué necesitas?' })).toBeVisible();
	await expect(page.getByRole('button', { name: /Quiero librar un turno/ })).toBeVisible();
	await expect(page.getByRole('button', { name: /Puedo trabajar/ })).toBeVisible();
	await expect(page.locator('body')).not.toHaveCSS('overflow-x', 'scroll');
	await page.screenshot({ path: testInfo.outputPath(`changes-${testInfo.project.name}.png`), fullPage: true });
});
