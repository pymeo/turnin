import { Controller } from '@hotwired/stimulus';
import { applyTone, closeSheet, configureCalendarContext, getJson, openSheet, postJson } from '../scheduling/roster_api.js';

/*
 * The month screen: paging, tapping a day, and opening the sheets.
 *
 * It owns no draft state. Painting, rotations and dictation each have their own
 * controller, and all of them hand their result to schedule-draft, so there is
 * exactly one confirm-and-write path.
 */
export default class extends Controller {
	static targets = ['grid', 'summary', 'title', 'emptyState', 'addSheet', 'daySheet', 'daySheetTitle', 'daySheetDetail', 'daySheetClear', 'manualFields', 'manualLabel', 'manualAbbreviation', 'manualStart', 'manualEnd', 'manualKind', 'manualColor', 'patternSheet', 'voiceSheet', 'voiceChoice', 'detection', 'detectionText', 'addButton'];
	static values = { month: String, today: String, csrf: String, assignment: String, view: String };

	connect() {
		configureCalendarContext(this.hasAssignmentValue ? this.assignmentValue : null, this.hasViewValue ? this.viewValue : null);
		this.painting = false;
		this.selectedDate = null;
		this.detected = null;
		this.element.addEventListener('calendar:changed', (event) => this.changed(event));
		this.element.addEventListener('paint:mode', (event) => this.paintModeChanged(event));
		this.element.addEventListener('pattern:ready', () => closeSheet(this.patternSheetTarget));
		// Delegated once on the root: the grid element itself is replaced on
		// every month change, and a listener bound to it would go with it.
		this.element.addEventListener('click', (event) => this.dayClicked(event));
		// A microphone button that does nothing is worse than no microphone
		// button: only offer the direct one where the browser can honour it.
		if (!this.speechAvailable()) this.voiceChoiceTarget.classList.add('hidden');
		this.refreshDetection();
	}

	previous() {
		this.load(this.titleTarget.dataset.previousMonth || this.shiftMonth(-1));
	}

	next() {
		this.load(this.titleTarget.dataset.nextMonth || this.shiftMonth(1));
	}

	goToToday() {
		this.load(this.todayValue.slice(0, 7));
	}

	async load(month) {
		try {
			const payload = await getJson(`/app/calendar/grid?month=${encodeURIComponent(month)}`);
			this.monthValue = payload.month;
			this.titleTarget.textContent = payload.title;
			this.titleTarget.dataset.previousMonth = payload.previousMonth;
			this.titleTarget.dataset.nextMonth = payload.nextMonth;
			this.gridTarget.outerHTML = payload.grid;
			this.summaryTarget.outerHTML = payload.summary;
			this.dispatch('monthLoaded', { detail: { month: payload.month }, prefix: 'calendar' });
			this.refreshDetection();
		} catch (error) {
			this.report(error);
		}
	}

	dayClicked(event) {
		const cell = event.target.closest('[data-day]');
		if (!cell) return;
		if (this.painting) return; // calendar-paint owns the grid while painting
		this.openDay(cell);
	}

	openDay(cell) {
		this.selectedDate = cell.dataset.day;
		const [year, month, day] = this.selectedDate.split('-').map(Number);
		const names = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
		this.daySheetTitleTarget.textContent = `${day} de ${names[month - 1]} de ${year}`;
		const unknown = cell.dataset.state === 'unknown';
		// The cell's own label already spells the day out for a screen reader;
		// the sheet reuses everything after the date.
		const described = (cell.getAttribute('aria-label') || '').split(', ').slice(1).join(', ');
		this.daySheetDetailTarget.textContent = cell.dataset.detail || (unknown
			? 'Aún no has indicado tu turno.'
			: described.charAt(0).toLocaleUpperCase('es') + described.slice(1));
		this.daySheetClearTarget.classList.toggle('hidden', unknown);
		openSheet(this.daySheetTarget);
	}

	setDayPreset(event) {
		this.saveDay('work', [event.currentTarget.dataset.presetId]);
	}

	setDayRest() {
		this.saveDay('rest', []);
	}

	clearDay() {
		this.saveDay('clear', []);
	}

	showManual() {
		this.manualFieldsTarget.classList.remove('hidden');
		this.manualFieldsTarget.classList.add('flex');
	}

