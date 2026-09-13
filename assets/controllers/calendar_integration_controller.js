import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['calendar', 'assignment', 'from', 'to', 'preview', 'summary', 'items', 'message'];
    static values = { csrf: String };

    connect() {
        if (!this.hasFromTarget) return;
        const now = new Date();
        const first = new Date(now.getFullYear(), now.getMonth(), 1);
        const last = new Date(now.getFullYear(), now.getMonth() + 1, 0);
        this.fromTarget.value = this.date(first);
        this.toTarget.value = this.date(last);
        this.lastItems = [];
    }

    async preview() {
        const result = await this.post('/app/calendar/integrations/google/import/preview', this.payload());
        this.lastItems = result.items || [];
        this.summaryTarget.textContent = `${result.recognized} turnos · ${result.review} eventos personales o para revisar · ${result.ignored} ignorados`;
        this.itemsTarget.replaceChildren(...this.lastItems.map((item) => {
            const label = document.createElement('label');
            label.className = 'flex items-start gap-2 rounded-xl border border-line p-3 text-sm';
            const importable = ['recognized', 'personal'].includes(item.status);
            const checked = importable ? 'checked' : '';
            const disabled = importable ? '' : 'disabled';
            const kind = item.status === 'recognized' ? 'Detectado como turno' : item.status === 'personal' ? 'Evento personal' : 'Necesita revisión';
            label.innerHTML = `<input type="checkbox" value="${this.escape(item.eventId)}" ${checked} ${disabled}><span><strong>${this.escape(item.date)} · ${this.escape(item.title)}</strong><br>${this.escape(item.description)} · ${this.escape(kind)}</span>`;
            return label;
        }));
        this.previewTarget.classList.remove('hidden');
        this.previewTarget.classList.add('flex');
    }

    async importSelected() {
        const eventIds = [...this.itemsTarget.querySelectorAll('input:checked')].map((input) => input.value);
        const result = await this.post('/app/calendar/integrations/google/import', {...this.payload(), eventIds});
        this.messageTarget.textContent = `${result.applied || 0} turnos · ${result.personalCreated || 0} eventos personales nuevos · ${result.personalUpdated || 0} actualizados. Los conflictos existentes se han conservado.`;
        this.previewTarget.classList.add('hidden');
    }

    async export() {
        const result = await this.post('/app/calendar/integrations/google/export', this.payload());
        this.messageTarget.textContent = `${result.created || 0} eventos creados · ${result.updated || 0} actualizados. No se han creado duplicados.`;
    }

    downloadIcs() {
        const query = new URLSearchParams({assignment: this.assignmentTarget.value, from: this.fromTarget.value, to: this.toTarget.value});
        window.location.assign(`/app/calendar/integrations/ics?${query}`);
    }

    async disconnect() {
        await this.post('/app/calendar/integrations/google/disconnect', {});
        window.location.reload();
    }

    payload() {
        return {calendarId: this.calendarTarget.value, assignmentId: this.assignmentTarget.value, from: `${this.fromTarget.value}T00:00:00Z`, to: `${this.toTarget.value}T23:59:59Z`};
    }

    async post(url, body) {
        this.messageTarget.textContent = '';
        const response = await fetch(url, {method: 'POST', headers: {'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrfValue}, body: JSON.stringify(body)});
        const result = await response.json();
        if (!response.ok) {
            this.messageTarget.textContent = result.error || 'No hemos podido sincronizar Google Calendar. Tus turnos de Turnin siguen intactos.';
            throw new Error(this.messageTarget.textContent);
        }
        return result;
    }

    date(value) {
        return `${value.getFullYear()}-${String(value.getMonth() + 1).padStart(2, '0')}-${String(value.getDate()).padStart(2, '0')}`;
    }

    escape(value) {
        const node = document.createElement('span'); node.textContent = String(value); return node.innerHTML;
    }
}
