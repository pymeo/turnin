import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['input', 'value', 'results', 'status'];
    static values = { endpoint: String };
    connect() { this.timer = null; this.inputTarget.addEventListener('input', () => { clearTimeout(this.timer); this.timer = setTimeout(() => this.search(), 220); }); }
    disconnect() { clearTimeout(this.timer); }
    async search() { const term = this.inputTarget.value.trim(); if (term.length < 2) { this.resultsTarget.replaceChildren(); return; } this.statusTarget.textContent = 'Buscando…'; const url = new URL(this.endpointValue, window.location.origin); url.searchParams.set('q', term); try { const response = await fetch(url); if (!response.ok) throw new Error(); const { results } = await response.json(); this.resultsTarget.replaceChildren(); this.statusTarget.textContent = results.length ? '' : 'Sin resultados'; results.forEach((result) => { const button = document.createElement('button'); button.type = 'button'; button.className = 'w-full rounded-[var(--radius-control)] px-3 py-3 text-left hover:bg-surface-muted'; button.textContent = result.name + (result.municipality ? ` · ${result.municipality}` : ''); button.addEventListener('click', () => { this.inputTarget.value = result.name; this.valueTarget.value = result.id; this.resultsTarget.replaceChildren(); }); this.resultsTarget.append(button); }); } catch { this.statusTarget.textContent = 'No se pudo buscar. Inténtalo de nuevo.'; } }
}
