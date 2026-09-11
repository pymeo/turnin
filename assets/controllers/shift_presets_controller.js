import { Controller } from '@hotwired/stimulus';
import { openSheet, postJson } from '../scheduling/roster_api.js';

/*
 * "Gestionar mis turnos".
 *
 * Retiring a preset never deletes it: shifts recorded months ago still name it,
 * so the button says "Retirar" and the row stays visible, dimmed.
 */
export default class extends Controller {
	static targets = ['list', 'sheet', 'sheetTitle', 'name', 'abbreviation', 'start', 'end', 'kind', 'aliases', 'error', 'deactivate'];
	static values = { csrf: String };

	connect() {
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
		this.aliasesTarget.value = '';
		this.deactivateTarget.classList.add('hidden');
		this.clearError();
		openSheet(this.sheetTarget);
	}

	edit(event) {
		const row = event.currentTarget.closest('[data-preset-id]');
		this.editing = row.dataset.presetId;
		const hours = row.querySelector('.text-ink-muted').textContent.trim().split('·')[0].trim();
		const [start, end] = hours.replace(/\(.*\)/, '').split('–').map((part) => part.trim());
		this.sheetTitleTarget.textContent = 'Editar turno';
		this.nameTarget.value = row.querySelector('.truncate').textContent.trim();
		this.abbreviationTarget.value = row.querySelector('.preset-mark').textContent.trim();
		this.startTarget.value = start;
		this.endTarget.value = end;
		this.deactivateTarget.classList.remove('hidden');
		this.clearError();
		openSheet(this.sheetTarget);
	}

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
