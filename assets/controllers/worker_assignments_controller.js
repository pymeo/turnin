import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
	static values = { csrf: String };
	async primary(event) { await this.post(event, 'primary'); }
	async deactivate(event) {
		if (!window.confirm('Tu histórico de turnos no se borrará. ¿Ya no trabajas aquí?')) return;
		await this.post(event, 'deactivate');
	}
	async post(event, action) {
		const id = event.currentTarget.closest('[data-assignment-id]').dataset.assignmentId;
		const response = await fetch(`/app/workplaces/${id}/${action}`, { method: 'POST', headers: { 'X-CSRF-TOKEN': this.csrfValue, Accept: 'application/json' } });
		const payload = await response.json(); if (!response.ok) throw new Error(payload.error); window.location.reload();
	}
}
