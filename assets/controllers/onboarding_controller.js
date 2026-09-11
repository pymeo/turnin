import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
	static targets = ['step', 'counter', 'progress', 'progressLabel', 'error', 'primaryContext', 'additionalButton', 'summaryName', 'summaryCategory', 'summaryDestination', 'summaryWorkplace', 'summaryAdditional', 'summaryAdditionalNames'];
	static values = { csrf: String, completeUrl: String };

	connect() {
		this.index = this.firstIncompleteStep();
		this.show();
		this.element.addEventListener('picker:selected', (event) => this.selectionChanged(event));
		this.syncAdditionalStep();
	}

	async next(event) {
		const button = event.currentTarget;
		this.busy(button, true);
		this.clearError();
		try {
			const step = this.stepTargets[this.index].dataset.step;
			if (step === 'name') await this.saveName();
			if (step === 'identity') await this.saveIdentity();
			if (['workplace', 'category', 'destination', 'additional'].includes(step)) await this.saveProgress(step);
			this.index = Math.min(this.stepTargets.length - 1, this.index + 1);
			if (this.stepTargets[this.index].dataset.step === 'summary') this.updateSummary();
			this.show();
		} catch (error) {
			this.showError(error.message || 'No se pudo guardar. Inténtalo de nuevo.');
		} finally {
			this.busy(button, false);
		}
	}

	back() {
		this.clearError();
		this.index = Math.max(0, this.index - 1);
		this.show();
	}

	async finish(event) {
		const button = event.currentTarget;
		this.busy(button, true);
		this.clearError();
		try {
			const payload = await this.post(this.completeUrlValue || '/onboarding/complete', new URLSearchParams());
			window.location.assign(payload.result.redirect);
		} catch (error) {
			this.showError(error.message || 'No se pudo completar tu perfil.');
			this.busy(button, false);
		}
	}

	selectionChanged(event) {
		if (event.detail.name === 'workplace_id') {
			for (const name of ['primary_destination_id', 'additional_destination_ids']) {
				const input = this.element.querySelector(`[name="${name}"]`);
				input?.closest('[data-controller~="searchable-picker"]')?.dispatchEvent(new CustomEvent('picker:clear'));
				input?.closest('[data-controller~="searchable-picker"]')?.dispatchEvent(new CustomEvent('picker:refresh'));
			}
		}
		if (event.detail.name === 'primary_destination_id') {
			this.input('additional_destination_ids').closest('[data-controller~="searchable-picker"]')?.dispatchEvent(new CustomEvent('picker:exclude'));
		}
		this.syncAdditionalStep();
	}

	async saveName() {
		const given = this.input('given_name').value.trim();
		const family = this.input('family_name').value.trim();
		if (!given || !family) throw new Error('Indica tu nombre y apellidos.');
		await this.post('/onboarding/name', new URLSearchParams({ given_name: given, family_name: family }));
	}

	async saveIdentity() {
		const section = this.stepTargets[this.index];
		if (section.dataset.complete === 'true') return;
		const document = this.input('identity_document').value.trim();
		const phone = this.input('phone').value.trim();
		if (!document || !phone) throw new Error('Indica tu DNI/NIE y teléfono.');
		await this.post('/onboarding/identity', new URLSearchParams({ identity_document: document, phone }));
		section.dataset.complete = 'true';
	}

	async saveProgress(step) {
		const workplace = this.input('workplace_id').value;
		const category = this.input('staff_category_id').value;
		const primary = this.input('primary_destination_id').value;
		if (step === 'workplace' && !workplace) throw new Error('Selecciona tu centro de los resultados.');
		if (step === 'category' && !category) throw new Error('Selecciona tu categoría de los resultados.');
		if (step === 'destination' && !primary) throw new Error('Selecciona tu destino principal.');
		const body = new URLSearchParams({ workplace_id: workplace, staff_category_id: category, primary_destination_id: primary });
		for (const id of this.input('additional_destination_ids').value.split(',').filter(Boolean)) body.append('additional_destination_ids[]', id);
		const payload = await this.post('/onboarding/progress', body);
		if (payload.result.primaryDestinationId) this.input('primary_destination_id').value = payload.result.primaryDestinationId;
		this.input('additional_destination_ids').value = payload.result.additionalDestinationIds.join(',');
	}

	async post(url, body) {
		const response = await fetch(url, { method: 'POST', body, headers: { 'X-CSRF-TOKEN': this.csrfValue, Accept: 'application/json' } });
		const payload = await response.json();
		if (!response.ok) throw new Error(payload.error || 'No se pudo guardar.');
		return payload;
	}

	show() {
		this.stepTargets.forEach((step, index) => {
			step.classList.toggle('hidden', index !== this.index);
			step.classList.toggle('step-enter', index === this.index);
		});
		this.counterTarget.textContent = `${this.index + 1} de ${this.stepTargets.length}`;
		this.progressLabelTarget.textContent = ['Sobre ti', 'Cuenta protegida', 'Centro', 'Categoría', 'Destino principal', 'Compatibilidad', 'Resumen'][this.index];
		this.progressTarget.style.width = `${((this.index + 1) / this.stepTargets.length) * 100}%`;
		this.syncAdditionalStep();
		window.scrollTo({ top: 0, behavior: 'smooth' });
		this.stepTargets[this.index].querySelector('input:not([type="hidden"])')?.focus({ preventScroll: true });
	}

	firstIncompleteStep() {
		if (!this.input('given_name').value || !this.input('family_name').value) return 0;
		if (this.stepTargets.find((step) => step.dataset.step === 'identity').dataset.complete !== 'true') return 1;
		if (!this.input('workplace_id').value) return 2;
		if (!this.input('staff_category_id').value) return 3;
		if (!this.input('primary_destination_id').value) return 4;
		return 5;
	}

	updateSummary() {
		this.summaryNameTarget.textContent = `${this.input('given_name').value.trim()} ${this.input('family_name').value.trim()}`;
		this.summaryWorkplaceTarget.textContent = this.label('workplace_id');
		this.summaryCategoryTarget.textContent = this.label('staff_category_id');
		this.summaryDestinationTarget.textContent = this.label('primary_destination_id');
		const additional = this.label('additional_destination_ids');
		this.summaryAdditionalTarget.classList.toggle('hidden', !additional);
		this.summaryAdditionalNamesTarget.textContent = additional;
	}

	label(name) {
		return this.input(name).closest('[data-controller~="searchable-picker"]')?.dataset.selectedLabels || '';
	}

	input(name) {
		return this.element.querySelector(`[name="${name}"]`);
	}

	busy(button, active) {
		button.disabled = active;
		button.setAttribute('aria-busy', active ? 'true' : 'false');
	}

	showError(message) {
		this.errorTarget.textContent = message;
		this.errorTarget.classList.remove('hidden');
	}

	clearError() {
		this.errorTarget.classList.add('hidden');
		this.errorTarget.textContent = '';
	}

	syncAdditionalStep() {
		const primary = this.label('primary_destination_id');
		this.primaryContextTarget.textContent = primary || 'Sin seleccionar';
		const hasAdditional = Boolean(this.input('additional_destination_ids').value);
		this.additionalButtonTarget.textContent = hasAdditional ? 'Continuar' : 'Omitir por ahora';
	}
}
