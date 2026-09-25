import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
	static targets = ['panel', 'list', 'badge', 'pushState'];
	static values = { csrf: String, vapidKey: String };

	async toggle() {
		const opening = this.panelTarget.hidden;
		this.panelTarget.hidden = !opening;
		if (opening) await this.load();
	}

	close(event) {
		if (!this.element.contains(event.target)) this.panelTarget.hidden = true;
	}

	async load() {
		const response = await fetch('/app/notifications', { headers: { Accept: 'application/json' } });
		if (!response.ok) return;
		const payload = await response.json();
		this.render(payload.notifications || []);
		this.updateBadge(payload.unreadCount || 0);
		this.renderPushState();
	}

	render(items) {
		this.listTarget.replaceChildren();
		if (!items.length) {
			const empty = document.createElement('p');
			empty.className = 'notification-empty';
			empty.textContent = 'No tienes notificaciones todavía.';
			this.listTarget.append(empty);
			return;
		}
		for (const item of items) {
			const link = document.createElement('a');
			link.className = `notification-item${item.read ? '' : ' notification-item-unread'}`;
			link.href = item.targetUrl;
			link.dataset.id = item.id;
			link.addEventListener('click', (event) => this.openNotification(event, item));
			const title = document.createElement('strong');
			title.textContent = item.title;
			const body = document.createElement('span');
			body.textContent = item.body;
			const time = document.createElement('time');
			time.dateTime = item.createdAt;
			time.textContent = this.relativeTime(item.createdAt);
			link.append(title, body, time);
			this.listTarget.append(link);
		}
	}

	async openNotification(event, item) {
		event.preventDefault();
		await this.post(`/app/notifications/${encodeURIComponent(item.id)}/read`);
		window.location.assign(item.targetUrl);
	}

	async markAll() {
		await this.post('/app/notifications/read-all');
		await this.load();
	}

	async enablePush() {
		if (!('Notification' in window) || !('serviceWorker' in navigator) || !('PushManager' in window)) {
			this.renderPushState('unsupported');
			return;
		}
		const permission = await Notification.requestPermission();
		if (permission !== 'granted') {
			this.renderPushState(permission);
			return;
		}
		const registration = await navigator.serviceWorker.ready;
		let subscription = await registration.pushManager.getSubscription();
		if (!subscription) {
			subscription = await registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: this.decodeKey(this.vapidKeyValue) });
		}
		await fetch('/app/notifications/push-subscriptions', {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfValue },
			body: JSON.stringify(subscription.toJSON()),
		});
		this.renderPushState('granted');
	}

	renderPushState(forced = null) {
		const supported = 'Notification' in window && 'serviceWorker' in navigator && 'PushManager' in window;
		const state = forced || (supported ? Notification.permission : 'unsupported');
		this.pushStateTarget.replaceChildren();
		if (state === 'granted') {
			this.pushStateTarget.textContent = 'Notificaciones activadas ✓';
			return;
		}
		if (state === 'denied') {
			this.pushStateTarget.textContent = 'Las notificaciones están bloqueadas en este navegador. Puedes volver a activarlas desde los permisos del sitio.';
			return;
		}
		if (state === 'unsupported') {
			this.pushStateTarget.textContent = 'Este navegador no admite avisos push. La campanita seguirá funcionando.';
			return;
		}
		const button = document.createElement('button');
		button.type = 'button';
		button.className = 'btn-secondary btn-block';
		button.textContent = 'Activar notificaciones';
		button.addEventListener('click', () => this.enablePush());
		this.pushStateTarget.append(button);
	}

	async post(url) {
		const response = await fetch(url, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrfValue } });
		if (response.ok) {
			const payload = await response.json();
			if (Number.isInteger(payload.unreadCount)) this.updateBadge(payload.unreadCount);
		}
		return response;
	}

	updateBadge(count) {
		this.badgeTarget.textContent = count > 99 ? '99+' : String(count);
		this.badgeTarget.hidden = count < 1;
	}

	relativeTime(value) {
		const seconds = Math.max(0, Math.floor((Date.now() - new Date(value).getTime()) / 1000));
		if (seconds < 60) return 'Ahora';
		if (seconds < 3600) return `Hace ${Math.floor(seconds / 60)} min`;
		if (seconds < 86400) return `Hace ${Math.floor(seconds / 3600)} h`;
		if (seconds < 172800) return 'Ayer';
		return `Hace ${Math.floor(seconds / 86400)} días`;
	}

	decodeKey(value) {
		const padding = '='.repeat((4 - (value.length % 4)) % 4);
		const raw = atob((value + padding).replace(/-/g, '+').replace(/_/g, '/'));
		return Uint8Array.from([...raw].map((character) => character.charCodeAt(0)));
	}
}
