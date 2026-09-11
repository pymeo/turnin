import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
	static targets = ['input', 'value', 'selection', 'featured', 'results', 'status', 'retry', 'dialog', 'sheetInput', 'sheetResults', 'local', 'localName'];
	static values = {
		endpoint: String,
		mode: { type: String, default: 'single' },
		allowLocal: { type: Boolean, default: false },
		excludeField: { type: String, default: '' },
	};

	connect() {
		this.timer = null;
		this.request = null;
		this.items = new Map();
		const values = this.valueTarget.value ? this.valueTarget.value.split(',').filter(Boolean) : [];
		const labels = (this.valueTarget.dataset.initialLabel || '').split(',').map((label) => label.trim()).filter(Boolean);
		values.forEach((value, index) => this.items.set(value, { id: value, name: labels[index] || 'Selección guardada' }));
		this.renderSelection();
		this.inputTarget.addEventListener('input', () => this.queue(this.inputTarget.value));
		this.inputTarget.addEventListener('keydown', (event) => this.keydown(event, this.resultsTarget));
		this.sheetInputTarget.addEventListener('input', () => this.queue(this.sheetInputTarget.value, true));
		this.sheetInputTarget.addEventListener('keydown', (event) => this.keydown(event, this.sheetResultsTarget));
		if (this.ready()) this.load('');
	}

	disconnect() {
		clearTimeout(this.timer);
		this.request?.abort();
	}

	refresh() {
		if (this.ready()) this.load('');
	}

	clear() {
		this.request?.abort();
		this.items.clear();
		this.inputTarget.value = '';
		this.valueTarget.value = '';
		this.renderSelection();
		this.resultsTarget.replaceChildren();
		this.featuredTarget.replaceChildren();
		this.sheetResultsTarget.replaceChildren();
		this.inputTarget.setAttribute('aria-expanded', 'false');
	}

	exclude() {
		const excluded = this.excludeFieldValue ? document.querySelector(`[name="${this.excludeFieldValue}"]`)?.value : '';
		if (excluded && this.items.delete(excluded)) this.renderSelection();
		this.syncOptionStates();
	}

	open() {
		if (!this.ready()) {
			this.statusTarget.textContent = 'Selecciona primero tu centro.';
			return;
		}
		this.dialogTarget.showModal();
		this.load('', true);
		requestAnimationFrame(() => this.sheetInputTarget.focus());
	}

	close() {
		this.dialogTarget.close();
	}

	retry() {
		this.load(this.dialogTarget.open ? this.sheetInputTarget.value : this.inputTarget.value, this.dialogTarget.open);
	}

	queue(term, sheet = false) {
		clearTimeout(this.timer);
		this.timer = setTimeout(() => this.load(term.trim(), sheet), 220);
	}

	async load(term, sheet = false) {
		if (!this.ready()) return;
		this.request?.abort();
		this.request = new AbortController();
		this.statusTarget.textContent = 'Buscando…';
		this.retryTarget.classList.add('hidden');
		const url = new URL(this.endpoint(), window.location.origin);
		url.searchParams.set('q', term);
		try {
			const response = await fetch(url, { signal: this.request.signal, headers: { Accept: 'application/json' } });
			if (!response.ok) throw new Error('request');
			const payload = await response.json();
			const results = this.available(Array.isArray(payload.results) ? payload.results : []);
			this.statusTarget.textContent = results.length ? '' : 'No hemos encontrado coincidencias.';
			if (!sheet) this.inputTarget.setAttribute('aria-expanded', term && results.length ? 'true' : 'false');
			if (sheet) this.renderGrouped(results, this.sheetResultsTarget);
			else this.renderInline(results, term);
		} catch (error) {
			if (error.name === 'AbortError') return;
			this.statusTarget.textContent = 'No se pudo cargar. Comprueba tu conexión.';
			this.retryTarget.classList.remove('hidden');
		}
	}

	renderInline(results, term) {
		this.resultsTarget.replaceChildren();
		this.featuredTarget.replaceChildren();
		if (!term) {
			const featured = results.filter((item) => item.featured).slice(0, 5);
			if (featured.length) {
				const title = document.createElement('p');
				title.className = 'w-full text-sm font-semibold text-ink-muted';
				title.textContent = this.modeValue === 'multiple' ? 'Opciones frecuentes' : 'Habituales';
				this.featuredTarget.append(title, ...featured.map((item) => this.button(item, true)));
			}
			return;
		}
		results.forEach((item) => this.resultsTarget.append(this.button(item, false)));
	}

	renderGrouped(results, container) {
		container.replaceChildren();
		const labels = { habitual: 'Habituales', hospitalization: 'Hospitalización', support: 'Apoyo' };
		for (const group of ['habitual', 'hospitalization', 'support']) {
			const items = results.filter((item) => (item.group || 'habitual') === group);
			if (!items.length) continue;
			const heading = document.createElement('h3');
			heading.className = 'picker-group-title';
			heading.textContent = labels[group];
			container.append(heading, ...items.map((item) => this.button(item, false)));
		}
	}

	button(item, chip) {
		const button = document.createElement('button');
		button.type = 'button';
		button.className = chip ? 'picker-chip' : 'picker-option';
		button.dataset.pickerId = item.id;
		button.setAttribute('role', 'option');
		button.setAttribute('aria-selected', this.items.has(item.id) ? 'true' : 'false');
		const mark = document.createElement('span');
		mark.className = chip ? 'picker-chip-mark' : 'picker-check';
		mark.textContent = this.items.has(item.id) ? '✓' : '';
		const copy = document.createElement('span');
		copy.className = 'picker-option-copy';
		const text = document.createElement('span');
		text.className = 'picker-option-name';
		text.textContent = item.name;
		copy.append(text);
		const detail = this.detail(item);
		if (!chip && detail) {
			const meta = document.createElement('span');
			meta.className = 'picker-option-detail';
			meta.textContent = detail;
			copy.append(meta);
		}
		button.append(mark, copy);
		button.addEventListener('click', () => this.choose(item));
		return button;
	}

	choose(item) {
		if (this.modeValue === 'multiple') {
			if (this.items.has(item.id)) this.items.delete(item.id);
			else this.items.set(item.id, item);
			this.inputTarget.value = '';
			this.sheetInputTarget.value = '';
		} else {
			this.items.clear();
			this.items.set(item.id, item);
			this.inputTarget.value = item.name;
			if (this.dialogTarget.open) this.close();
		}
		this.renderSelection();
		this.syncOptionStates();
		this.element.dispatchEvent(new CustomEvent('picker:selected', { bubbles: true, detail: { name: this.valueTarget.name, values: [...this.items.keys()] } }));
	}

	renderSelection() {
		this.valueTarget.value = [...this.items.keys()].join(',');
		this.element.dataset.selectedLabels = [...this.items.values()].map((item) => item.name).join(', ');
		this.selectionTarget.replaceChildren();
		if (this.modeValue !== 'multiple') {
			this.inputTarget.value = [...this.items.values()][0]?.name || '';
			return;
		}
		for (const item of this.items.values()) {
			const selected = document.createElement('span');
			selected.className = 'selected-item';
			selected.textContent = item.name;
			if (this.modeValue === 'multiple') {
				const remove = document.createElement('button');
				remove.type = 'button';
				remove.setAttribute('aria-label', `Quitar ${item.name}`);
				remove.textContent = '×';
				remove.addEventListener('click', () => {
					this.items.delete(item.id);
					this.renderSelection();
					this.syncOptionStates();
					this.element.dispatchEvent(new CustomEvent('picker:selected', { bubbles: true, detail: { name: this.valueTarget.name, values: [...this.items.keys()] } }));
				});
				selected.append(remove);
			}
			this.selectionTarget.append(selected);
		}
	}

	async createLocal() {
		const name = this.localNameTarget.value.trim();
		if (!name) return;
		const body = new URLSearchParams({ name });
		const response = await fetch(`${this.endpoint().split('?')[0]}/local`, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': document.querySelector('[data-onboarding-csrf-value]').dataset.onboardingCsrfValue, Accept: 'application/json' } });
		const payload = await response.json();
		if (!response.ok) { this.statusTarget.textContent = payload.error || 'No se pudo añadir la unidad.'; return; }
		this.choose(payload.result);
	}

	keydown(event, container) {
		const options = [...container.querySelectorAll('[role="option"]')];
		if (event.key === 'Escape' && this.dialogTarget.open) { this.close(); return; }
		if (!['ArrowDown', 'ArrowUp', 'Enter'].includes(event.key) || !options.length) return;
		event.preventDefault();
		const focused = options.indexOf(document.activeElement);
		if (event.key === 'Enter' && focused >= 0) { options[focused].click(); return; }
		const next = event.key === 'ArrowUp' ? Math.max(0, focused - 1) : Math.min(options.length - 1, focused + 1);
		options[next].focus();
	}

	ready() {
		return !this.endpointValue.includes('{workplace}') || Boolean(document.querySelector('[name="workplace_id"]')?.value);
	}

	endpoint() {
		return this.endpointValue.replace('{workplace}', encodeURIComponent(document.querySelector('[name="workplace_id"]')?.value || ''));
	}

	available(results) {
		if (!this.excludeFieldValue) return results;
		const excluded = document.querySelector(`[name="${this.excludeFieldValue}"]`)?.value;
		return excluded ? results.filter((item) => item.id !== excluded) : results;
	}

	detail(item) {
		if (item.municipality) {
			return item.province && item.province.toLocaleLowerCase('es') !== item.municipality.toLocaleLowerCase('es')
				? `${item.municipality} · ${item.province}`
				: item.municipality;
		}
		return item.description || '';
	}

	syncOptionStates() {
		for (const option of this.element.querySelectorAll('[data-picker-id]')) {
			const selected = this.items.has(option.dataset.pickerId);
			option.setAttribute('aria-selected', selected ? 'true' : 'false');
			const check = option.querySelector('.picker-check, .picker-chip-mark');
			if (check) check.textContent = selected ? '✓' : '';
		}
	}
}
