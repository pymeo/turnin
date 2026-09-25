import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
	static targets = ['button', 'feedback'];
	static values = { url: String, text: String };

	connect() {
		if (!navigator.share) this.buttonTarget.textContent = 'Copiar enlace';
	}

	async share() {
		try {
			if (navigator.share) {
				await navigator.share({ title: 'Cambio de turno · Turnin', text: this.textValue, url: this.urlValue });
				return;
			}
			await navigator.clipboard.writeText(this.urlValue);
			this.feedbackTarget.textContent = 'Enlace copiado';
		} catch (error) {
			if (error?.name !== 'AbortError') this.feedbackTarget.textContent = 'No se pudo compartir. Mantén pulsado el enlace para copiarlo.';
		}
	}
}
