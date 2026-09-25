import { Controller } from '@hotwired/stimulus';

/*
 * Sends a supervisor invitation or verification link. The server writes the
 * message; this only chooses the channel. WhatsApp is a plain share link with
 * the text prefilled: no contacts, no phone numbers, the person picks who.
 */
export default class extends Controller {
	static targets = ['feedback'];
	static values = { url: String, message: String, title: String };

	async share() {
		if (!navigator.share) {
			await this.copy();
			return;
		}
		try {
			await navigator.share({ title: this.titleValue, text: this.messageValue });
		} catch (error) {
			if (error?.name !== 'AbortError') await this.copy();
		}
	}

	whatsapp() {
		window.open(`https://wa.me/?text=${encodeURIComponent(this.messageValue)}`, '_blank', 'noopener');
	}

	async copy() {
		try {
			await navigator.clipboard.writeText(this.urlValue);
			this.say('Enlace copiado');
		} catch {
			this.say('No se pudo copiar. Mantén pulsado el enlace para copiarlo.');
		}
	}

	say(text) {
		if (this.hasFeedbackTarget) this.feedbackTarget.textContent = text;
	}
}
