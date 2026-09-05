import { __, _n } from '@wordpress/i18n';

// CSS страницы Пресетов co-located с модулем ([internal], #113):
// webpack извлекает его в assets/css/admin-ui/preset.css, PresetsPage грузит его через
// wp_enqueue_style только на странице Пресетов. Сам import стиль на страницу НЕ доставляет —
// доставку делает enqueue_style во владельце.
import './preset.css';

function initUploadModal() {
    const uploadModal = document.getElementById('plathix-upload-modal');
    if (!uploadModal) return;

    const uploadClose = document.getElementById('plathix-upload-close');
    const dropZone    = document.getElementById('plathix-drop-zone');
    const fileInput   = document.getElementById('plathix-file-input');
    const uploadApply = document.getElementById('plathix-upload-apply');
    const uploadDifferent = document.getElementById('plathix-upload-different');
    let selectedFile = null;

    const setState = (state) => {
        const states = {
            idle: document.getElementById('plathix-upload-idle'),
            parsing: document.getElementById('plathix-upload-parsing'),
            ready: document.getElementById('plathix-upload-ready'),
            error: document.getElementById('plathix-upload-error'),
        };

        Object.entries(states).forEach(([key, node]) => {
            if (node) {
                node.style.display = key === state ? '' : 'none';
            }
        });
    };

    const resetModal = () => {
        selectedFile = null;
        delete window.__plathixSelectedPresetFile;
        if (fileInput) {
            fileInput.value = '';
        }
        setState('idle');
    };

    document.querySelectorAll('[data-open-upload-modal]').forEach(btn => {
        btn.addEventListener('click', () => {
            resetModal();
            uploadModal.style.display = 'flex';
        });
    });

    uploadClose?.addEventListener('click', () => {
        uploadModal.style.display = 'none';
        resetModal();
    });

    uploadModal.addEventListener('click', e => {
        if (e.target === uploadModal) {
            uploadModal.style.display = 'none';
            resetModal();
        }
    });

    dropZone?.addEventListener('click', () => fileInput && fileInput.click());

    dropZone?.addEventListener('dragover', e => {
        e.preventDefault();
        dropZone.classList.add('is-over');
    });
    dropZone?.addEventListener('dragleave', () => dropZone.classList.remove('is-over'));
    dropZone?.addEventListener('drop', e => {
        e.preventDefault();
        dropZone.classList.remove('is-over');
        handlePresetFile(e.dataTransfer.files[0]);
    });

    fileInput?.addEventListener('change', e => {
        if (e.target.files[0]) handlePresetFile(e.target.files[0]);
    });

    document.getElementById('plathix-upload-retry')?.addEventListener('click', () => {
        resetModal();
    });

    uploadDifferent?.addEventListener('click', () => {
        resetModal();
    });

    uploadApply?.addEventListener('click', () => {
        const file = selectedFile || window.__plathixSelectedPresetFile;
        if (!file) {
            return;
        }

        const form = document.getElementById('plathix-upload-form');
        if (!form) {
            return;
        }

        const input = form.querySelector('input[type="file"]');
        if (input) {
            const dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
        }
        form.submit();
    });
}

// [internal]: показ success-уведомления должен ждать реальную серверную проверку ZIP-структуры,
// не декоративный таймер. handlePresetFile() шлёт файл на wp_ajax_plathix_preset_validate
// (dry-run — ничего не персистится) и переходит в ready-состояние только по success-ответу
// сервера; при ошибке показывает error-состояние с текстом от PresetUploadPipeline.
function handlePresetFile(file) {
    const idle    = document.getElementById('plathix-upload-idle');
    const parsing = document.getElementById('plathix-upload-parsing');
    const ready   = document.getElementById('plathix-upload-ready');
    const errBox  = document.getElementById('plathix-upload-error');
    const errText = document.getElementById('plathix-upload-error-text');
    const readyName = document.getElementById('plathix-upload-ready-name');
    const readyMeta = document.getElementById('plathix-upload-ready-meta');

    if (!file) return;

    // Быстрый клиентский pre-check до сетевого запроса — не показывает ready сам по себе,
    // только отсекает явно неподходящие файлы раньше отправки на сервер.
    if (!file.name.endsWith('.zip')) {
        idle.style.display   = 'none';
        parsing.style.display = 'none';
        if (ready) ready.style.display = 'none';
        errBox.style.display = '';
        errText.textContent  = __('Only .zip files are accepted. Please upload a valid Plathix preset package.', 'plathix');
        return;
    }
    if (file.size > 512 * 1024) {
        idle.style.display   = 'none';
        parsing.style.display = 'none';
        if (ready) ready.style.display = 'none';
        errBox.style.display = '';
        errText.textContent  = __('File is too large. Preset files should be under 512 KB.', 'plathix');
        return;
    }

    idle.style.display    = 'none';
    if (ready) ready.style.display = 'none';
    errBox.style.display = 'none';
    parsing.style.display = '';

    const sizeKb = Math.max(1, Math.round(file.size / 1024));
    const nonceField = document.querySelector('#plathix-upload-form input[name="_wpnonce"]');
    const nonce = nonceField ? nonceField.value : '';

    const formData = new FormData();
    formData.append('action', 'plathix_preset_validate');
    formData.append('_wpnonce', nonce);
    formData.append('plathix_preset_zip', file, file.name);

    fetch(window.ajaxurl, {
        method: 'POST',
        credentials: 'same-origin',
        body: formData,
    })
        .then(response => response.json())
        .then(payload => {
            parsing.style.display = 'none';

            if (!payload || !payload.success) {
                if (ready) ready.style.display = 'none';
                errBox.style.display = '';
                errText.textContent = (payload && payload.data && payload.data.message)
                    || __('Could not validate the archive.', 'plathix');
                return;
            }

            if (ready) {
                ready.style.display = '';
            }
            if (readyName) {
                readyName.textContent = file.name;
            }
            if (readyMeta) {
                /* translators: %s = file size in KB */
                readyMeta.textContent = sizeKb + ' KB · ' + __('Preset package', 'plathix');
            }
            window.__plathixSelectedPresetFile = file;
        })
        .catch(() => {
            parsing.style.display = 'none';
            if (ready) ready.style.display = 'none';
            errBox.style.display = '';
            errText.textContent = __('Could not reach the server to validate the archive.', 'plathix');
        });
}

