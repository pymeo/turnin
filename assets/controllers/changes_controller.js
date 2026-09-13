import { Controller } from '@hotwired/stimulus';

// Los siete tipos que un cuadrante puede contener. «Cualquier turno» significa
// todos: ofrecer menos que lo publicable deja solicitudes sin emparejar jamás.
const OFFERABLE_KINDS = ['morning', 'evening', 'night', 'long_day', 'long_night', 'on_call', 'other'];
const KIND_LABELS = { morning: 'Mañana', evening: 'Tarde', night: 'Noche', long_day: '12 h (día)', long_night: '12 h (noche)', on_call: 'Guardia', other: 'Otro' };
const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

export default class extends Controller {
	static targets = [
		'error', 'sheet', 'stepLabel', 'title', 'releasePick', 'shiftList', 'noShifts', 'releaseConfirm', 'releaseRecap',
		'availabilityDates', 'monthTitle', 'dateGrid', 'dateCount', 'datesContinue', 'availabilityKinds', 'kindsContinue',
		'availabilityPlaces', 'placeList', 'placesContinue', 'availabilitySummary', 'availabilityRecap', 'deleteDay',
		'success', 'successTitle', 'successCopy', 'successAvailable', 'flowError',
	];
	static values = {
		csrf: String, upcoming: Array, workingDates: Array, groups: Array, availability: Array, from: String, to: String,
	};

	connect() {
		this.selectedDates = new Set();
		this.selectedKinds = new Set();
		this.selectedPools = new Set();
		this.selectedShift = null;
		this.editingDate = null;
		if (!this.hasSheetTarget) return;

		const query = new URLSearchParams(window.location.search);
		if (query.get('edit')) this.startAvailability(null, query.get('edit'));
		else if (query.get('flow') === 'availability') this.startAvailability(null, null, query.get('date'));
		else if (query.get('flow') === 'release') this.startRelease();
	}

	startRelease() {
		this.editingDate = null;
		this.showPanel('releasePick', 'Tus próximos turnos', '¿Qué turno quieres librar?');
		this.shiftListTarget.replaceChildren();
		this.noShiftsTarget.classList.toggle('hidden', this.upcomingValue.length > 0);

		let previousMonth = '';
		this.upcomingValue.forEach((shift, index) => {
			const month = shift.date.slice(0, 7);
			if (month !== previousMonth) {
				const heading = document.createElement('h3');
				heading.className = 'flow-month';
				heading.textContent = `${MONTHS[Number(month.slice(5)) - 1]} ${month.slice(0, 4)}`;
				this.shiftListTarget.append(heading);
				previousMonth = month;
			}
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'shift-choice';
			button.dataset.index = String(index);
			button.dataset.kind = shift.shiftKind;
			button.addEventListener('click', () => this.pickShift(index));
			const date = document.createElement('span');
			date.className = 'shift-choice-date';
			const [weekday = '', day = '', shortMonth = ''] = shift.dateHeadline.split(' ');
			const weekdayNode = document.createElement('small'); weekdayNode.textContent = weekday.slice(0, 3);
			const dayNode = document.createElement('strong'); dayNode.textContent = day;
			const monthNode = document.createElement('small'); monthNode.textContent = shortMonth;
			date.append(weekdayNode, dayNode, monthNode);
			const copy = document.createElement('span');
			copy.className = 'shift-choice-copy';
			const name = document.createElement('strong');
			name.textContent = shift.shiftLabel;
			const hours = document.createElement('small');
			hours.textContent = shift.hours;
			const place = document.createElement('small');
			place.className = 'shift-choice-place';
			place.textContent = shift.destinationLabel;
			copy.append(name, hours, place);
			const state = document.createElement('span');
			state.className = shift.alreadyOpen ? 'shift-choice-open' : 'shift-choice-arrow';
			state.textContent = shift.alreadyOpen ? 'Buscando ✓' : '›';
			button.append(date, copy, state);
			this.shiftListTarget.append(button);
		});
		this.openSheet();
	}

	pickShift(index) {
		this.selectedShift = this.upcomingValue[index];
		const shift = this.selectedShift;
		this.releaseRecapTarget.replaceChildren(
			this.recapLine(shift.dateHeadline, true), this.recapLine(`${shift.shiftLabel} · ${shift.hours}`),
			this.recapLine(shift.workplaceName, true), this.recapLine(shift.destinationLabel),
		);
		const button = this.releaseConfirmTarget.querySelector('[data-action="changes#confirmRelease"]');
		button.textContent = shift.alreadyOpen ? 'Dejar de buscar' : 'Buscar compañero';
		this.showPanel('releaseConfirm', shift.alreadyOpen ? 'Turno publicado' : 'Confirmar', shift.alreadyOpen ? 'Ya estamos buscando compañero' : 'Quieres librar este turno');
	}

