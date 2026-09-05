import { _n } from '@wordpress/i18n';

// CSS Dashboard-виджетов co-located с модулем ([internal], #113):
// webpack извлекает его в assets/css/admin-ui/dashboard.css, HomeDashboardPage грузит его
// через wp_enqueue_style только на Dashboard. Сам import стиль на страницу НЕ доставляет —
// доставку делает enqueue_style во владельце.
import './dashboard.css';

// [internal]: per-card dismiss — cardId обязателен, по образцу postDismissMigration(source).
export function postDismissOnboarding(button, cardId) {
    if (!window.PlathixDashboard?.ajaxUrl || !window.PlathixDashboard?.dismissNonce) {
        return Promise.reject(new Error('Missing dashboard config'));
    }

    const body = new URLSearchParams({
        action: 'plathix_dismiss_onboarding',
        nonce: window.PlathixDashboard.dismissNonce,
        card_id: cardId || '',
    });

    button.disabled = true;

    return fetch(window.PlathixDashboard.ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body.toString(),
        credentials: 'same-origin',
    }).then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    }).then(data => {
        if (!data.success) throw new Error('dismiss rejected by server');
        return true;
    }).finally(() => {
        button.disabled = false;
    });
}

// [internal]: dismiss миграционного баннера сохраняется на бэкенде (per-source, user_meta),
// иначе баннер возвращается после reload. Тот же паттерн, что postDismissOnboarding.
export function postDismissMigration(button, source) {
    if (!window.PlathixDashboard?.ajaxUrl || !window.PlathixDashboard?.migrationDismissNonce) {
        return Promise.reject(new Error('Missing dashboard config'));
    }

    const body = new URLSearchParams({
        action: 'plathix_dismiss_migration',
        nonce: window.PlathixDashboard.migrationDismissNonce,
        source: source || '',
    });

    button.disabled = true;

    return fetch(window.PlathixDashboard.ajaxUrl, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
        },
        body: body.toString(),
        credentials: 'same-origin',
    }).then(response => {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
    }).then(data => {
        if (!data.success) throw new Error('dismiss rejected by server');
        return true;
    }).finally(() => {
        button.disabled = false;
    });
}

// [internal]: per-card dismiss — каждая карточка гасится своим ×, не весь блок разом.
// Блок исчезает только когда карточек не осталось (все дизмиснуты/условия закрылись).
export function initOnboardingDismiss() {
    const block = document.getElementById('plathix-onboarding-block');
    if (!block) {
        return;
    }

    const buttons = block.querySelectorAll('.plathix-onboarding__card-dismiss');

    buttons.forEach(button => {
        button.addEventListener('click', () => {
            const card = button.closest('.plathix-onboarding__card');
            const cardId = card?.dataset.cardId;
            if (!card || !cardId) {
                return;
            }

            postDismissOnboarding(button, cardId)
                .then(() => {
                    card.remove();
                    if (!block.querySelector('.plathix-onboarding__card')) {
                        block.remove();
                    }
                })
                .catch(() => {
                    // button re-enabled via .finally() in postDismissOnboarding; card stays visible
                });
        });
    });
}

export function initMigrationBannerDismiss() {
    const btn    = document.getElementById('plathix-migration-dismiss');
    const banner = document.getElementById('plathix-migration-banner');
    if (!btn || !banner) return;
    btn.addEventListener('click', () => {
        const source = banner.dataset.source || '';
        postDismissMigration(btn, source)
            .then(() => {
                banner.remove();
            })
            .catch(() => {
                // server не подтвердил — баннер остаётся; кнопка re-enabled в .finally()
            });
    });
}