	async saveManual() {
		if (!this.selectedDate || this.viewValue === 'all') return;
		try {
			await postJson('/app/calendar/manual', this.csrfValue, {date: this.selectedDate, label: this.manualLabelTarget.value, abbreviation: this.manualAbbreviationTarget.value, start: this.manualStartTarget.value, end: this.manualEndTarget.value, kind: this.manualKindTarget.value, colorKey: this.manualColorTarget.value});
			closeSheet(this.daySheetTarget);
			await this.load(this.monthValue);
		} catch (error) { this.report(error); }
	}

	/*
	 * A single day is the one case that does not need a preview: the worker is
	 * looking at that day, chose one thing for it, and replacing it is the
	 * obvious intent. Everything touching more than one day goes through the
	 * confirmation sheet.
	 */
	async saveDay(intent, presetIds) {
		if (this.viewValue === 'all') return;
		if (!this.selectedDate) return;
		try {
			await postJson('/app/calendar/apply', this.csrfValue, {
				entries: [{ date: this.selectedDate, intent, presetIds }],
				source: 'manual',
				policy: 'replace_existing',
			});
			closeSheet(this.daySheetTarget);
			await this.load(this.monthValue);
		} catch (error) {
			this.report(error);
		}
	}

	openAdd() {
		openSheet(this.addSheetTarget);
	}

	async seedPresets() {
		await postJson('/app/calendar/turnos/inicializar', this.csrfValue, {});
		window.location.reload();
	}

	async copyPresets(event) {
		await postJson('/app/calendar/turnos/copiar', this.csrfValue, { sourceAssignmentId: event.currentTarget.dataset.assignmentId });
		window.location.reload();
	}

	startPainting() {
		closeSheet(this.addSheetTarget);
		this.dispatch('start', { prefix: 'paint' });
	}

	openPattern() {
		closeSheet(this.addSheetTarget);
		openSheet(this.patternSheetTarget);
	}

	openVoice() {
		closeSheet(this.addSheetTarget);
		this.voiceSheetTarget.dispatchEvent(new CustomEvent('voice:mode', { detail: { microphone: true } }));
		openSheet(this.voiceSheetTarget);
	}

	openText() {
		closeSheet(this.addSheetTarget);
		this.voiceSheetTarget.dispatchEvent(new CustomEvent('voice:mode', { detail: { microphone: false } }));
		openSheet(this.voiceSheetTarget);
	}

	paintModeChanged(event) {
		this.painting = event.detail.active;
		this.addButtonTarget.classList.toggle('hidden', this.painting);
		// The dock is much taller while painting; the shell makes room for it.
		this.element.dataset.painting = String(this.painting);
	}

	changed(event) {
		if (this.hasEmptyStateTarget) this.emptyStateTarget.classList.add('hidden');
		this.load(event.detail?.month || this.monthValue);
	}

	/*
	 * Offered only for an exact repetition over at least two whole cycles, so
	 * the banner is either right or absent.
	 */
	async refreshDetection() {
		try {
			const payload = await getJson(`/app/calendar/detect-pattern?month=${encodeURIComponent(this.monthValue)}`);
			this.detected = payload.result;
			const found = Boolean(this.detected);
			this.detectionTarget.classList.toggle('hidden', !found);
			this.detectionTarget.classList.toggle('flex', found);
			if (found) {
				this.detectionTextTarget.textContent = `Parece que este patrón se repite cada ${this.detected.length} días: ${this.detected.sequence}.`;
			}
		} catch {
			this.detectionTarget.classList.add('hidden');
		}
	}

	repeatDetectedPattern() {
		if (!this.detected) return;
		this.patternSheetTarget.dispatchEvent(new CustomEvent('pattern:seed', {
			detail: { slots: this.detected.slots, from: this.detected.repeatsFrom },
		}));
		openSheet(this.patternSheetTarget);
	}

	shiftMonth(offset) {
		const [year, month] = this.monthValue.split('-').map(Number);
		const total = year * 12 + (month - 1) + offset;
		return `${String(Math.floor(total / 12)).padStart(4, '0')}-${String((total % 12) + 1).padStart(2, '0')}`;
	}

	speechAvailable() {
		return Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);
	}

	/** Paint and preview both repaint cells optimistically; the tone map lives in one place. */
	paintCell(cell, tone, code) {
		applyTone(cell, tone);
		cell.querySelector('.calendar-cell-code').textContent = code;
	}

	report(error) {
		this.dispatch('error', { detail: { message: error.message }, prefix: 'calendar' });
	}
}
