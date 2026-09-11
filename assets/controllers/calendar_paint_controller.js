import { Controller } from '@hotwired/stimulus';
import { applyTone, plural } from '../scheduling/roster_api.js';

/*
 * Paint mode: pick a shift, then tap or drag across the days.
 *
 * Nothing is saved while painting. Every tap goes into a local draft and the
 * cell is repainted optimistically, so the month responds instantly and a
 * hundred taps are still one write. Undo is a stack of what changed, which is
 * all "↶ Deshacer" needs — no event sourcing involved.
 */
export default class extends Controller {
	static targets = ['panel', 'swatch', 'counter', 'undo', 'save'];

	connect() {
		this.active = false;
		this.choice = null;
		this.draft = new Map();
		this.history = [];
		this.dragging = false;
		this.stroke = new Set();
		this.element.addEventListener('paint:start', () => this.start());
		this.element.addEventListener('calendar:monthLoaded', () => this.repaint());

		this.element.addEventListener('pointerdown', (event) => this.pointerDown(event));
		this.element.addEventListener('pointerover', (event) => this.pointerOver(event));
		// Ending the drag anywhere — including off the grid — must end it.
		window.addEventListener('pointerup', () => { this.dragging = false; });
		window.addEventListener('pointercancel', () => { this.dragging = false; });

		// Registered only while something is unsaved. A beforeunload listener
		// that is always attached makes every ordinary navigation slower and
		// leaves the browser deciding whether to prompt.
		this.guarding = false;
		this.beforeUnload = (event) => {
			event.preventDefault();
			event.returnValue = '';
		};
	}

	disconnect() {
		this.guardUnsavedDraft(false);
	}

	guardUnsavedDraft(active) {
		if (active === this.guarding) return;
		if (active) window.addEventListener('beforeunload', this.beforeUnload);
		else window.removeEventListener('beforeunload', this.beforeUnload);
		this.guarding = active;
	}

	start() {
		this.active = true;
		this.panelTarget.classList.remove('hidden');
		this.panelTarget.classList.add('flex');
		this.choose({ currentTarget: this.swatchTargets[0] });
		this.announce();
		this.dispatch('mode', { detail: { active: true }, prefix: 'paint' });
	}

	cancel() {
		const hadDraft = this.draft.size > 0;
		this.active = false;
		this.panelTarget.classList.add('hidden');
		this.panelTarget.classList.remove('flex');
		this.draft.clear();
		this.history = [];
		this.clearPressedState();
		this.announce();
		this.dispatch('mode', { detail: { active: false }, prefix: 'paint' });
		// The optimistic paint is thrown away by re-rendering the month from the
		// server, which is the only copy that is actually true.
		if (hadDraft) this.dispatch('changed', { prefix: 'calendar' });
	}

	choose(event) {
		const swatch = event.currentTarget;
		if (!swatch) return;
		this.choice = { value: swatch.dataset.paintValue, tone: swatch.dataset.paintTone, code: swatch.dataset.paintCode };
		this.swatchTargets.forEach((candidate) => candidate.setAttribute('aria-pressed', String(candidate === swatch)));
	}

	pointerDown(event) {
		if (!this.active) return;
		const cell = event.target.closest('[data-day]');
		if (!cell) return;
		// Stops the browser turning a drag across cells into a text selection.
		event.preventDefault();
		this.dragging = true;
		this.paint(cell, true);
	}

	pointerOver(event) {
		if (!this.active || !this.dragging) return;
		const cell = event.target.closest('[data-day]');
		if (cell) this.paint(cell, false);
	}

	/** @param startsStroke a drag over an already-painted cell must not stack undo steps */
	paint(cell, startsStroke) {
		if (!this.choice) return;
		const date = cell.dataset.day;
		if (!startsStroke && this.stroke?.has(date)) return;
		if (startsStroke) this.stroke = new Set();
		this.stroke.add(date);

		const previous = this.draft.get(date) ?? null;
		const previousLook = { tone: this.toneOf(cell), code: cell.querySelector('.calendar-cell-code').textContent };
		this.history.push({ date, previous, previousLook });

		const intent = this.choice.value === 'rest' ? 'rest' : this.choice.value === 'clear' ? 'clear' : 'work';
		this.draft.set(date, {
			date,
			intent,
			presetIds: intent === 'work' ? [this.choice.value] : [],
			tone: this.choice.tone,
			code: this.choice.code,
		});
		this.show(cell, this.choice.tone, this.choice.code);
		this.announce();
	}

	undo() {
		const step = this.history.pop();
		if (!step) return;
		if (step.previous) this.draft.set(step.date, step.previous);
		else this.draft.delete(step.date);
		const cell = this.element.querySelector(`[data-day="${step.date}"]`);
		if (cell) this.show(cell, step.previousLook.tone, step.previousLook.code);
		this.announce();
	}

	save() {
		if (!this.draft.size) return;
		this.dispatch('propose', {
			prefix: 'draft',
			detail: {
				title: 'Guardar los días pintados',
				confirmLabel: 'Guardar',
				previewUrl: '/app/calendar/preview',
				applyUrl: '/app/calendar/apply',
				body: { entries: [...this.draft.values()].map(({ date, intent, presetIds }) => ({ date, intent, presetIds })), source: 'manual' },
				onDone: () => this.cancel(),
			},
		});
	}

	/** After a month reload the server's markup is the truth again. */
	repaint() {
		this.draft.clear();
		this.history = [];
		this.announce();
	}

	/**
	 * Drops the "painted, not saved" highlight straight away. The month reload
	 * would do it a moment later, and that moment is long enough to look like
	 * the save did not take.
	 */
	clearPressedState() {
		for (const cell of this.element.querySelectorAll('[data-day][aria-pressed="true"]')) {
			cell.setAttribute('aria-pressed', 'false');
		}
	}

	show(cell, tone, code) {
		applyTone(cell, tone);
		cell.querySelector('.calendar-cell-code').textContent = code === '·' ? '·' : code;
		cell.setAttribute('aria-pressed', 'true');
	}

	toneOf(cell) {
		const found = [...cell.classList].find((name) => name.startsWith('calendar-tone-'));
		return found ? found.replace('calendar-tone-', '') : 'unknown';
	}

	announce() {
		const count = this.draft.size;
		this.guardUnsavedDraft(count > 0);
		this.counterTarget.textContent = count ? `${plural(count, 'día modificado', 'días modificados')}` : 'Toca los días para pintarlos';
		this.saveTarget.disabled = count === 0;
		this.saveTarget.textContent = count ? `Guardar ${plural(count, 'día', 'días')}` : 'Guardar';
		this.undoTarget.disabled = this.history.length === 0;
	}
}
