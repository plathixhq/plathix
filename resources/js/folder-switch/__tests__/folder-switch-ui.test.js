import { bindFolderSwitchUi, __resetFolderSwitchCacheForTests } from '../folder-switch-ui.js';
import { restRequest } from '../../sidebar/api/transport.js';

jest.mock('../../sidebar/api/transport.js', () => ({ restRequest: jest.fn() }));

const mockAlpineStore = jest.fn();
const mockRestRequest = /** @type {jest.Mock} */ (restRequest);
const flushAsyncUi = () => new Promise((resolve) => setTimeout(resolve, 0));

const FOLDERS = [
    { id: 1, parentId: 0, name: 'Editorial', hasChildren: true },
    { id: 2, parentId: 1, name: 'Featured Images', hasChildren: false },
    { id: 3, parentId: 0, name: 'Videos', hasChildren: false },
    { id: 4, parentId: 0, name: 'Infographics', hasChildren: true },
    { id: 5, parentId: 4, name: 'Charts', hasChildren: false },
];



const FOLDERS_WITH_ROOT = [
    { id: 0, parentId: 0, name: 'Медиафайлы', hasChildren: false },
    { id: 157, parentId: 0, name: 'Несортированные', hasChildren: false },
    { id: 4, parentId: 0, name: 'Infographics', hasChildren: true },
];

const fieldHtml = (currentFolderId) => `
    <span class="plathix-folder-switch__field" data-attachment-id="8" data-taxonomy="plathix_folder" data-current-folder-id="${currentFolderId}">
        <span class="plathix-folder-switch__bar">
            <a href="http://example.test/upload.php?plathix_folder=${currentFolderId}" class="plathix-folder-switch__goto" target="_top">
                <span class="plathix-folder-switch__icon" aria-hidden="true"></span>
                <span class="plathix-folder-switch__name">Infographics</span>
                <span class="plathix-folder-switch__extlink" aria-hidden="true"></span>
            </a>
            <span class="plathix-folder-switch__divider" aria-hidden="true"></span>
            <button type="button" class="plathix-folder-switch__trigger" aria-expanded="false">
                <span class="plathix-folder-switch__trigger-label">Change</span>
                <span class="plathix-folder-switch__chevron" aria-hidden="true"></span>
            </button>
        </span>
    </span>
`;






const emptyFieldHtml = () => `
    <span class="plathix-folder-switch__field" data-attachment-id="9" data-taxonomy="plathix_folder" data-current-folder-id="0">
        <span class="plathix-folder-switch__bar">
            <span class="plathix-folder-switch__empty">— No folder —</span>
            <span class="plathix-folder-switch__divider" aria-hidden="true"></span>
            <button type="button" class="plathix-folder-switch__trigger" aria-expanded="false">
                <span class="plathix-folder-switch__trigger-label">Change</span>
                <span class="plathix-folder-switch__chevron" aria-hidden="true"></span>
            </button>
        </span>
    </span>
`;

