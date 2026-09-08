export function initInlineTabs() {
    document.querySelectorAll('[data-plathix-tabs]').forEach(tabList => {
        const tabs = Array.from(tabList.querySelectorAll('[data-plathix-tab]'));
        if (tabs.length === 0) {
            return;
        }

        const panels = tabs
            .map(tab => {
                const slug = tab.dataset.plathixTab || '';
                const panel = document.querySelector(`[data-plathix-tab-panel="${slug}"]`);
                return slug && panel ? [slug, panel] : null;
            })
            .filter(Boolean);

        if (panels.length === 0) {
            return;
        }

        const setActiveTab = slug => {
            tabs.forEach(tab => {
                const isActive = tab.dataset.plathixTab === slug;
                tab.classList.toggle('is-active', isActive);
                tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
                if (isActive) {
                    tab.setAttribute('aria-current', 'page');
                } else {
                    tab.removeAttribute('aria-current');
                }
            });

            panels.forEach(([panelSlug, panel]) => {
                const isActive = panelSlug === slug;
                panel.hidden = !isActive;
                panel.setAttribute('aria-hidden', isActive ? 'false' : 'true');
            });

            const url = new URL(window.location.href);
            url.searchParams.set('tab', slug);
            window.history.replaceState({}, '', url);
        };

        tabs.forEach(tab => {
            tab.addEventListener('click', event => {
                event.preventDefault();
                const slug = tab.dataset.plathixTab || '';
                if (!slug) {
                    return;
                }
                setActiveTab(slug);
            });
        });
    });
}
