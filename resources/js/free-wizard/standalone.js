
//







import '../../css/free-wizard.css';

document.addEventListener('DOMContentLoaded', () => {
    document.addEventListener('click', e => {
        const link = e.target.closest('[data-confirm]');
        if (!link) {
            return;
        }
        if (!window.confirm(link.dataset.confirm)) {
            e.preventDefault();
        }
    });
});
