/* The Service page works without JavaScript. Enhance navigation and the labelled demo CTA. */
(function () {
	'use strict';
	var root = document.getElementById('nova-service-v2');
	if (!root) return;
	root.addEventListener('click', function (event) {
		var demoButton = event.target.closest('.nova-service__demo-submit');
		if (demoButton && root.contains(demoButton)) {
			var demo = demoButton.closest('.nova-service__demo-cta');
			if (!demo) return;
			event.preventDefault();
			var fields = demo.querySelectorAll('input');
			for (var index = 0; index < fields.length; index++) {
				if (!fields[index].checkValidity()) {
					fields[index].reportValidity();
					return;
				}
			}
			var status = demo.querySelector('.nova-service__demo-status');
			if (status) status.textContent = 'Je aanvraag is klaar. Dit is een demo: er zijn geen gegevens verzonden. Koppel je eigen formulier om aanvragen te ontvangen.';
			return;
		}
		var link = event.target.closest('.nova-service__button[href^="#"]');
		if (!link || !root.contains(link) || event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
		var id;
		try { id = decodeURIComponent(link.hash.slice(1)); } catch (error) { return; }
		var target = document.getElementById(id);
		if (!target || !id) return;
		if (target.tagName === 'DETAILS') target.open = true;
		// Let native anchors update the URL and retain their no-JavaScript fallback.
		if (!target.hasAttribute('tabindex') && !/^(A|BUTTON|INPUT|SELECT|TEXTAREA)$/.test(target.tagName)) {
			target.setAttribute('tabindex', '-1');
			target.addEventListener('blur', function () { target.removeAttribute('tabindex'); }, { once: true });
		}
		target.focus({ preventScroll: true });
	});
}());
