import { Controller } from '@hotwired/stimulus';
import { applyTone, configureCalendarContext, openSheet, postJson } from '../scheduling/roster_api.js';

/*
 * "Gestionar mis turnos".
 *
 * Retiring a preset never deletes it: shifts recorded months ago still name it,
 * so the button says "Retirar" and the row stays visible, dimmed.
 */
export default class extends Controller {
	static targets = ['list', 'sheet', 'sheetTitle', 'name', 'abbreviation', 'start', 'end', 'kind', 'color', 'colors', 'colorName', 'aliases', 'error', 'deactivate', 'mode', 'partOptions', 'base', 'slice', 'previewCell', 'previewCode', 'previewName', 'previewHours'];
	static values = { csrf: String, assignment: String };

	connect() {
		configureCalendarContext(this.hasAssignmentValue ? this.assignmentValue : null);
		this.editing = null;
		// True once the worker picks a colour by hand: after that, changing the
		// kind must not quietly repaint their choice.
		this.colorChosenByHand = false;
		this.sheetTarget.addEventListener('input', () => this.renderPreview());
		this.kindTarget.addEventListener('change', () => this.suggestColorForKind());
	}

	/* --- colour ---------------------------------------------------------- */

	chooseColor(event) {
		this.colorChosenByHand = true;
		this.applyColor(event.currentTarget.dataset.colorKey);
	}

	/** Arrow keys move through the palette, as a radio group should. */
	moveColor(event) {
		const swatches = [...this.colorsTarget.querySelectorAll('[data-color-key]')];
		const current = swatches.indexOf(event.currentTarget);
		const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[event.key];
		if (undefined === step) return;
		event.preventDefault();
		const next = swatches[(current + step + swatches.length) % swatches.length];
		this.colorChosenByHand = true;
		this.applyColor(next.dataset.colorKey);
		next.focus();
	}

	applyColor(key) {
		this.colorTarget.value = key;
		for (const swatch of this.colorsTarget.querySelectorAll('[data-color-key]')) {
			const chosen = swatch.dataset.colorKey === key;
			swatch.setAttribute('aria-checked', String(chosen));
			// Only the selected swatch stays in the tab order, so a keyboard
			// reaches the group once and then moves within it.
			swatch.tabIndex = chosen ? 0 : -1;
			if (chosen) this.colorNameTarget.textContent = swatch.getAttribute('aria-label');
		}
		this.renderPreview();
	}

	suggestColorForKind() {
		if (this.colorChosenByHand) return;
		const suggestion = {
			morning: 'amber', evening: 'orange', night: 'blue', long_night: 'blue',
			long_day: 'emerald', on_call: 'violet', other: 'slate',
		}[this.kindTarget.value];
		if (suggestion) this.applyColor(suggestion);
	}

	/** What this preset will look like in a calendar cell, as it is filled in. */
	renderPreview() {
		applyTone(this.previewCellTarget, this.colorTarget.value);
		this.previewCodeTarget.textContent = this.abbreviationTarget.value.trim() || '·';
		this.previewNameTarget.textContent = this.nameTarget.value.trim() || 'Sin nombre';
		this.previewHoursTarget.textContent = `${this.startTarget.value} – ${this.endTarget.value}`;
	}

	create() {
		this.editing = null;
		this.sheetTitleTarget.textContent = 'Nuevo turno';
		this.nameTarget.value = '';
		this.abbreviationTarget.value = '';
		this.startTarget.value = '08:00';
		this.endTarget.value = '15:00';
		this.kindTarget.value = 'morning';
		this.colorChosenByHand = false;
		this.applyColor('amber');
		this.aliasesTarget.value = '';
		this.deactivateTarget.classList.add('hidden');
		this.modeTarget.value = 'custom';
		this.partOptionsTarget.classList.add('hidden');
		this.clearError();
		openSheet(this.sheetTarget);
	}