describe('folder switch ui', () => {
    beforeEach(() => {
        jest.clearAllMocks();
        document.body.innerHTML = '';
        window.Alpine = { store: mockAlpineStore };




        bindFolderSwitchUi();
        __resetFolderSwitchCacheForTests();
    });

    it('opens popover and renders tree with all branches expanded by default', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest.mockResolvedValue({ folders: FOLDERS });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        const popover = document.querySelector('.plathix-folder-switch__popover');
        expect(popover).not.toBeNull();
        expect(document.querySelector('.plathix-folder-switch__field').classList.contains('is-open')).toBe(true);

        const rows = document.querySelectorAll('.plathix-folder-switch__tree-row');

        expect(rows.length).toBe(5);

        const currentRow = document.querySelector('.plathix-folder-switch__tree-row.is-current');
        expect(currentRow.dataset.folderId).toBe('4');
    });

    it('falls back to the default popover title when the translated string is an empty string', async() => {


        mockAlpineStore.mockReturnValue({ notify: jest.fn() });
        document.body.innerHTML = fieldHtml(4);
        window.Plathix = { ...window.Plathix, i18n: { folder_switch_move_to: '' } };

        mockRestRequest.mockResolvedValue({ folders: FOLDERS });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        const title = document.querySelector('.plathix-folder-switch__popover-title');
        expect(title.textContent).toBe('Move to folder');
    });

    it('closes popover on repeated trigger click (toggle)', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest.mockResolvedValue({ folders: FOLDERS });

        const trigger = document.querySelector('.plathix-folder-switch__trigger');
        trigger.click();
        await flushAsyncUi();
        await flushAsyncUi();
        expect(document.querySelector('.plathix-folder-switch__popover')).not.toBeNull();

        trigger.click();
        expect(document.querySelector('.plathix-folder-switch__popover')).toBeNull();
        expect(document.querySelector('.plathix-folder-switch__field').classList.contains('is-open')).toBe(false);
    });

    it('closes popover on outside click', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });
        document.body.innerHTML = fieldHtml(4) + '<div id="outside">outside</div>';

        mockRestRequest.mockResolvedValue({ folders: FOLDERS });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();
        expect(document.querySelector('.plathix-folder-switch__popover')).not.toBeNull();

        document.getElementById('outside').click();
        expect(document.querySelector('.plathix-folder-switch__popover')).toBeNull();
    });

    it('clicking the current folder row is a no-op (closes popover, no REST call)', async() => {
        mockAlpineStore.mockReturnValue({ notify: jest.fn() });
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest.mockResolvedValue({ folders: FOLDERS });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        mockRestRequest.mockClear();
        document.querySelector('.plathix-folder-switch__tree-row.is-current').click();

        expect(mockRestRequest).not.toHaveBeenCalled();
        expect(document.querySelector('.plathix-folder-switch__popover')).toBeNull();
    });

    it('clicking a different folder row moves the file and updates the goto zone with breadcrumb', async() => {
        const notify = jest.fn();
        const refreshFolders = jest.fn().mockResolvedValue(undefined);
        mockAlpineStore.mockReturnValue({ notify, refreshFolders });
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest
            .mockResolvedValueOnce({ folders: FOLDERS })
            .mockResolvedValueOnce({ moved: 1 });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        const targetRow = document.querySelector('.plathix-folder-switch__tree-row[data-folder-id="2"]');
        targetRow.click();
        await flushAsyncUi();
        await flushAsyncUi();

        expect(mockRestRequest).toHaveBeenLastCalledWith(
            'folders/2/items',
            expect.objectContaining({ method: 'PUT', data: { ids: [8], post_type: 'attachment' } })
        );

        const nameEl = document.querySelector('.plathix-folder-switch__name');
        expect(nameEl.textContent).toBe('Editorial / Featured Images');
        expect(document.querySelector('.plathix-folder-switch__field').dataset.currentFolderId).toBe('2');
        expect(document.querySelector('.plathix-folder-switch__popover')).toBeNull();
        expect(notify).toHaveBeenCalledWith('success', expect.any(String));



        expect(refreshFolders).toHaveBeenCalledWith({ silent: true });
    });

    it('shows error notice and keeps popover open when move_items fails', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest
            .mockResolvedValueOnce({ folders: FOLDERS })
            .mockRejectedValueOnce(Object.assign(new Error('Server error'), { code: null }));

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        document.querySelector('.plathix-folder-switch__tree-row[data-folder-id="2"]').click();
        await flushAsyncUi();
        await flushAsyncUi();

        expect(notify).toHaveBeenCalledWith('error', 'Server error');

        expect(document.querySelector('.plathix-folder-switch__popover')).not.toBeNull();
        expect(document.querySelector('.plathix-folder-switch__field').dataset.currentFolderId).toBe('4');
    });

    it('replaces the empty placeholder with a link when a file without a folder gets one assigned', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });
        document.body.innerHTML = emptyFieldHtml();

        mockRestRequest
            .mockResolvedValueOnce({ folders: FOLDERS })
            .mockResolvedValueOnce({ moved: 1 });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        document.querySelector('.plathix-folder-switch__tree-row[data-folder-id="3"]').click();
        await flushAsyncUi();
        await flushAsyncUi();

        expect(document.querySelector('.plathix-folder-switch__empty')).toBeNull();
        const goto = document.querySelector('.plathix-folder-switch__goto');
        expect(goto).not.toBeNull();
        expect(goto.querySelector('.plathix-folder-switch__name').textContent).toBe('Videos');
    });

    it('shows error notice when folder tree fails to load', async() => {
        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest.mockRejectedValue(Object.assign(new Error('Failed to load folders.'), { code: null }));

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        expect(notify).toHaveBeenCalledWith('error', expect.any(String));
        expect(document.querySelector('.plathix-folder-switch__popover')).toBeNull();
    });

    it('handles trash workflow consistently', async() => {



        const notify = jest.fn();
        mockAlpineStore.mockReturnValue({ notify });
        window.PlathixFolderSwitch = { ...window.PlathixFolderSwitch, uncategorizedTermId: 157 };
        document.body.innerHTML = fieldHtml(4);

        mockRestRequest
            .mockResolvedValueOnce({ folders: FOLDERS_WITH_ROOT })
            .mockResolvedValueOnce({ unassigned: 1, failed: 0 });

        document.querySelector('.plathix-folder-switch__trigger').click();
        await flushAsyncUi();
        await flushAsyncUi();

        const rootRow = document.querySelector('.plathix-folder-switch__tree-row[data-folder-id="0"]');
        rootRow.click();
        await flushAsyncUi();
        await flushAsyncUi();

        expect(mockRestRequest).toHaveBeenLastCalledWith(
            'items',
            expect.objectContaining({ method: 'DELETE', data: { item_ids: [8], post_type: 'attachment' } })
        );


        expect(document.querySelector('.plathix-folder-switch__field').dataset.currentFolderId).toBe('157');
        const nameEl = document.querySelector('.plathix-folder-switch__name');
        expect(nameEl.textContent).toBe('Несортированные');
        expect(notify).toHaveBeenCalledWith('success', expect.any(String));
    });
});
