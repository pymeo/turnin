/*
 * The small amount of plumbing every calendar controller needs. Kept out of the
 * controllers so that the CSRF header and the error shape are defined once: the
 * endpoints all answer {ok, result} or {error}, and a controller that invented
 * its own handling would be the one that silently swallows a 419.
 */

export const TONE_CLASSES = {
	amber: 'calendar-tone-amber', orange: 'calendar-tone-orange', teal: 'calendar-tone-teal',
	blue: 'calendar-tone-blue', indigo: 'calendar-tone-indigo', violet: 'calendar-tone-violet',
	rose: 'calendar-tone-rose', slate: 'calendar-tone-slate', emerald: 'calendar-tone-emerald', cyan: 'calendar-tone-cyan',
	rest: 'calendar-tone-rest',
	unknown: 'calendar-tone-unknown',
};

let calendarContext = { assignmentId: null, view: null };

export function configureCalendarContext(assignmentId, view = null) {
	calendarContext = { assignmentId: assignmentId || null, view: view || null };
}

function contextualUrl(url) {
	const target = new URL(url, window.location.origin);
	if (calendarContext.assignmentId) target.searchParams.set('assignment', calendarContext.assignmentId);
	if (calendarContext.view) target.searchParams.set('view', calendarContext.view);
	return `${target.pathname}${target.search}`;
}

export function toneClass(tone) {
	return TONE_CLASSES[tone] || TONE_CLASSES.unknown;
}

export function applyTone(element, tone) {
	element.classList.remove(...Object.values(TONE_CLASSES));
	element.classList.add(toneClass(tone));
}

export async function postJson(url, csrf, body) {
	const contextualBody = calendarContext.assignmentId ? { ...body, assignmentId: calendarContext.assignmentId } : body;
	const response = await fetch(url, {
		method: 'POST',
		headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
		body: JSON.stringify(contextualBody),
	});
	const payload = await response.json().catch(() => ({}));
	if (!response.ok) throw new Error(payload.error || 'No se pudo completar la operación.');
	return payload.result;
}

export async function getJson(url) {
	const response = await fetch(contextualUrl(url), { headers: { Accept: 'application/json' } });
	const payload = await response.json().catch(() => ({}));
	if (!response.ok) throw new Error(payload.error || 'No se pudo cargar.');
	return payload;
}

/** "12 días" / "1 día" — small enough to inline everywhere, so it is not. */
export function plural(count, singular, pluralForm) {
	return `${count} ${count === 1 ? singular : pluralForm}`;
}

export function openSheet(dialog) {
	dialog.dispatchEvent(new CustomEvent('sheet:open'));
}

export function closeSheet(dialog) {
	dialog.dispatchEvent(new CustomEvent('sheet:close'));
}