	edit(event) {
		const row = event.currentTarget.closest('[data-preset-id]');
		this.editing = row.dataset.presetId;
		this.sheetTitleTarget.textContent = 'Editar turno';
		this.nameTarget.value = row.dataset.name;
		this.abbreviationTarget.value = row.dataset.abbreviation;
		this.startTarget.value = row.dataset.start;
		this.endTarget.value = row.dataset.end;
		this.aliasesTarget.value = row.dataset.aliases;
		this.modeTarget.value = 'custom';
		this.partOptionsTarget.classList.add('hidden');
		this.deactivateTarget.classList.remove('hidden');
		this.kindTarget.value = row.dataset.kind || 'other';
		// An existing preset already has a colour somebody chose; keep it.
		this.colorChosenByHand = true;
		this.applyColor(row.dataset.color || 'slate');
		this.clearError();
		openSheet(this.sheetTarget);
	}

	duplicate(event) {
		this.edit(event);
		this.editing = null;
		this.sheetTitleTarget.textContent = 'Duplicar turno';
		this.nameTarget.value = `${this.nameTarget.value} copia`;
		this.deactivateTarget.classList.add('hidden');
	}

	derive() {
		const mode = this.modeTarget.value;
		this.partOptionsTarget.classList.toggle('hidden', mode !== 'part');
		if (mode === '12h') this.endTarget.value = this.time(this.minutes(this.startTarget.value) + 720);
		if (mode === 'part' && this.baseTarget.value) {
			const [start, end] = this.baseTarget.value.split('|').map((value) => this.minutes(value));
			const duration = (end - start + 1440) % 1440 || 1440;
			const [index, count] = this.sliceTarget.value.split('/').map(Number);
			const from = start + Math.ceil(duration * index / count);
			const to = start + Math.ceil(duration * (index + 1) / count);
			this.startTarget.value = this.time(from); this.endTarget.value = this.time(to);
		}
		this.renderPreview();
	}

	minutes(value) { const [hour, minute] = value.split(':').map(Number); return hour * 60 + minute; }
	time(minutes) { const value = ((minutes % 1440) + 1440) % 1440; return `${String(Math.floor(value / 60)).padStart(2, '0')}:${String(value % 60).padStart(2, '0')}`; }

	async submit(event) {
		event.preventDefault();
		this.clearError();
		try {
			await postJson('/app/calendar/turnos/guardar', this.csrfValue, {
				presetId: this.editing,
				name: this.nameTarget.value,
				abbreviation: this.abbreviationTarget.value,
				start: this.startTarget.value,
				end: this.endTarget.value,
				kind: this.kindTarget.value,
				colorKey: this.colorTarget.value,
				aliases: this.aliasesTarget.value,
			});
			window.location.reload();
		} catch (error) {
			this.showError(error.message);
		}
	}

	async deactivate() {
		if (!this.editing) return;
		try {
			await postJson(`/app/calendar/turnos/${this.editing}/retirar`, this.csrfValue, {});
			window.location.reload();
		} catch (error) {
			this.showError(error.message);
		}
	}

	async moveUp(event) {
		const row = event.currentTarget.closest('[data-preset-id]');
		const rows = [...this.listTarget.querySelectorAll('[data-preset-id]')];
		const index = rows.indexOf(row);
		if (index <= 0) return;
		rows.splice(index - 1, 0, rows.splice(index, 1)[0]);
		try {
			await postJson('/app/calendar/turnos/orden', this.csrfValue, { presetIds: rows.map((item) => item.dataset.presetId) });
			this.listTarget.replaceChildren(...rows);
		} catch (error) {
			this.showError(error.message);
		}
	}

	showError(message) {
		this.errorTarget.textContent = message;
		this.errorTarget.classList.remove('hidden');
	}

	clearError() {
		this.errorTarget.textContent = '';
		this.errorTarget.classList.add('hidden');
	}
}
