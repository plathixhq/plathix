import '../css/admin-menu.css';

(function () {
	const config = window.PlathixAdminMenu;
	if (!config || !config.parentMenuId || !config.anchorSlug || !Array.isArray(config.items) || config.items.length === 0) {
		return;
	}

	const root = document.getElementById(config.parentMenuId);
	if (!root) {
		return;
	}

	const links = root.querySelectorAll('.wp-submenu a');
	let anchorLi = null;

	for (let i = 0; i < links.length; i += 1) {
		const href = links[i].getAttribute('href') || '';
		if (href.indexOf('page=' + config.anchorSlug) !== -1) {
			anchorLi = links[i].closest('li');
			break;
		}
	}

	if (!anchorLi) {
		return;
	}

	anchorLi.classList.add('plathix-has-flyout');

	const flyoutUl = document.createElement('ul');
	flyoutUl.className = 'plathix-submenu__flyout';
	flyoutUl.setAttribute('role', 'menu');

	config.items.forEach(function (item) {
		const li = document.createElement('li');
		const a = document.createElement('a');
		a.href = item.url;
		a.textContent = item.label;
		a.setAttribute('role', 'menuitem');
		if (config.currentPage === item.slug) {
			a.classList.add('current');
		}
		li.appendChild(a);
		flyoutUl.appendChild(li);
	});

	anchorLi.appendChild(flyoutUl);

	let closeTimer = null;

	function openFlyout() {
		if (closeTimer) {
			clearTimeout(closeTimer);
			closeTimer = null;
		}
		anchorLi.classList.add('plathix-flyout-open');
	}

	function scheduleClose() {
		if (closeTimer) {
			clearTimeout(closeTimer);
		}
		closeTimer = setTimeout(function () {
			anchorLi.classList.remove('plathix-flyout-open');
		}, 120);
	}

	function handleAnchorKeydown(e) {
		if (e.key === 'ArrowRight' || e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			openFlyout();
			const first = flyoutUl.querySelector('a');
			if (first) { first.focus(); }
		}
	}

	function handleFlyoutKeydown(e) {
		if (e.key === 'Escape') {
			anchorLi.classList.remove('plathix-flyout-open');
			const anchorLink = anchorLi.querySelector('a');
			if (anchorLink) { anchorLink.focus(); }
		}
	}

	anchorLi.addEventListener('mouseenter', openFlyout);
	anchorLi.addEventListener('mouseleave', scheduleClose);
	anchorLi.addEventListener('focusin', openFlyout);
	anchorLi.addEventListener('keydown', handleAnchorKeydown);
	flyoutUl.addEventListener('mouseenter', openFlyout);
	flyoutUl.addEventListener('mouseleave', scheduleClose);
	flyoutUl.addEventListener('focusout', function (e) {
		if (!anchorLi.contains(e.relatedTarget)) {
			scheduleClose();
		}
	});
	flyoutUl.addEventListener('keydown', handleFlyoutKeydown);
})();
