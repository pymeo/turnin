import { Controller } from '@hotwired/stimulus';
import { closeSheet, openSheet, plural, postJson, toneClass } from '../scheduling/roster_api.js';

/*
 * Preview, decide about conflicts, confirm. The single place anything is
 * written from.
 *
 * Painting, rotations and dictation all dispatch `draft:propose` with the two
 * URLs to use and a body; this controller adds the conflict policy and nothing
 * else. That is what keeps "never overwrite silently" a property of the system
 * rather than of three separate screens.
 */
export default class extends Controller {
	static targets = ['sheet', 'title', 'counts', 'list', 'conflicts', 'conflictHeadline', 'warnings', 'error', 'confirm'];
	static values = { csrf: String };

	/*
	 * A quarter of rotation is around a hundred days. Nobody reads a hundred
	 * rows on a phone, and rendering them costs more than the whole request did,
	 * so the list shows the first few plus every day that is in conflict — which
	 * is the part that actually needs a decision — and counts the rest.
	 */
	static LIST_LIMIT = 12;

	connect() {
		this.proposal = null;
		this.policy = 'skip_existing';
		this.element.addEventListener('draft:propose', (event) => this.propose(event.detail));
	}

	async propose(proposal) {
		this.proposal = proposal;
		this.policy = 'skip_existing';
		this.titleTarget.textContent = proposal.title || 'Esto es lo que haremos';
		for (const input of this.element.querySelectorAll('input[name="conflict-policy"]')) {
			input.checked = input.value === 'skip_existing';
		}
		this.clearError();
		openSheet(this.sheetTarget);
		await this.refresh();
	}

	changePolicy(event) {
		this.policy = event.currentTarget.value;
		this.refresh();
	}

	async refresh() {
		if (!this.proposal) return;
		this.confirmTarget.disabled = true;
		try {
			const preview = await postJson(this.proposal.previewUrl, this.csrfValue, { ...this.proposal.body, policy: this.policy });
			this.render(preview);
		} catch (error) {
			this.showError(error.message);
		}
	}

	render(preview) {
		this.preview = preview;

		this.countsTarget.replaceChildren(
			this.count(plural(preview.totalDays, 'día', 'días')),
			this.count(plural(preview.shiftCount, 'turno', 'turnos')),
			this.count(plural(preview.restCount, 'libre', 'libres')),
		);

		this.listTarget.replaceChildren(...this.rowsFor(preview.entries));

		const hasConflicts = preview.conflictCount > 0;
		this.conflictsTarget.classList.toggle('hidden', !hasConflicts);
		this.conflictsTarget.classList.toggle('flex', hasConflicts);
		if (hasConflicts) {
			this.conflictHeadlineTarget.textContent = `${plural(preview.conflictCount, 'día ya tiene', 'días ya tienen')} información. ¿Qué hacemos?`;
		}

		this.warningsTarget.classList.toggle('hidden', preview.unrecognized.length === 0);
		this.warningsTarget.replaceChildren(...preview.unrecognized.map((warning) => {
			const note = document.createElement('p');
			note.className = 'alert-info';
			note.textContent = warning;
			return note;
		}));

		this.confirmTarget.disabled = preview.applyCount === 0;
		this.confirmTarget.textContent = preview.applyCount === 0
			? 'No hay nada que cambiar'
			: `${this.proposal.confirmLabel || 'Guardar'} ${plural(preview.applyCount, 'día', 'días')}`;
	}

	rowsFor(entries) {
		const limit = this.constructor.LIST_LIMIT;
		const shown = new Set(entries.slice(0, limit));
		for (const entry of entries) {
			if (entry.conflicting) shown.add(entry);
		}

		const rows = entries.filter((entry) => shown.has(entry)).map((entry) => this.row(entry));
		const omitted = entries.length - rows.length;
		if (omitted > 0) rows.push(this.moreRow(omitted));

		return rows;
	}

	moreRow(omitted) {
		const more = document.createElement('p');
		more.className = 'preview-row text-ink-muted';
		more.textContent = `… y ${plural(omitted, 'día más', 'días más')}`;
		return more;
	}

	count(text) {
		const element = document.createElement('span');
		element.innerHTML = '<strong></strong>';
		element.querySelector('strong').textContent = text;
		return element;
	}

	row(entry) {
		const row = document.createElement('div');
		row.className = 'preview-row';

		const date = document.createElement('span');
		date.className = 'preview-row-date';
		date.textContent = String(entry.dayNumber);

		const code = document.createElement('span');
		code.className = `preview-code ${toneClass(entry.tone)}`;
		code.textContent = entry.abbreviation;

		const description = document.createElement('span');
		description.className = 'min-w-0 flex-1 truncate';
		description.textContent = entry.description;

		row.append(date, code, description);

		if (entry.conflicting) {
			const conflict = document.createElement('span');
			conflict.className = 'shrink-0 text-xs text-ink-muted';
			conflict.textContent = `⚠ ya tiene ${entry.existing}`;
			row.append(conflict);
		}

		return row;
	}

	async confirm() {
		if (!this.proposal) return;
		this.confirmTarget.disabled = true;
		this.clearError();
		try {
			const result = await postJson(this.proposal.applyUrl, this.csrfValue, { ...this.proposal.body, policy: this.policy });
			closeSheet(this.sheetTarget);
			this.proposal.onDone?.();
			this.dispatch('changed', { prefix: 'calendar', detail: { month: result?.month } });
			this.proposal = null;
		} catch (error) {
			this.showError(error.message);
		}
	}

	showError(message) {
		this.errorTarget.textContent = message;
		this.errorTarget.classList.remove('hidden');
		this.confirmTarget.disabled = false;
	}

	clearError() {
		this.errorTarget.textContent = '';
		this.errorTarget.classList.add('hidden');
	}
}
