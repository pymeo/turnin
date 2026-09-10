import { Controller } from '@hotwired/stimulus';

/*
 * Shows the "install" button only when the browser is actually willing to
 * install the app. Chrome fires `beforeinstallprompt`; iOS Safari never does,
 * which is why the button starts hidden instead of starting visible and being
 * hidden later.
 */
export default class extends Controller {
	static targets = ['button'];

	#deferredPrompt = null;

	connect() {
		this.onBeforeInstallPrompt = (event) => {
			event.preventDefault();
			this.#deferredPrompt = event;
			if (this.hasButtonTarget) {
				this.buttonTarget.hidden = false;
			}
		};

		this.onInstalled = () => this.#hide();

		window.addEventListener('beforeinstallprompt', this.onBeforeInstallPrompt);
		window.addEventListener('appinstalled', this.onInstalled);
	}

	disconnect() {
		window.removeEventListener('beforeinstallprompt', this.onBeforeInstallPrompt);
		window.removeEventListener('appinstalled', this.onInstalled);
	}

	async install() {
		if (!this.#deferredPrompt) {
			return;
		}

		const prompt = this.#deferredPrompt;
		this.#deferredPrompt = null;
		await prompt.prompt();
		this.#hide();
	}

	#hide() {
		if (this.hasButtonTarget) {
			this.buttonTarget.hidden = true;
		}
	}
}
