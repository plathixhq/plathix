



const FOCUSABLE_SELECTOR = 'button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])';



export function trapFocus(container, event) {
	if (!(container instanceof HTMLElement)) return;
	/** @type {HTMLElement[]} */
	const focusable = [];
	container.querySelectorAll(FOCUSABLE_SELECTOR).forEach((el) => {
		if (el instanceof HTMLElement && el.offsetParent !== null) focusable.push(el);
	});
	if (focusable.length === 0) return;
	const first = focusable[0];
	const last = focusable[focusable.length - 1];
	if (event.shiftKey && document.activeElement === first) {
		event.preventDefault();
		last.focus();
	} else if (!event.shiftKey && document.activeElement === last) {
		event.preventDefault();
		first.focus();
	}
}



export function focusFirstIn(container) {
	if (!(container instanceof HTMLElement)) return;
	const focusable = container.querySelector(FOCUSABLE_SELECTOR);
	if (focusable instanceof HTMLElement) focusable.focus();
}



export function restoreFocus(opener) {
	if (opener instanceof HTMLElement && document.body.contains(opener)) {
		opener.focus();
	}
}



export function captureActiveElement() {
	return document.activeElement instanceof HTMLElement ? document.activeElement : null;
}
