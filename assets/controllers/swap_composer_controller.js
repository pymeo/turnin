import { Controller } from '@hotwired/stimulus';

/*
 * "Which of my shifts could you do?" — picking between one and five of them.
 *
 * The checkboxes are rendered by the server and are the real form, so this
 * controller never builds a selection of its own: the calendar ticks one of
 * those inputs and everything else follows from the change event. That is what
 * makes choosing from the calendar and choosing from the list the same thing,
 * and it is why the page still works with the calendar turned off.
 *
 * Every label it writes was computed on the server. Nothing here recalculates a
 * duration, a compatibility or a rest block from a date.
 */
export default class extends Controller {
	static targets = [
		'views', 'calendarTab', 'listTab', 'calendarPanel', 'listPanel',
		'cell', 'option', 'away', 'weekLink',
		'summary', 'count', 'chosen', 'submit',
		'sheet', 'sheetTitle', 'sheetKicker', 'sheetBody',
	];

	static values = { maximum: Number, author: String };

	connect() {
		// Progressive enhancement: the list ships visible, and the calendar only
		// becomes the default view once there is something to switch back with.
		if (this.hasViewsTarget) this.viewsTarget.classList.remove('hidden');
		this.showCalendar();
		this.sync();
	}

	showCalendar() {
		this.swapPanels(true);
	}

	showList() {
		this.swapPanels(false);
	}

	swapPanels(calendar) {
		if (this.hasCalendarPanelTarget) this.calendarPanelTarget.classList.toggle('hidden', !calendar);
		if (this.hasListPanelTarget) this.listPanelTarget.classList.toggle('hidden', calendar);
		if (this.hasCalendarTabTarget) this.calendarTabTarget.setAttribute('aria-pressed', String(calendar));
		if (this.hasListTabTarget) this.listTabTarget.setAttribute('aria-pressed', String(!calendar));
	}

	/** A day in the calendar: tick it, and say what it is. */
	pick(event) {
		const cell = event.currentTarget;
		if (cell.dataset.selectable === 'true' && cell.dataset.shiftKey) {
			this.toggle(cell.dataset.shiftKey);
			return;
		}
		this.openDetail(cell.dataset.date);
	}

	toggle(key) {
		const box = this.boxFor(key);
		if (!box) return;
		if (!box.checked && this.selected().length >= this.limit()) {
			this.announceLimit();
			return;
		}
		box.checked = !box.checked;
		this.sync();
	}

	sync() {
		const keys = this.selected();
		for (const cell of this.cellTargets) {
			if (!cell.hasAttribute('aria-pressed')) continue;
			const chosen = keys.includes(cell.dataset.shiftKey);
			cell.setAttribute('aria-pressed', String(chosen));
			const status = cell.querySelector('[data-swap-composer-target="cellStatus"]');
			if (status) {
				status.replaceChildren();
				const symbol = document.createElement('b');
				symbol.textContent = chosen ? '✓' : '+';
				status.append(symbol, document.createTextNode(chosen ? ' ELEGIDO' : ' PUEDE'));
			}
		}
		this.renderSummary(keys);
		this.updateWeekLinks(keys);
	}

	selected() {
		return [...new Set(
			[...this.element.querySelectorAll('input[name="offeredShifts[]"]:checked')].map((box) => box.value),
		)];
	}

	limit() {
		return this.hasMaximumValue && this.maximumValue > 0 ? this.maximumValue : 5;
	}

	renderSummary(keys) {
		if (this.hasCountTarget) this.countTarget.textContent = `${keys.length} de ${this.limit()} elegidos`;
		if (this.hasSubmitTarget) {
			this.submitTarget.disabled = keys.length === 0;
			this.submitTarget.textContent = keys.length === 0
				? 'Elige un turno'
				: `Enviar ${keys.length} ${keys.length === 1 ? 'opción' : 'opciones'}`;
		}
		if (!this.hasChosenTarget) return;
		this.chosenTarget.replaceChildren(...keys.map((key) => {
			const option = this.optionFor(key);
			const fallbackDate = key.includes('|') ? key.split('|').at(-1) : key;
			const item = document.createElement('li');
			item.textContent = option ? `${option.dataset.headline} · ${option.dataset.hours}` : fallbackDate;
			const drop = document.createElement('button');
			drop.type = 'button';
			drop.className = 'composer-chosen-drop';
			drop.setAttribute('aria-label', `Quitar ${option ? option.dataset.headline : fallbackDate}`);
			drop.textContent = '×';
			drop.addEventListener('click', () => this.toggle(key));
			item.append(drop);
			return item;
		}));
	}

