import { Controller } from '@hotwired/stimulus';

/*
 * "Which of my shifts do I want covered."
 *
 * The list of radios is the real form and it is rendered by the server, so this
 * controller never builds a selection of its own: the calendar and the
 * recommendations tick one of those inputs and everything else follows from the
 * change event. That is what makes choosing from the calendar and choosing from
 * the list the same command, and it is why the page still works with the
 * calendar turned off.
 *
 * Every number it writes was computed on the server. Nothing here recalculates
 * a duration, a balance or a rest block from a date.
 */
export default class extends Controller {
	static targets = [
		'views', 'calendarTab', 'listTab', 'calendarPanel', 'listPanel',
		'cell', 'option', 'weekLink',
		'summary', 'offered', 'balance', 'recap', 'bridge', 'submit',
		'sheet', 'sheetTitle', 'sheetKicker', 'sheetBody',
	];

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

	/** A day in the calendar: show what it is before anything is decided. */
	pick(event) {
		const cell = event.currentTarget;
		const date = cell.dataset.date;
		if (cell.dataset.selectable === 'true' && cell.dataset.shiftKey) this.select(cell.dataset.shiftKey);
		this.openDetail(date);
	}

	/** The button under a recommendation. It picks; it does not submit. */
	choose(event) {
		event.preventDefault();
		this.select(event.currentTarget.dataset.shiftKey);
		if (this.hasSummaryTarget) this.summaryTarget.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
	}

	select(key) {
		const radio = this.radioFor(key);
		if (!radio) return;
		radio.checked = true;
		this.sync();
	}

	sync() {
		const radio = this.element.querySelector('input[name="offeredShift"]:checked');
		const key = radio ? radio.value : '';
		for (const cell of this.cellTargets) {
			if (cell.hasAttribute('aria-pressed')) cell.setAttribute('aria-pressed', String(cell.dataset.shiftKey === key && key !== ''));
		}
		this.updateSummary(key ? this.optionFor(key) : null);
		this.updateWeekLinks(key);
	}

	updateSummary(option) {
		if (this.hasSubmitTarget) this.submitTarget.disabled = !option;
		if (!option) {
			this.offeredTarget.textContent = '—';
			this.balanceTarget.textContent = '—';
			this.recapTarget.textContent = 'Todavía no has elegido ningún turno.';
			this.bridgeTarget.classList.add('hidden');
			return;
		}
		this.offeredTarget.textContent = option.dataset.duration || '';
		this.balanceTarget.textContent = option.dataset.balance || '';
		this.recapTarget.textContent = `Propones ${option.dataset.headline} · ${option.dataset.hours}`;
		this.bridgeTarget.textContent = option.dataset.opportunity ? `✨ Conseguirías ${option.dataset.opportunity}` : '';
		this.bridgeTarget.classList.toggle('hidden', !option.dataset.opportunity);
	}

	/** Paging weeks is a link, so it has to carry the choice already made. */
	updateWeekLinks(key) {
		for (const link of this.weekLinkTargets) {
			const url = new URL(link.href, window.location.origin);
			if (key) url.searchParams.set('turno', key);
			else url.searchParams.delete('turno');
			link.href = `${url.pathname}${url.search}`;
		}
	}

	openDetail(date) {
		if (!this.hasSheetTarget) return;
		const cells = this.cellTargets;
		const index = cells.findIndex((cell) => cell.dataset.date === date);
		const cell = cells[index];
		if (!cell) return;

		this.sheetKicker(cell);
		this.sheetTitleTarget.textContent = cell.dataset.headline || date;
		this.sheetBodyTarget.replaceChildren(
			this.contextStrip(cells, index),
			...this.shiftCards(date, cell),
		);
		this.sheetTarget.dispatchEvent(new CustomEvent('sheet:open'));
	}

	sheetKicker(cell) {
		const opportunity = cell.dataset.opportunity;
		this.sheetKickerTarget.textContent = opportunity ? `✨ ${opportunity}` : cell.dataset.stateLabel || '';
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
			empty.textContent = cell.dataset.blocked
				? cell.dataset.blocked
				: `${cell.dataset.stateLabel}. No hay ningún turno tuyo que ofrecer este día.`;
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
			if (row.dataset.opportunity) card.append(this.line('composer-opportunity', `★ ${row.dataset.opportunity}`));
			if (row.dataset.blockedReason) {
				card.append(this.line('composer-blocked-reason', `No disponible para cambio · ${row.dataset.blockedReason}`));
				return card;
			}
			card.append(this.line('trade-balance', `Diferencia para ti: ${row.dataset.balance} · ${row.dataset.balanceHint}`));
			const choose = document.createElement('button');
			choose.type = 'button';
			choose.className = 'btn-primary btn-block';
			choose.textContent = 'Elegir este turno';
			choose.addEventListener('click', () => {
				this.select(row.dataset.shiftKey);
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

	radioFor(key) {
		return this.element.querySelector(`input[name="offeredShift"][value="${CSS.escape(key)}"]`);
	}

	optionFor(key) {
		return this.optionTargets.find((option) => option.dataset.shiftKey === key) ?? null;
	}
}