	backToShifts() { this.startRelease(); }

	async confirmRelease(event) {
		const shift = this.selectedShift;
		if (!shift) return;
		this.busy(event.currentTarget, true);
		try {
			if (shift.alreadyOpen) await this.post(`/app/changes/${shift.requestId}/retirar`);
			else await this.post('/app/changes/publicar', { assignmentId: shift.assignmentId, date: shift.date, swapPoolId: shift.swapPoolId });
			this.showSuccess(shift.alreadyOpen ? 'Ya no buscamos compañero' : 'Estamos buscando a alguien', shift.alreadyOpen ? 'El turno ya no aparece a tus compañeros.' : 'Te avisaremos cuando haya compañeros disponibles.', false);
		} catch (error) { this.showFlowError(error.message); }
		finally { this.busy(event.currentTarget, false); }
	}

	startAvailability(event = null, editDate = null, initialDate = null) {
		this.editingDate = editDate;
		this.selectedDates = new Set(editDate || initialDate ? [editDate || initialDate] : []);
		this.selectedKinds = new Set();
		this.selectedPools = new Set();
		if (editDate) {
			const existing = this.availabilityValue.find((day) => day.date === editDate);
			(existing?.shiftKinds ?? []).forEach((kind) => this.selectedKinds.add(kind));
			(existing?.poolIds ?? []).forEach((pool) => this.selectedPools.add(pool));
		}
		const initial = editDate || initialDate || this.fromValue || new Date().toISOString().slice(0, 10);
		this.visibleMonth = initial.slice(0, 7);
		this.renderCalendar();
		this.showPanel('availabilityDates', 'Paso 1 de 4', '¿Cuándo puedes trabajar?');
		this.openSheet();
		if (editDate) this.continueToKinds();
	}

