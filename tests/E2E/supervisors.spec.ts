import type { Browser, Page, TestInfo } from '@playwright/test';
import fs from 'node:fs';
import path from 'node:path';
import { expect, onboardWorker, registerAccount, test } from './support/worker';

/*
 * Supervisor onboarding as people live it: Ana invites the person who usually
 * validates their changes, Laura accepts with her own account, David vouches
 * for her, and only then does the supervisor area open.
 *
 * Every run creates its own local unit, so the team is exactly Ana and David
 * and the quorum is exactly two, whatever else the development database holds.
 * Approving a real agreement needs a pool that requires approval, which has no
 * screen yet; that part is covered end to end in
 * tests/Functional/Workforce/SupervisorOnboardingFlowTest.php.
 */

const baseURL = process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8080';
const SCREENSHOT_PROJECTS = ['mobile-390', 'desktop'];

async function screenshot(page: Page, testInfo: TestInfo, name: string): Promise<void> {
	if (!SCREENSHOT_PROJECTS.includes(testInfo.project.name)) return;
	const directory = path.resolve('var/playwright/screenshots/supervisors');
	fs.mkdirSync(directory, { recursive: true });
	await page.screenshot({ path: path.join(directory, `${name}-${testInfo.project.name}.png`), fullPage: true });
}

/** Web Share, clipboard and window.open recorded instead of performed. */
async function recordSharing(page: Page, withWebShare: boolean): Promise<void> {
	await page.addInitScript((webShare) => {
		const w = window as unknown as { __shared: unknown[]; __copied: string; __opened: string };
		w.__shared = [];
		Object.defineProperty(navigator, 'share', { configurable: true, value: webShare ? async (data: unknown) => { w.__shared.push(data); } : undefined });
		Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: async (text: string) => { w.__copied = text; } } });
		window.open = ((url: string) => { w.__opened = url; return null; }) as typeof window.open;
	}, withWebShare);
}

async function team(browser: Browser, testInfo: TestInfo, unit: string): Promise<{ ana: Page; david: Page; laura: Page; close: () => Promise<void> }> {
	const contexts = await Promise.all([browser.newContext({ baseURL }), browser.newContext({ baseURL }), browser.newContext({ baseURL })]);
	const [ana, david, laura] = await Promise.all(contexts.map((context) => context.newPage()));
	const group = { category: 'TCAE', destination: unit, local: true };
	await onboardWorker(ana, testInfo, 'sup-ana', group);
	await onboardWorker(david, testInfo, 'sup-david', group);

	return { ana, david, laura, close: async () => { await Promise.all(contexts.map((context) => context.close())); } };
}

async function inviteFromHome(ana: Page): Promise<string> {
	await ana.goto('/app');
	const missing = ana.getByTestId('supervisor-missing-card').filter({ hasText: 'Todavía no tenéis un responsable verificado' });
	await expect(missing).toBeVisible();
	await missing.getByRole('button', { name: 'Invitar responsable' }).click();
	const url = await ana.getByTestId('supervisor-invitation-url').inputValue();
	expect(url).toMatch(/\/invitacion\/responsable\/[A-Za-z0-9_-]{43}$/);

	return new URL(url).pathname;
}

async function acceptAsNewAccount(laura: Page, testInfo: TestInfo, invitationPath: string): Promise<void> {
	await laura.goto(invitationPath);
	await expect(laura.getByText('Te han invitado como responsable')).toBeVisible();
	await expect(laura.getByRole('link', { name: 'Continuar con Google' })).toBeVisible();
	// Google cannot run in CI; an email account follows the same session and
	// the same "come back to this link" redirect after signing in.
	await registerAccount(laura, testInfo, 'laura');
	await expect(laura).toHaveURL(new RegExp(`${invitationPath}$`));
	await screenshot(laura, testInfo, 'invitation');
	await laura.getByRole('button', { name: 'Aceptar' }).click();
	await laura.waitForURL(/\/app$/);
}

