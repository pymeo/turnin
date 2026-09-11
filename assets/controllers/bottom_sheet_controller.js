import { Controller } from '@hotwired/stimulus';

/*
 * A <dialog> used as a bottom sheet.
 *
 * showModal() already gives us the focus trap, the Escape key and inertness of
 * the page behind — reimplementing those in JavaScript is how modals end up
 * unusable with a screen reader. This controller only adds what the platform
 * does not: restoring focus to whatever opened the sheet, and a pair of events
 * so other controllers can open it without reaching for the element's API.
 */
export default class extends Controller {
	connect() {
		this.opener = null;
		this.onOpen = () => this.open();
		this.onClose = () => this.close();
		this.element.addEventListener('sheet:open', this.onOpen);
		this.element.addEventListener('sheet:close', this.onClose);
		this.element.addEventListener('close', () => this.restoreFocus());
		// Clicking the backdrop is the gesture people try first on a phone.
		this.element.addEventListener('click', (event) => {
			if (event.target === this.element) this.close();
		});
	}

	disconnect() {
		this.element.removeEventListener('sheet:open', this.onOpen);
		this.element.removeEventListener('sheet:close', this.onClose);
	}

	open() {
		if (this.element.open) return;
		this.opener = document.activeElement;
		this.element.showModal();
	}

	close() {
		if (this.element.open) this.element.close();
	}

	restoreFocus() {
		if (this.opener && this.opener.isConnected) this.opener.focus({ preventScroll: true });
		this.opener = null;
	}
}