function initUploadsWidget() {
    const widget = document.querySelector('.plathix-uploads-widget');
    if (!widget) return;

    const tabs     = widget.querySelectorAll('.plathix-uploads-tab');
    const countEl  = widget.querySelector('.plathix-uploads-count');
    const subEl    = widget.querySelector('.plathix-uploads-sub');
    const sparks   = widget.querySelectorAll('.plathix-spark-wrap');

    function applyPeriod(period) {
        tabs.forEach(t => t.classList.toggle('plathix-uploads-tab--active', t.dataset.period === period));
        if (countEl) countEl.textContent = countEl.getAttribute('data-count-' + period) || '0';
        if (subEl)   subEl.textContent   = subEl.getAttribute('data-sub-' + period)    || '';
        sparks.forEach(s => {
            s.style.display = s.dataset.spark === period ? '' : 'none';
        });
        widget.dataset.current = period;
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => applyPeriod(tab.dataset.period));
    });

    // Инициализация при загрузке — читаем активный таб из PHP
    const activeTab = widget.querySelector('.plathix-uploads-tab--active');
    if (activeTab) applyPeriod(activeTab.dataset.period);

    // Тултип-карточка при наведении на sparkline (две строки: дата капсом + число·единица).
    const tooltip = document.createElement('div');
    tooltip.className = 'plathix-spark-tooltip';
    tooltip.innerHTML =
        '<div class="plathix-spark-tip-date"></div>' +
        '<div class="plathix-spark-tip-val"><span class="plathix-spark-tip-dot"></span>' +
        '<span class="plathix-spark-tip-num"></span> <span class="plathix-spark-tip-unit"></span></div>';
    const tipDate = tooltip.querySelector('.plathix-spark-tip-date');
    const tipNum  = tooltip.querySelector('.plathix-spark-tip-num');
    const tipUnit = tooltip.querySelector('.plathix-spark-tip-unit');
    widget.style.position = 'relative';
    widget.appendChild(tooltip);

    sparks.forEach(wrap => {
        const svg = wrap.querySelector('svg');
        if (!svg) return;

        let points = [];
        try { points = JSON.parse(wrap.dataset.points || '[]'); } catch (e) { return; }
        if (!points.length) return;

        // Индексы дней с загрузками — тултип снапится ТОЛЬКО к ним, а не к арифметическому
        // idx: иначе промах у пика попадал на соседний нулевой день и показывал «0».
        const nonZero = points
            .map((pt, i) => ({ i, count: pt.count }))
            .filter(p => p.count > 0)
            .map(p => p.i);
        if (!nonZero.length) return; // нет ни одной загрузки за период — тултипу нечего показывать

        const maxCount = points.reduce((m, p) => Math.max(m, p.count), 0) || 1;
        const n = points.length;

        // Активный маркер (кружок с гало) + вертикальная guide-линия — DOM-оверлей поверх
        // wrap: рисуем на снапнутой точке, следуют за курсором. Статичные <circle> в
        // растянутом SVG плющит в эллипс, поэтому оверлей в пикселях, а не в единицах viewBox.
        const guide  = document.createElement('div');
        guide.className = 'plathix-spark-guide';
        const marker = document.createElement('div');
        marker.className = 'plathix-spark-marker';
        wrap.style.position = 'relative';
        wrap.appendChild(guide);
        wrap.appendChild(marker);

        const hide = function () {
            tooltip.style.display = 'none';
            guide.style.display   = 'none';
            marker.style.display  = 'none';
        };

        svg.addEventListener('mousemove', function (e) {
            const rect = svg.getBoundingClientRect();
            const ratio = Math.max(0, Math.min(1, (e.clientX - rect.left) / rect.width));
            const rawIdx = ratio * (n - 1);
            // ближайший по X ненулевой день
            let idx = nonZero[0];
            let best = Math.abs(rawIdx - idx);
            for (const ni of nonZero) {
                const d = Math.abs(rawIdx - ni);
                if (d < best) { best = d; idx = ni; }
            }
            const pt = points[idx];
            if (!pt) return;

            // координаты активной точки внутри wrap (в пикселях)
            const px = (n > 1 ? idx / (n - 1) : 0) * rect.width;
            const py = rect.height - (pt.count / maxCount) * (rect.height - 4) - 2;

            marker.style.display = 'block';
            marker.style.left = px + 'px';
            marker.style.top  = py + 'px';
            guide.style.display = 'block';
            guide.style.left = px + 'px';
            guide.style.top  = py + 'px';
            guide.style.height = (rect.height - py) + 'px';

            // дата капсом (TUE, JUN 30) + число + единица
            const d = new Date(pt.date + 'T00:00:00');
            if (!isNaN(d.getTime())) {
                tipDate.textContent = d.toLocaleDateString(undefined,
                    { weekday: 'short', month: 'short', day: 'numeric' }).toUpperCase();
            } else {
                tipDate.textContent = pt.date;
            }
            tipNum.textContent  = String(pt.count);
            tipUnit.textContent = _n('upload', 'uploads', pt.count, 'plathix');

            tooltip.style.display = 'block';
            const wRect = widget.getBoundingClientRect();
            const wrapRect = wrap.getBoundingClientRect();
            const anchorX = (wrapRect.left - wRect.left) + px;
            const anchorY = (wrapRect.top - wRect.top) + py;
            tooltip.style.left = anchorX + 'px';
            tooltip.style.top  = (anchorY - tooltip.offsetHeight - 12) + 'px';
        });

        svg.addEventListener('mouseleave', hide);
    });
}

function initWizardOverlay() {
    // Free first-run визард ([internal]): помощник, не жёсткий путь. Закрытие по клику на
    // затемнённый фон (бэкдроп) и по Esc ведёт на skip-URL (серверный mark_skipped — визард
    // больше не всплывает), а не просто прячет overlay JS-ом. «×» и «Пропустить» — обычные ссылки
    // на тот же skip-URL, отдельного обработчика не требуют.
    const overlay = document.getElementById('plathix-wizard-overlay');
    if (!overlay) {
        return;
    }

    const skipUrl = overlay.dataset.wizardSkip || '';
    if (!skipUrl) {
        return;
    }

    // клик именно по фону оверлея (не по карточке внутри)
    overlay.addEventListener('click', event => {
        if (event.target === overlay) {
            window.location.href = skipUrl;
        }
    });

    document.addEventListener('keydown', event => {
        if (event.key === 'Escape' && document.getElementById('plathix-wizard-overlay')) {
            window.location.href = skipUrl;
        }
    });
}

document.addEventListener('DOMContentLoaded', () => {
    initOnboardingDismiss();
    initMigrationBannerDismiss();
    initUploadsWidget();
    initWizardOverlay();
});