	renderCalendar() {
		const [year, month] = this.visibleMonth.split('-').map(Number);
		this.monthTitleTarget.textContent = `${MONTHS[month - 1]} ${year}`;
		this.dateGridTarget.replaceChildren();
		const first = new Date(Date.UTC(year, month - 1, 1));
		const offset = (first.getUTCDay() + 6) % 7;
		const days = new Date(Date.UTC(year, month, 0)).getUTCDate();
		for (let i = 0; i < offset; i += 1) this.dateGridTarget.append(document.createElement('span'));
		for (let day = 1; day <= days; day += 1) {
			const date = `${year}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
			const working = this.workingDatesValue.includes(date);
			const outside = date < this.fromValue || date > this.toValue;
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'availability-day';
			button.textContent = String(day);
			button.dataset.date = date;
			button.disabled = working || outside;
			button.setAttribute('aria-selected', this.selectedDates.has(date) ? 'true' : 'false');
			button.setAttribute('aria-label', working ? `${day}, ya trabajas` : `${day} de ${MONTHS[month - 1]}`);
			if (working) { button.classList.add('is-working'); button.title = 'Ya trabajas'; }
			button.addEventListener('click', () => this.toggleDate(date));
			this.dateGridTarget.append(button);
		}
		this.updateDateCount();
	}

	toggleDate(date) {
		if (this.selectedDates.has(date)) this.selectedDates.delete(date); else this.selectedDates.add(date);
		this.renderCalendar();
	}

	previousMonth() { this.moveMonth(-1); }
	nextMonth() { this.moveMonth(1); }
	moveMonth(delta) {
		const [year, month] = this.visibleMonth.split('-').map(Number);
		const next = new Date(Date.UTC(year, month - 1 + delta, 1));
		this.visibleMonth = `${next.getUTCFullYear()}-${String(next.getUTCMonth() + 1).padStart(2, '0')}`;
		this.renderCalendar();
	}
	updateDateCount() {
		const count = this.selectedDates.size;
		this.dateCountTarget.textContent = count === 0 ? 'Ningún día seleccionado' : `${count} ${count === 1 ? 'día seleccionado' : 'días seleccionados'}`;
		this.datesContinueTarget.disabled = count === 0;
	}

	continueToKinds() {
		if (!this.selectedDates.size) return;
		this.showPanel('availabilityKinds', 'Paso 2 de 4', '¿Qué turnos podrías hacer?');
		this.renderKinds();
	}
	backToDates() { this.showPanel('availabilityDates', 'Paso 1 de 4', '¿Cuándo puedes trabajar?'); this.renderCalendar(); }
	toggleKind(event) {
		const kind = event.currentTarget.dataset.kind;
		if (kind === 'any') {
			const all = OFFERABLE_KINDS.every((value) => this.selectedKinds.has(value));
			this.selectedKinds = new Set(all ? [] : OFFERABLE_KINDS);
		} else if (this.selectedKinds.has(kind)) this.selectedKinds.delete(kind); else this.selectedKinds.add(kind);
		this.renderKinds();
	}
	renderKinds() {
		this.availabilityKindsTarget.querySelectorAll('[data-kind]').forEach((button) => {
			const kind = button.dataset.kind;
			const selected = kind === 'any' ? OFFERABLE_KINDS.every((value) => this.selectedKinds.has(value)) : this.selectedKinds.has(kind);
			button.setAttribute('aria-pressed', selected ? 'true' : 'false');
		});
		this.kindsContinueTarget.disabled = this.selectedKinds.size === 0;
	}

	continueToPlaces() {
		if (!this.selectedKinds.size) return;
		if (this.groupsValue.length === 0) {
			this.showFlowError('No tienes ningún grupo de intercambio válido. Revisa tu lugar de trabajo antes de continuar.');
			return;
		}
		if (this.groupsValue.length === 1) {
			this.selectedPools = new Set([this.groupsValue[0].poolId]);
			this.continueToSummary();
			return;
		}
		this.renderPlaces();
		this.showPanel('availabilityPlaces', 'Paso 3 de 4', '¿Para qué grupo estás disponible?');
	}
	backToKinds() { this.showPanel('availabilityKinds', 'Paso 2 de 4', '¿Qué turnos podrías hacer?'); this.renderKinds(); }
	renderPlaces() {
		this.placeListTarget.replaceChildren();
		const byWorkplace = this.groupBy(this.groupsValue, (group) => group.workplaceName);
		Object.entries(byWorkplace).forEach(([workplace, groups]) => {
			const section = document.createElement('fieldset');
			section.className = 'place-group';
			const legend = document.createElement('legend'); legend.textContent = workplace; section.append(legend);
			groups.forEach((group) => {
				const label = document.createElement('label'); label.className = 'place-choice';
				const input = document.createElement('input'); input.type = 'checkbox'; input.value = group.poolId; input.checked = this.selectedPools.has(group.poolId);
				input.addEventListener('change', () => { if (input.checked) this.selectedPools.add(group.poolId); else this.selectedPools.delete(group.poolId); this.placesContinueTarget.disabled = this.selectedPools.size === 0; });
				label.append(input, document.createTextNode(group.label)); section.append(label);
			});
			this.placeListTarget.append(section);
		});
		this.placesContinueTarget.disabled = this.selectedPools.size === 0;
	}

	continueToSummary() {
		if (!this.selectedPools.size || !this.selectedKinds.size) return;
		this.availabilityRecapTarget.replaceChildren();
		const dates = [...this.selectedDates].sort().map((date) => this.humanDate(date));
		this.availabilityRecapTarget.append(this.recapHeading('Vas a indicar que puedes trabajar:'), ...dates.map((date) => this.recapLine(date, true)));
		this.availabilityRecapTarget.append(this.recapHeading('Turnos:'), this.recapLine(OFFERABLE_KINDS.filter((kind) => this.selectedKinds.has(kind)).map((kind) => KIND_LABELS[kind]).join(' · ')));
		const groups = this.groupsValue.filter((group) => this.selectedPools.has(group.poolId));
		const workplaces = [...new Set(groups.map((group) => group.workplaceName))];
		this.availabilityRecapTarget.append(this.recapHeading('En:'));
		workplaces.forEach((workplace) => {
			this.availabilityRecapTarget.append(this.recapLine(workplace, true), this.recapLine(groups.filter((group) => group.workplaceName === workplace).map((group) => group.label).join(' · ')));
		});
		this.deleteDayTarget.classList.toggle('hidden', !this.editingDate);
		this.deleteDayTarget.textContent = this.editingDate ? `Eliminar disponibilidad del ${this.humanDate(this.editingDate).toLowerCase()}` : '';
		this.showPanel('availabilitySummary', 'Paso 4 de 4', 'Revisa tu disponibilidad');
	}
	backFromSummary() { this.groupsValue.length === 1 ? this.backToKinds() : this.continueToPlaces(); }

	async saveAvailability(event) {
		this.busy(event.currentTarget, true);
		try {
			await this.post('/app/changes/disponible', { dates: [...this.selectedDates], shiftKinds: [...this.selectedKinds], swapPoolIds: [...this.selectedPools] });
			this.showSuccess('Disponibilidad guardada', 'Ahora podremos avisarte cuando alguien necesite cubrir un turno que encaje.', true);
		} catch (error) { this.showFlowError(error.message); }
		finally { this.busy(event.currentTarget, false); }
	}

	async deleteAvailabilityDay(event) {
		if (!this.editingDate || !window.confirm(`¿Eliminar tu disponibilidad del ${this.humanDate(this.editingDate).toLowerCase()}?`)) return;
		this.busy(event.currentTarget, true);
		try { await this.post('/app/changes/disponibilidad/retirar', { date: this.editingDate }); this.showSuccess('Disponibilidad eliminada', 'Ese día ya no se tendrá en cuenta para encontrar turnos.', false); }
		catch (error) { this.showFlowError(error.message); }
		finally { this.busy(event.currentTarget, false); }
	}

	async offer(event) {
		const button = event.currentTarget; this.busy(button, true);
		try { await this.post(`/app/changes/${button.dataset.requestId}/puedo`); const done = document.createElement('p'); done.className = 'swap-card-done'; done.textContent = '✓ Encaja con tu disponibilidad'; button.replaceWith(done); }
		catch (error) { this.showError(error.message); this.busy(button, false); }
	}
	async cancelRequest(event) {
		const button = event.currentTarget; this.busy(button, true);
		try { await this.post(`/app/changes/${button.dataset.requestId}/retirar`); window.location.reload(); }
		catch (error) { this.showError(error.message); this.busy(button, false); }
	}

	showPanel(name, step, title) {
		['releasePick', 'releaseConfirm', 'availabilityDates', 'availabilityKinds', 'availabilityPlaces', 'availabilitySummary', 'success'].forEach((target) => this[`${target}Target`].classList.toggle('hidden', target !== name));
		this.stepLabelTarget.textContent = step; this.titleTarget.textContent = title; this.clearFlowError();
	}
	showSuccess(title, copy, available) {
		this.showPanel('success', '', title); this.successTitleTarget.textContent = `✓ ${title}`; this.successCopyTarget.textContent = copy; this.successAvailableTarget.classList.toggle('hidden', !available);
	}
	openSheet() {
		// When a flow comes from the URL, this parent controller connects before
		// the bottom-sheet controller attached to the child <dialog>. Defer the
		// event until every controller in the current Stimulus scope is ready.
		queueMicrotask(() => {
			if (!this.sheetTarget.open) this.sheetTarget.dispatchEvent(new CustomEvent('sheet:open'));
			// Keep the URL entry point robust if Stimulus connection order changes.
			if (!this.sheetTarget.open) this.sheetTarget.showModal();
		});
	}
	finish() { window.location.assign('/app/changes'); }
	recapLine(text, strong = false) { const line = document.createElement(strong ? 'strong' : 'p'); line.textContent = text; return line; }
	recapHeading(text) { const line = document.createElement('p'); line.className = 'flow-recap-heading'; line.textContent = text; return line; }
	humanDate(value) { const date = new Date(`${value}T12:00:00Z`); return new Intl.DateTimeFormat('es-ES', { weekday: 'long', day: 'numeric', month: 'long', timeZone: 'UTC' }).format(date); }
	groupBy(items, keyFor) { return items.reduce((groups, item) => { const key = keyFor(item); (groups[key] ??= []).push(item); return groups; }, {}); }
	async post(url, body = {}) { const response = await fetch(url, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfValue, Accept: 'application/json' }, body: JSON.stringify(body) }); const payload = await response.json().catch(() => ({})); if (!response.ok) throw new Error(payload.error || 'No se pudo completar la operación.'); return payload.result; }
	busy(button, active) { button.disabled = active; button.setAttribute('aria-busy', active ? 'true' : 'false'); }
	showError(message) { if (!this.hasErrorTarget) return; this.errorTarget.textContent = message; this.errorTarget.classList.remove('hidden'); }
	showFlowError(message) { this.flowErrorTarget.textContent = message; this.flowErrorTarget.classList.remove('hidden'); }
	clearFlowError() { this.flowErrorTarget.textContent = ''; this.flowErrorTarget.classList.add('hidden'); }
}
