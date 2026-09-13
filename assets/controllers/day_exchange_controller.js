import { Controller } from '@hotwired/stimulus';

/*
 * The exchange half of the calendar's day sheet.
 *
 * A separate controller because it belongs to a separate context: the calendar
 * knows what somebody works, Swap knows what they want to do about it. The two
 * meet through one event and one endpoint, and neither has to grow to know the
 * other's rules — the server decides which action a day allows.
 */
export default class extends Controller {
	static targets = ['panel', 'status', 'groups', 'candidates', 'publish', 'offer', 'withdrawRequest', 'withdrawAvailability', 'error'];
	static values = { csrf: String };

	connect() {
		this.state = null;
		this.assignmentId = null;
		this.date = null;
		// Dispatched by calendar_controller when a cell opens the sheet.
		this.onDay = (event) => this.load(event.detail);
		document.addEventListener('exchange:day', this.onDay);
	}

	disconnect() {
		document.removeEventListener('exchange:day', this.onDay);
	}

	async load({ date, assignmentId }) {
		this.date = date;
		this.assignmentId = assignmentId || '';
		this.clearError();
		this.hideAll();
		try {
			const url = `/app/changes/dia?date=${encodeURIComponent(date)}&assignment=${encodeURIComponent(this.assignmentId)}`;
			const response = await fetch(url, { headers: { Accept: 'application/json' } });
			const payload = await response.json().catch(() => ({}));
			if (!response.ok || !payload.result) return;
			this.state = payload.result;
			this.render();
		} catch {
			// The exchange block is an addition to the day sheet; if it cannot
			// load, the calendar still works. Failing loudly here would break
			// editing a shift because a second context was unavailable.
		}
	}

	render() {
		const state = this.state;
		this.panelTarget.classList.remove('hidden');
		this.panelTarget.classList.add('flex');

		if (state.requestId) {
			// The count lives on the list it counts; saying it twice reads as
			// two different facts.
			this.statusTarget.textContent = 'Buscando compañero';
			this.show(this.withdrawRequestTarget);
			this.renderCandidates(state.candidates);
			return;
		}

		if (state.canPublish) {
			this.statusTarget.textContent = '';
			this.show(this.publishTarget);
			this.renderGroups(state.groups, state.availableIn);
			return;
		}

		if (state.availableIn.length > 0) {
			this.statusTarget.textContent = 'Disponible para trabajar';
			this.show(this.withdrawAvailabilityTarget);
			return;
		}

		if (state.canOfferAvailability) {
			this.statusTarget.textContent = '';
			this.show(this.offerTarget);
			this.renderGroups(state.groups, state.availableIn);
			return;
		}

		// Nothing to offer and nothing published: a past day, or a calendar
		// this worker has no group for.
		this.panelTarget.classList.add('hidden');
		this.panelTarget.classList.remove('flex');
	}

	/** Only asked when there is a real choice to make. */
	renderGroups(groups, alreadyIn) {
		this.groupsTarget.replaceChildren();
		if (groups.length < 2) return;
		this.groupsTarget.classList.remove('hidden');
		this.groupsTarget.classList.add('flex');
		const title = document.createElement('p');
		title.className = 'text-sm text-ink-muted';
		title.textContent = '¿Dónde?';
		this.groupsTarget.append(title);
		for (const group of groups) {
			const label = document.createElement('label');
			label.className = 'flex items-center gap-2 text-sm';
			const input = document.createElement('input');
			input.type = 'checkbox';
			input.value = group.poolId;
			input.checked = alreadyIn.includes(group.poolId) || group.primary;
			label.append(input, document.createTextNode(group.label));
			this.groupsTarget.append(label);
		}
	}

	renderCandidates(candidates) {
		this.candidatesTarget.replaceChildren();
		if (!candidates.length) return;
		this.candidatesTarget.classList.remove('hidden');
		this.candidatesTarget.classList.add('flex');
		const title = document.createElement('p');
		title.className = 'text-sm text-ink-muted';
		title.textContent = `${candidates.length} ${candidates.length === 1 ? 'persona disponible' : 'personas disponibles'} ese día`;
		this.candidatesTarget.append(title);
		for (const candidate of candidates) {
			const row = document.createElement('div');
			row.className = 'candidate-row';
			const text = document.createElement('span');
			text.textContent = `${candidate.name} · ${candidate.groupLabel}`;
			const button = document.createElement('button');
			button.type = 'button';
			button.className = 'btn-secondary';
			button.textContent = 'Elegir';
			button.addEventListener('click', () => this.cover(button, candidate.availabilityId));
			row.append(text, button);
			this.candidatesTarget.append(row);
		}
	}

	async cover(button, availabilityId) {
		await this.act(button, `/app/changes/${this.state.requestId}/cubrir`, { availabilityId });
	}

	async publish(event) {
		await this.act(event.currentTarget, '/app/changes/publicar', {
			assignmentId: this.assignmentId,
			date: this.date,
			swapPoolId: this.chosenGroups()[0] || '',
		});
	}

	async offerAvailability(event) {
		window.location.assign(`/app/changes?flow=availability&date=${encodeURIComponent(this.date)}`);
	}

	async withdrawRequest(event) {
		await this.act(event.currentTarget, `/app/changes/${this.state.requestId}/retirar`, {});
	}

	async withdrawMyAvailability(event) {
		await this.act(event.currentTarget, '/app/changes/disponibilidad/retirar', { date: this.date });
	}

	async act(button, url, body) {
		button.disabled = true;
		this.clearError();
		try {
			const response = await fetch(url, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfValue, Accept: 'application/json' },
				body: JSON.stringify(body),
			});
			const payload = await response.json().catch(() => ({}));
			if (!response.ok) throw new Error(payload.error || 'No se pudo completar la operación.');
			await this.load({ date: this.date, assignmentId: this.assignmentId });
			this.dispatch('changed', { prefix: 'exchange', detail: { date: this.date }, bubbles: true });
		} catch (error) {
			this.showError(error.message);
		} finally {
			button.disabled = false;
		}
	}

	chosenGroups() {
		const checked = [...this.groupsTarget.querySelectorAll('input[type="checkbox"]:checked')];
		if (checked.length) return checked.map((input) => input.value);
		const groups = this.state?.groups ?? [];
		const primary = groups.find((group) => group.primary) || groups[0];
		return primary ? [primary.poolId] : [];
	}

	hideAll() {
		this.panelTarget.classList.add('hidden');
		this.panelTarget.classList.remove('flex');
		for (const target of [this.publishTarget, this.offerTarget, this.withdrawRequestTarget, this.withdrawAvailabilityTarget]) {
			target.classList.add('hidden');
		}
		for (const target of [this.groupsTarget, this.candidatesTarget]) {
			target.classList.add('hidden');
			target.classList.remove('flex');
			target.replaceChildren();
		}
		this.statusTarget.textContent = '';
	}

	show(target) {
		target.classList.remove('hidden');
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
