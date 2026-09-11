import { Controller } from '@hotwired/stimulus';
import { configureCalendarContext, openSheet, postJson } from '../scheduling/roster_api.js';

/*
 * "Gestionar mis turnos".
 *
 * Retiring a preset never deletes it: shifts recorded months ago still name it,
 * so the button says "Retirar" and the row stays visible, dimmed.
 */
export default class extends Controller {
	static targets = ['list', 'sheet', 'sheetTitle', 'name', 'abbreviation', 'start', 'end', 'kind', 'color', 'aliases', 'error', 'deactivate', 'mode', 'partOptions', 'base', 'slice'];
	static values = { csrf: String, assignment: String };

	connect() {
		configureCalendarContext(this.hasAssignmentValue ? this.assignmentValue : null);
		this.editing = null;
	}

	create() {
		this.editing = null;
		this.sheetTitleTarget.textContent = 'Nuevo turno';
		this.nameTarget.value = '';
		this.abbreviationTarget.value = '';
		this.startTarget.value = '08:00';
		this.endTarget.value = '15:00';
		this.kindTarget.value = 'morning';
		this.colorTarget.value = 'amber';
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
		this.colorTarget.value = row.dataset.color || 'slate';
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