async function confirmFromTheBell(david: Page): Promise<void> {
	await david.goto('/app');
	await david.getByRole('button', { name: /Notificaciones/ }).click();
	const panel = david.locator('[data-notifications-target="panel"]');
	await expect(panel).toContainText('Comprueba a tu responsable');
	await panel.locator('.notification-item').filter({ hasText: 'Comprueba a tu responsable' }).click();
	await expect(david.getByText('¿Es vuestro responsable?')).toBeVisible();
}

test.describe('Responsable verificado por su equipo', () => {
	test.describe.configure({ timeout: 240_000 });

	test('invitar, aceptar, verificar por quórum y entrar al área de responsable', async ({ browser }, testInfo) => {
		const unit = `Supervisión E2E ${testInfo.project.name} ${Date.now() % 1_000_000}`;
		const { ana, david, laura, close } = await team(browser, testInfo, unit);
		try {
			await recordSharing(ana, true);
			const invitationPath = await inviteFromHome(ana);
			await expect(ana.getByRole('button', { name: 'Compartir por WhatsApp' })).toBeVisible();
			await acceptAsNewAccount(laura, testInfo, invitationPath);

			// ── Laura: pending, with progress, and no authority ─────────────
			const pending = laura.getByTestId('supervisor-pending-card');
			await expect(pending).toContainText('Responsable · pendiente de verificación');
			await expect(pending.getByTestId('supervisor-progress')).toHaveText('1 de 2 confirmaciones');
			await expect(pending).toContainText('otro miembro del equipo confirme');
			await expect(pending.getByRole('button', { name: 'Pedir confirmación por WhatsApp' })).toBeVisible();
			await screenshot(laura, testInfo, 'pending-1-of-2');
			const blocked = await laura.goto('/app/responsable');
			expect(blocked?.status()).toBe(403);
			await expect(laura.getByText('Todavía no eres responsable verificado')).toBeVisible();

			// ── David: the bell takes him straight to the question ──────────
			await confirmFromTheBell(david);
			await expect(david.getByText(/lau\*\*\*@example\.test/).first()).toBeVisible();
			await screenshot(david, testInfo, 'worker-confirmation');
			await david.getByRole('button', { name: 'Sí, es nuestro responsable' }).click();
			await expect(david.getByText('Has confirmado que es vuestro responsable')).toBeVisible();

			// ── Laura: verified, told so, and the area opens ────────────────
			await laura.goto('/app');
			const verified = laura.getByTestId('supervisor-verified-card');
			await expect(verified).toContainText('✓');
			await expect(verified).toContainText('No hay cambios pendientes de revisión.');
			await laura.getByRole('button', { name: /Notificaciones/ }).click();
			await expect(laura.locator('[data-notifications-target="panel"]')).toContainText('Tu equipo te ha verificado como responsable');
			await screenshot(laura, testInfo, 'verified');
			await laura.goto('/app');
			await laura.getByTestId('supervisor-verified-card').getByRole('link', { name: 'Ir al panel de responsable' }).click();
			await expect(laura.getByRole('heading', { name: 'Cambios pendientes' })).toBeVisible();
			await expect(laura.getByText('No tienes cambios pendientes.')).toBeVisible();

			// Authority is per pool and checked on the endpoint, not the page.
			const token = await laura.locator('[data-changes-csrf-value]').getAttribute('data-changes-csrf-value');
			const foreign = await laura.request.post('/app/responsable/cambios/01999999-0000-7000-8000-000000000000/approve', { form: { _token: token ?? '' } });
			expect(foreign.status()).toBe(403);

			await ana.goto('/app/equipo');
			await expect(ana.getByTestId('team-card').filter({ hasText: unit })).toContainText('Responsable verificado por el equipo');
		} finally {
			await close();
		}
	});

	test('compartir: WhatsApp, Web Share y copiar enlace, sin pedir contactos', async ({ browser }, testInfo) => {
		const context = await browser.newContext({ baseURL });
		const ana = await context.newPage();
		try {
			await onboardWorker(ana, testInfo, 'share-ana', { category: 'TCAE', destination: `Compartir E2E ${testInfo.project.name} ${Date.now() % 1_000_000}`, local: true });
			await recordSharing(ana, true);
			const invitationPath = await inviteFromHome(ana);
			const url = `${new URL(ana.url()).origin}${invitationPath}`;
			const share = ana.getByTestId('supervisor-share');

			await share.getByRole('button', { name: 'Compartir por WhatsApp' }).click();
			const opened = await ana.evaluate(() => (window as unknown as { __opened: string }).__opened);
			expect(opened.startsWith('https://wa.me/?text=')).toBe(true);
			const whatsappText = decodeURIComponent(opened.slice('https://wa.me/?text='.length));
			expect(whatsappText).toContain('Hola, estamos usando Turnin para gestionar nuestros cambios de turno.');
			expect(whatsappText).toContain(url);
			expect(whatsappText).toContain('Solo tendrás que entrar con tu cuenta de Google.');

			await share.getByRole('button', { name: 'Compartir', exact: true }).click();
			const shared = await ana.evaluate(() => (window as unknown as { __shared: { text: string }[] }).__shared);
			expect(shared).toHaveLength(1);
			expect(shared[0].text).toContain(url);

			await share.getByRole('button', { name: 'Copiar enlace' }).click();
			await expect(share.getByRole('status')).toHaveText('Enlace copiado');
			expect(await ana.evaluate(() => (window as unknown as { __copied: string }).__copied)).toBe(url);

			// Without Web Share, "Compartir" falls back to the clipboard.
			await recordSharing(ana, false);
			await ana.reload();
			await ana.getByTestId('supervisor-share').getByRole('button', { name: 'Compartir', exact: true }).click();
			await expect(ana.getByTestId('supervisor-share').getByRole('status')).toHaveText('Enlace copiado');
		} finally {
			await context.close();
		}
	});

	test('dejar de ser responsable quita el acceso y el equipo vuelve a estar sin responsable', async ({ browser }, testInfo) => {
		const unit = `Renuncia E2E ${testInfo.project.name} ${Date.now() % 1_000_000}`;
		const { ana, david, laura, close } = await team(browser, testInfo, unit);
		try {
			const invitationPath = await inviteFromHome(ana);
			await acceptAsNewAccount(laura, testInfo, invitationPath);
			await confirmFromTheBell(david);
			await david.getByRole('button', { name: 'Sí, es nuestro responsable' }).click();

			await laura.goto('/app');
			await expect(laura.getByTestId('supervisor-verified-card')).toBeVisible();
			await laura.getByRole('link', { name: 'Dejar de ser responsable' }).click();
			await expect(laura.getByRole('heading', { name: /¿Quieres dejar de ser responsable de/ })).toBeVisible();
			await expect(laura.getByText('Tu historial de acciones anteriores se conservará.')).toBeVisible();
			await screenshot(laura, testInfo, 'leave');
			await laura.getByRole('button', { name: 'Sí, dejar de ser responsable' }).click();
			await laura.waitForURL(/\/app\?responsable=dejado$/);
			await expect(laura.getByText('Has dejado de ser responsable')).toBeVisible();
			await expect(laura.getByTestId('supervisor-verified-card')).toHaveCount(0);

			const blocked = await laura.goto('/app/responsable');
			expect(blocked?.status()).toBe(403);
			const token = await laura.locator('[data-changes-csrf-value]').getAttribute('data-changes-csrf-value');
			const approval = await laura.request.post('/app/responsable/cambios/01999999-0000-7000-8000-000000000000/approve', { form: { _token: token ?? '' } });
			expect(approval.status()).toBe(403);

			await ana.goto('/app/equipo');
			await expect(ana.getByTestId('team-card').filter({ hasText: unit }).getByTestId('team-without-supervisor')).toHaveText('Sin responsable verificado.');
		} finally {
			await close();
		}
	});
});
