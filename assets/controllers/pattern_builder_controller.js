import { Controller } from '@hotwired/stimulus';
import { closeSheet, postJson, toneClass } from '../scheduling/roster_api.js';

/*
 * Build a rotation by tapping it, then say how far to repeat it.
 *
 * The rotation is stored as slots, not as the string "M,M,T,T,N,N,L,L,L": the
 * structure is what lets us count nights, survive a preset being renamed and
 * expand the thing without re-parsing it.
 *
 * The name is generated ("Patrón de 9 días"). Asking somebody to name a rota
 * before they have tried it is a step that buys nothing.
 */
export default class extends Controller {
	static targets = ['buildStep', 'rangeStep', 'sequence', 'length', 'continue', 'from', 'to', 'customRange', 'rangeSummary', 'error', 'previewButton'];
	static values = { csrf: String };

	connect() {
		this.slots = [];
		this.patternId = null;
		this.span = '3';
		this.element.addEventListener('pattern:seed', (event) => this.seed(event.detail));
		this.render();
	}

	push(event) {
		const button = event.currentTarget;
		this.slots.push({ value: button.dataset.slotValue, tone: button.dataset.slotTone, code: button.dataset.slotCode });
		this.render();
	}

	pop() {
		this.slots.pop();
		this.render();
	}

	reset() {
		this.slots = [];
		this.patternId = null;
		this.render();
	}

	seed(detail) {
		this.slots = detail.slots.map((slot) => ({
			value: slot.type === 'rest' ? 'rest' : slot.presetId,
			tone: slot.type === 'rest' ? 'rest' : 'oncall',
			code: slot.abbreviation,
		}));
		this.patternId = null;
		this.render();
		if (detail.from) this.fromTarget.value = detail.from;
	}

	render() {
		this.sequenceTarget.replaceChildren(...this.slots.map((slot) => {
			const chip = document.createElement('span');
			chip.className = `pattern-slot ${toneClass(slot.tone)}`;
			chip.textContent = slot.code;
			return chip;
		}));
		this.lengthTarget.textContent = this.slots.length ? `${this.slots.length} días` : '';
		this.continueTarget.disabled = this.slots.length === 0;
	}

	async toRange() {
		this.clearError();
		try {
			if (!this.patternId) {
				const created = await postJson('/app/calendar/patrones', this.csrfValue, {
					slots: this.slots.map((slot) => (slot.value === 'rest' ? null : slot.value)),
				});
				this.patternId = created.patternId;
			}
			this.showRangeStep(this.slots.length);
		} catch (error) {
			this.showError(error.message);
		}
	}

	useExisting(event) {
		this.patternId = event.currentTarget.dataset.patternId;
		this.showRangeStep(Number(event.currentTarget.dataset.patternLength));
	}

	showRangeStep(length) {
		this.rangeSummaryTarget.textContent = `Repetiremos tu rotación de ${length} días.`;
		this.buildStepTarget.classList.add('hidden');
		this.rangeStepTarget.classList.remove('hidden');
		this.rangeStepTarget.classList.add('flex');
		if (!this.fromTarget.value) this.fromTarget.value = new Date().toISOString().slice(0, 10);
	}

	backToBuild() {
		this.rangeStepTarget.classList.add('hidden');
		this.rangeStepTarget.classList.remove('flex');
		this.buildStepTarget.classList.remove('hidden');
	}

	chooseSpan(event) {
		this.span = event.currentTarget.dataset.span;
		for (const option of this.element.querySelectorAll('[data-span]')) {
			option.setAttribute('aria-pressed', String(option === event.currentTarget));
		}
		this.customRangeTarget.classList.toggle('hidden', this.span !== 'custom');
	}

	preview() {
		this.clearError();
		const from = this.fromTarget.value;
		if (!from) {
			this.showError('Elige la fecha en la que empieza.');
			return;
		}
		const to = this.endDate(from);
		if (!to) {
			this.showError('Elige hasta cuándo lo repetimos.');
			return;
		}

		// One modal at a time: the confirmation sheet replaces this one.
		closeSheet(this.element);
		this.dispatch('propose', {
			prefix: 'draft',
			detail: {
				title: 'Esto es lo que aplicaría tu patrón',
				confirmLabel: 'Aplicar',
				previewUrl: `/app/calendar/patrones/${this.patternId}/previsualizar`,
				applyUrl: `/app/calendar/patrones/${this.patternId}/aplicar`,
				body: { from, to },
			},
		});
	}

	endDate(from) {
		if (this.span === 'custom') return this.toTarget.value || null;
		const start = new Date(`${from}T00:00:00Z`);
		if (this.span === 'month') {
			return new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + 1, 0)).toISOString().slice(0, 10);
		}
		const months = Number(this.span);
		const end = new Date(Date.UTC(start.getUTCFullYear(), start.getUTCMonth() + months, start.getUTCDate()));
		end.setUTCDate(end.getUTCDate() - 1);
		return end.toISOString().slice(0, 10);
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
