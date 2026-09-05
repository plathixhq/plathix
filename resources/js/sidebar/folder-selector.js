import { Api } from './api.js';
import { escapeHtml } from './utils/escape.js';

function toNumber(value, fallback = 0) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
}


function buildTreeMap(folders) {
    const byParent = new Map();

    folders.forEach((folder) => {
        const parentId = toNumber(folder?.parentId, 0);
        const group = byParent.get(parentId) || [];
        group.push(folder);
        byParent.set(parentId, group);
    });

    byParent.forEach((group) => {
        group.sort((left, right) => {
            const leftPos = toNumber(left?.position, 0);
            const rightPos = toNumber(right?.position, 0);
            if (leftPos !== rightPos) {
                return leftPos - rightPos;
            }

            return String(left?.name || '').localeCompare(String(right?.name || ''), undefined, { sensitivity: 'base' });
        });
    });

    return byParent;
}

function flattenFolders(folders, { includeProtected = true } = {}) {
    const byParent = buildTreeMap(Array.isArray(folders) ? folders : []);
    const flattened = [];

    const walk = (parentId, depth) => {
        const items = byParent.get(parentId) || [];
        items.forEach((folder) => {
            if (includeProtected || !folder?.isProtected) {
                flattened.push({ ...folder, depth });
            }
            walk(toNumber(folder?.id, 0), depth + 1);
        });
    };

    walk(0, 0);

    return flattened;
}

function normalizeTarget(target) {
    if (!target) {
        return null;
    }

    if (target instanceof HTMLElement) {
        return target;
    }

    if (typeof target === 'string') {
        return document.querySelector(target);
    }

    return null;
}

export class FolderSelector {
    constructor(target, options = {}) {
        this.root = normalizeTarget(target);
        if (!this.root) {
            throw new Error('Plathix folder selector target not found');
        }

        this.options = {
            value: 0,
            includeAll: false,
            allLabel: 'All folders',
            includeProtected: true,
            placeholder: '',
            className: 'plathix-folder-selector',
            selectClassName: 'plathix-folder-selector__control',
            query: {},
            onChange: null,
            ...options,
        };

        this.folders = [];
        this.select = null;
        this.handleChange = this.handleChange.bind(this);
    }

    async init() {
        await this.refresh();
        return this;
    }

    async refresh() {
        const response = await Api.getFolders(this.options.query || {});
        this.folders = Array.isArray(response?.folders) ? response.folders : [];
        this.render();
        return this.folders;
    }

    destroy() {
        if (this.select) {
            this.select.removeEventListener('change', this.handleChange);
        }
        this.root.innerHTML = '';
        this.select = null;
    }

    getFolders() {
        return [...this.folders];
    }

    getValue() {
        return this.select ? toNumber(this.select.value, 0) : toNumber(this.options.value, 0);
    }

    setValue(value) {
        const normalized = toNumber(value, 0);
        this.options.value = normalized;
        if (this.select) {
            this.select.value = String(normalized);
        }
    }

    focus() {
        this.select?.focus();
    }

    render() {
        const flattened = flattenFolders(this.folders, {
            includeProtected: !!this.options.includeProtected,
        });

        const optionMarkup = [];

        if (this.options.placeholder) {
            optionMarkup.push(
                `<option value="">${escapeHtml(this.options.placeholder)}</option>`
            );
        }

        if (this.options.includeAll) {
            optionMarkup.push(
                `<option value="0">${escapeHtml(this.options.allLabel)}</option>`
            );
        }

        flattened.forEach((folder) => {
            const indent = folder.depth > 0 ? `${'  '.repeat(folder.depth)}↳ ` : '';
            optionMarkup.push(
                `<option value="${escapeHtml(folder.id)}">${escapeHtml(indent + String(folder.name || ''))}</option>`
            );
        });

        this.root.classList.add(this.options.className);
        this.root.innerHTML = `<select class="${escapeHtml(this.options.selectClassName)}">${optionMarkup.join('')}</select>`;
        this.select = this.root.querySelector('select');
        this.select.addEventListener('change', this.handleChange);

        const selected = this.options.placeholder && toNumber(this.options.value, 0) === 0
            ? ''
            : String(toNumber(this.options.value, 0));
        this.select.value = selected;
    }

    handleChange(event) {
        const value = event?.target?.value === '' ? 0 : toNumber(event?.target?.value, 0);
        this.options.value = value;

        if (typeof this.options.onChange === 'function') {
            this.options.onChange(value, this);
        }
    }
}

export async function createFolderSelector(target, options = {}) {
    const selector = new FolderSelector(target, options);
    await selector.init();
    return selector;
}

