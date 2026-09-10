import { expect, test } from '@playwright/test';

test.describe('health', () => {
	test('reports every dependency without leaking any of them', async ({ request }) => {
		const response = await request.get('/health');

		expect(response.status()).toBe(200);

		const body = await response.json();

		expect(body.status).toBe('healthy');
		expect(Object.keys(body.components).sort()).toEqual(['cache', 'database', 'schema']);

		// Unauthenticated endpoint: it must not become a reconnaissance tool.
		const raw = JSON.stringify(body).toLowerCase();
		for (const secret of ['postgres', 'redis', 'password', '5432']) {
			expect(raw).not.toContain(secret);
		}
	});

	test('is correlated with a request id', async ({ request }) => {
		const response = await request.get('/health');

		expect(response.headers()['x-request-id']).toBeTruthy();
	});
});
