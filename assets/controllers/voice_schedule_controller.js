import { Controller } from '@hotwired/stimulus';
import { closeSheet, postJson } from '../scheduling/roster_api.js';

/*
 * Dictating a rota.
 *
 * The microphone is progressive enhancement; the textarea is the feature. Most
 * phones dictate straight into a text field through the keyboard, so the text
 * path works everywhere and the SpeechRecognition path is a shortcut where the
 * browser offers one.
 *
 * Audio never leaves the device through us: the browser produces a transcript
 * and only that text is posted. We store no recording.
 *
 * Nothing is ever written from here. Interpreting produces a preview, and the
 * preview has to be confirmed.
 */
export default class extends Controller {
	static targets = ['title', 'microphone', 'micButton', 'status', 'text', 'error', 'interpretButton', 'unresolved', 'unresolvedText', 'patternOffer', 'patternSequence'];
	static values = { csrf: String };

	connect() {
		this.recognition = null;
		this.listening = false;
		this.usedMicrophone = false;
		this.patternSlots = [];
		this.element.addEventListener('voice:mode', (event) => this.setMode(event.detail.microphone));
		this.element.addEventListener('close', () => this.stopListening());
	}

	setMode(microphone) {
		const available = microphone && this.speechSupported();
		this.titleTarget.textContent = available ? 'Dicta tu cuadrante' : 'Escribe tu cuadrante';
		this.microphoneTarget.classList.toggle('hidden', !available);
		this.microphoneTarget.classList.toggle('flex', available);
		this.reset();
	}

	speechSupported() {
		return Boolean(window.SpeechRecognition || window.webkitSpeechRecognition);
	}

	toggleListening() {
		if (this.listening) {
			this.stopListening();
			return;
		}
		const Recognition = window.SpeechRecognition || window.webkitSpeechRecognition;
		if (!Recognition) return;

		this.recognition = new Recognition();
		this.recognition.lang = 'es-ES';
		this.recognition.continuous = true;
		this.recognition.interimResults = true;
		this.recognition.onresult = (event) => {
			let transcript = '';
			for (const result of event.results) transcript += result[0].transcript;
			this.textTarget.value = transcript.trim();
			this.textChanged();
		};
		this.recognition.onerror = () => {
			this.statusTarget.textContent = 'No hemos podido escucharte. Puedes escribirlo abajo.';
			this.stopListening();
		};
		this.recognition.onend = () => this.stopListening();

		try {
			this.recognition.start();
			this.listening = true;
			this.usedMicrophone = true;
			this.micButtonTarget.dataset.listening = 'true';
			this.statusTarget.textContent = '● Escuchando…';
		} catch {
			this.statusTarget.textContent = 'No hemos podido activar el micrófono.';
		}
	}

	stopListening() {
		if (this.recognition) {
			this.recognition.onend = null;
			try { this.recognition.stop(); } catch { /* already stopped */ }
			this.recognition = null;
		}
		this.listening = false;
		if (this.hasMicButtonTarget) this.micButtonTarget.dataset.listening = 'false';
		if (this.hasStatusTarget && this.statusTarget.textContent === '● Escuchando…') {
			this.statusTarget.textContent = 'Pulsa y habla';
		}
	}

	textChanged() {
		this.interpretButtonTarget.disabled = this.textTarget.value.trim().length === 0;
	}

	async interpret() {
		this.clearError();
		this.interpretButtonTarget.disabled = true;
		try {
			const month = this.element.closest('[data-calendar-month-value]')?.dataset.calendarMonthValue;
			const result = await postJson('/app/calendar/interpret', this.csrfValue, {
				text: this.textTarget.value,
				month,
				source: this.usedMicrophone ? 'voice' : 'text',
			});

			this.patternSlots = result.patternSlots || [];
			const hasPattern = this.patternSlots.length > 0;
			this.patternOfferTarget.classList.toggle('hidden', !hasPattern);
			this.patternOfferTarget.classList.toggle('flex', hasPattern);
			if (hasPattern) this.patternSequenceTarget.textContent = result.patternSequence;

			const unresolved = result.preview.unrecognized || [];
			this.unresolvedTarget.classList.toggle('hidden', unresolved.length === 0);
			this.unresolvedTarget.classList.toggle('flex', unresolved.length > 0);
			if (unresolved.length) this.unresolvedTextTarget.textContent = `«${unresolved.join('», «')}»`;

			if (result.understoodNothing && !hasPattern) {
				this.showError('No hemos entendido el cuadrante. Prueba con «1 y 2 mañana, 3 tarde».');
				return;
			}
			if (hasPattern) return;

			this.propose(result.preview);
		} catch (error) {
			this.showError(error.message);
		} finally {
			this.textChanged();
		}
	}

	/** Hands the understood days to the one confirmation screen. */
	propose(preview) {
		this.stopListening();
		closeSheet(this.element);
		this.dispatch('propose', {
			prefix: 'draft',
			detail: {
				title: 'He entendido esto',
				confirmLabel: 'Añadir',
				previewUrl: '/app/calendar/preview',
				applyUrl: '/app/calendar/apply',
				body: {
					source: this.usedMicrophone ? 'voice' : 'text',
					entries: preview.entries.map((entry) => ({ date: entry.date, intent: entry.intent, presetIds: entry.presetIds })),
				},
			},
		});
	}

	continueAsPattern() {
		this.stopListening();
		closeSheet(this.element);
		const sheet = document.querySelector('[data-calendar-target="patternSheet"]');
		if (!sheet) return;
		sheet.dispatchEvent(new CustomEvent('pattern:seed', { detail: { slots: this.patternSlots } }));
		sheet.dispatchEvent(new CustomEvent('sheet:open'));
	}

	reset() {
		this.textTarget.value = '';
		this.usedMicrophone = false;
		this.patternSlots = [];
		this.patternOfferTarget.classList.add('hidden');
		this.unresolvedTarget.classList.add('hidden');
		this.clearError();
		this.textChanged();
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