function initPresetsSearch() {
    const searchEl  = document.getElementById('plathix-preset-search');
    const sortEl    = document.getElementById('plathix-preset-sort');
    const catalog   = document.getElementById('plathix-presets-catalog');
    const noResults = document.getElementById('plathix-presets-no-results');

    if (!searchEl || !catalog) return;

    function applyFilter() {
        const q    = searchEl.value.toLowerCase().trim();
        const sort = sortEl ? sortEl.value : 'default';
        const cards = Array.from(catalog.querySelectorAll('.plathix-preset-card'));
        const sections = Array.from(catalog.querySelectorAll('.plathix-presets-section'));

        // builtin/custom — это ФИЛЬТР по источнику (показать только встроенные /
        // только загруженные), а не сортировка: карточки уже разложены PHP'ом по
        // секциям источника, поэтому переставлять их внутри секции бессмысленно.
        // Признак встроенного — наличие бейджа .plathix-badge--ok (Official).
        // default/alpha источник не фильтруют.
        const sourceFilter = (sort === 'builtin' || sort === 'custom') ? sort : null;

        let visible = 0;
        cards.forEach(card => {
            const title = card.dataset.title || '';
            const desc  = card.dataset.desc  || '';
            const matchSearch = !q || title.includes(q) || desc.includes(q);

            const isBuiltin = !!card.querySelector('.plathix-badge--ok');
            const matchSource = sourceFilter === null
                || (sourceFilter === 'builtin' && isBuiltin)
                || (sourceFilter === 'custom' && !isBuiltin);

            const match = matchSearch && matchSource;
            card.style.display = match ? '' : 'none';
            if (match) visible++;
        });

        // Секция с источником, который отфильтрован, остаётся без видимых карточек
        // и скрывается целиком этим механизмом — так на странице остаётся только
        // выбранный источник.
        sections.forEach(section => {
            const visibleInSection = section.querySelector('.plathix-preset-card:not([style*="display: none"])');
            section.style.display = visibleInSection ? '' : 'none';
        });

        if (noResults) noResults.style.display = visible === 0 ? '' : 'none';

        if (sort === 'alpha') {
            const sorted = cards.filter(c => c.style.display !== 'none')
                .sort((a, b) => (a.dataset.title || '').localeCompare(b.dataset.title || ''));
            sorted.forEach(c => c.parentNode?.appendChild(c));
        }
    }

    searchEl.addEventListener('input', applyFilter);
    if (sortEl) sortEl.addEventListener('change', applyFilter);
}

// [internal]: после импорта handle_upload редиректит с plathix_new_preset=<id>.
// Скроллим к карточке нового пресета и кратко подсвечиваем, чтобы пользователь увидел
// только что загруженный пресет (применение — отдельным шагом у карточки).
function highlightNewPreset() {
    const params = new URLSearchParams(window.location.search);
    const newId = params.get('plathix_new_preset');
    if (!newId) {
        return;
    }

    const card = document.querySelector(`.plathix-preset-card[data-preset-id="${CSS.escape(newId)}"]`);
    if (card) {
        card.scrollIntoView({ block: 'center', behavior: 'smooth' });
        card.classList.add('plathix-preset-card--highlight');
        window.setTimeout(() => card.classList.remove('plathix-preset-card--highlight'), 1800);
    }

    // Убираем параметр из URL, чтобы F5 не скроллил/подсвечивал повторно.
    params.delete('plathix_new_preset');
    const url = new URL(window.location.href);
    url.search = params.toString();
    window.history.replaceState({}, '', url);
}

document.addEventListener('DOMContentLoaded', () => {
    initUploadModal();
    initPresetsSearch();
    highlightNewPreset();
});