	announceLimit() {
		if (!this.hasCountTarget) return;
		this.countTarget.textContent = `Ya has elegido ${this.limit()}. Quita uno para añadir otro.`;
	}

	/** Paging weeks is a link, so it has to carry the choices already made. */
	updateWeekLinks(keys) {
		for (const link of this.weekLinkTargets) {
			const url = new URL(link.href, window.location.origin);
			// Twig serialises an array as turnos[0], turnos[1]… while this
			// controller appends turnos[]. Remove every representation first or
			// each trip to another month duplicates the selection.
			for (const parameter of [...url.searchParams.keys()]) {
				if (parameter === 'turnos' || parameter.startsWith('turnos[')) url.searchParams.delete(parameter);
			}
			for (const key of keys) url.searchParams.append('turnos[]', key);
			link.href = `${url.pathname}${url.search}`;
		}
	}

	openDetail(date) {
		if (!this.hasSheetTarget) return;
		const cells = this.cellTargets;
		const index = cells.findIndex((cell) => cell.dataset.date === date);
		const cell = cells[index];
		if (!cell) return;

		this.sheetKickerTarget.textContent = cell.dataset.stateLabel || '';
		this.sheetTitleTarget.textContent = cell.dataset.headline || date;
		this.sheetBodyTarget.replaceChildren(this.contextStrip(cells, index), ...this.shiftCards(date, cell));
		this.sheetTarget.dispatchEvent(new CustomEvent('sheet:open'));
	}

	/**
	 * Two days either side. Read straight off the grid, so it can never disagree
	 * with the calendar it came from.
	 */
	contextStrip(cells, index) {
		const strip = document.createElement('div');
		strip.className = 'composer-sheet-context';
		for (let offset = -2; offset <= 2; offset += 1) {
			const neighbour = cells[index + offset];
			if (!neighbour) continue;
			const item = document.createElement('span');
			item.className = 'composer-sheet-context-day';
			item.dataset.state = neighbour.dataset.state || 'unknown';
			item.dataset.current = String(offset === 0);
			const when = document.createElement('b');
			when.textContent = neighbour.dataset.headline || '';
			const what = document.createElement('i');
			what.textContent = neighbour.dataset.stateLabel || '';
			item.append(when, what);
			strip.append(item);
		}
		return strip;
	}

	/** One card per shift that day, so two shifts on one date still fit. */
	shiftCards(date, cell) {
		const rows = this.optionTargets.filter((option) => option.dataset.date === date);
		if (rows.length === 0) {
			const empty = document.createElement('p');
			empty.className = 'composer-sheet-empty';
			empty.textContent = cell.dataset.blocked ?? `${cell.dataset.stateLabel}. No hay ningún turno tuyo este día.`;
			return [empty];
		}

		return rows.map((row) => {
			const card = document.createElement('article');
			card.className = 'composer-sheet-shift';
			card.append(
				this.line('trade-side-label', row.dataset.headline || ''),
				this.line('shift-card-hours', row.dataset.hours || ''),
				this.line('shift-card-duration', (row.dataset.duration || '').toUpperCase()),
				this.line('composer-sheet-kind', row.dataset.shiftLabel || ''),
			);
			if (row.dataset.recommendation) card.append(this.line('composer-opportunity', `★ ${row.dataset.recommendation}`));
			if (row.dataset.compatibilityNote) card.append(this.line('compatibility-note', `⚠ ${row.dataset.compatibilityNote}`));
			if (row.dataset.blockedReason) {
				card.append(this.line('composer-blocked-reason', row.dataset.blockedReason));
				return card;
			}
			const key = row.dataset.shiftKey;
			const choose = document.createElement('button');
			choose.type = 'button';
			choose.className = 'btn-primary btn-block';
			choose.textContent = this.selected().includes(key) ? 'Quitar de las opciones' : 'Añadir a las opciones';
			choose.addEventListener('click', () => {
				this.toggle(key);
				this.sheetTarget.dispatchEvent(new CustomEvent('sheet:close'));
			});
			card.append(choose);
			return card;
		});
	}

	line(className, text) {
		const node = document.createElement('p');
		node.className = className;
		node.textContent = text;
		return node;
	}

	boxFor(key) {
		return this.element.querySelector(`input[name="offeredShifts[]"][value="${CSS.escape(key)}"]`);
	}

	optionFor(key) {
		return this.optionTargets.find((option) => option.dataset.shiftKey === key) ?? null;
	}
}
