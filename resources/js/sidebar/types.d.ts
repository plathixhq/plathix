/**
 * @typedef {Object} WPCFolder
 * @property {number}  id          - Numeric post ID (term ID).
 * @property {string}  name        - Display name.
 * @property {number}  parentId    - Parent folder ID; 0 = root.
 * @property {number}  count       - Number of items assigned to this folder.
 * @property {string}  color       - Hex colour string, e.g. "#4caf50", or empty string.
 * @property {boolean} isProtected - True for system folders (Uncategorized, All).
 * @property {number}  position    - Sort weight; lower = first.
 */

/**
 * @typedef {Object} WPCNotification
 * @property {number} id      - Auto-incrementing local ID.
 * @property {'success'|'error'|'info'} type
 * @property {string} message - Human-readable text.
 */

/**
 * @typedef {Object} WPCFolderDropPos
 * @property {'before'|'inside'|'after'} pos
 */

/**
 * @typedef {Object} WPCCaps
 * @property {boolean} canView
 * @property {boolean} canAssign
 * @property {boolean} canManage
 * @property {boolean} canZipDownload
 */

/**
 * @typedef {Object} WPCRuntime  - window.Plathix shape
 * @property {WPCFolder[]}  folders
 * @property {number}       openId
 * @property {WPCCaps}      caps
 * @property {number}       depthLimit
 * @property {string}       restUrl
 * @property {string}       restNonce
 * @property {string}       ajaxUrl
 * @property {string}       postType
 * @property {string}       screenBase
 * @property {string}       screenKind
 * @property {string}       mediaMode
 * @property {string}       filterStrategy
 * @property {boolean}      mediaModalOnly
 * @property {boolean}      isStaticLibraryScreen
 * @property {boolean}      isTouch
 * @property {Record<string,string>} i18n
 */

/**
 * @typedef {Object} WPCFileEntry
 * @property {File}   file
 * @property {string} relativePath - Path relative to the dragged root directory.
 */

// ── Ambient declarations for TypeScript (checkJs) ────────────────────────────

declare type PlathixI18nKey = string;

declare interface PlathixFolder {
    id: number;
    name: string;
    parentId: number;
    count: number;
    color: string;
    isProtected: boolean;
    position: number;
    [key: string]: unknown;
}

declare interface PlathixRuntime {
    folders: PlathixFolder[];
    openId: number;
    openFolderId?: number;
    trashFolderId?: number;
    caps: { canView: boolean; canAssign: boolean; canManage: boolean; canZipDownload: boolean };
    depthLimit: number;
    restUrl: string;
    restNonce: string;
    nonce: string;
    ajaxUrl: string;
    ajaxurl: string;
    wpMediaUrl?: string;
    postType: string;
    screenBase: string;
    screenKind: string;
    mediaMode: string;
    filterStrategy: string;
    mediaModalOnly: boolean;
    isStaticLibraryScreen: boolean;
    isTouch: boolean;
    searchOnlyAt?: string;
    features?: Record<string, boolean>;
    imageSizes?: string[];
    bulkSafeMode?: boolean;
    removeFromAll?: boolean;
    deferFoldersBootstrap?: boolean;
    bootstrapLoadedParents?: number[];
    favorites?: number[];
    i18n: Record<string, string>;
    [key: string]: unknown;
}

declare interface PlathixMediaSelection {
    get(key: string): unknown;
    set(key: string, value: unknown): this;
    on(event: string, callback: (...args: unknown[]) => void): this;
    off(event: string, callback?: (...args: unknown[]) => void): this;
    reset(models?: unknown[], options?: unknown): this;
    models: PlathixWpMediaItem[];
    [key: string]: unknown;
}

declare interface PlathixMediaLibrary {
    get(key: string): PlathixWpMediaItem & PlathixMediaSelection & PlathixMediaLibrary & { id?: number; collection?: PlathixWpCollection; [key: string]: unknown };
    set(key: string, value: unknown): this;
    on(event: string, callback: (...args: unknown[]) => void): this;
    off(event: string, callback?: (...args: unknown[]) => void): this;
    fetch(options?: unknown): unknown;
    reset(models?: unknown[], options?: unknown): this;
    hasMore?(): boolean;
    more?(): { always?: (cb: () => void) => void } | undefined;
    props: PlathixWpProps;
    models: PlathixWpMediaItem[];
    model?: unknown;
    frame?: unknown;
    [key: string]: unknown;
}

declare interface PlathixWpMediaItem {
    id?: number;
    toJSON?(): Record<string, unknown>;
    props?: PlathixWpProps;
    [key: string]: unknown;
}

declare interface PlathixWpProps {
    get(key: string): unknown;
    set(key: string, value: unknown): this;
    toJSON(): Record<string, unknown> & { query?: { props?: PlathixWpProps }; [key: string]: unknown };
    [key: string]: unknown;
}

declare interface PlathixWpCollection {
    fetch(options?: unknown): unknown;
    reset(models?: unknown[], options?: unknown): this;
    hasMore?(): boolean;
    more?(): { always?: (cb: () => void) => void } | undefined;
    props?: PlathixWpProps;
    [key: string]: unknown;
}

declare interface PlathixWpMediaContent {
    get(key?: string): PlathixWpMediaContent & { collection?: PlathixWpCollection; [key: string]: unknown };
    collection?: PlathixWpCollection;
    [key: string]: unknown;
}

declare interface PlathixWpMediaFrame extends PlathixMediaLibrary {
    state(id?: string): PlathixWpMediaFrame & {
        get(key: string): PlathixMediaSelection & PlathixMediaLibrary & { id?: number; collection?: PlathixWpCollection; [key: string]: unknown };
    };
    content: PlathixWpMediaContent;
    trigger(event: string, ...args: unknown[]): this;
}

declare interface WpMediaAttachmentsPrototype {
    sync: (...args: unknown[]) => unknown;
    props?: PlathixWpProps & { toJSON(): Record<string, unknown> };
    _query?: { props?: PlathixWpProps };
    [key: string]: unknown;
}

declare interface WpMediaModel {
    Attachments?: {
        prototype: WpMediaAttachmentsPrototype & {
            _requery?: (...args: unknown[]) => unknown;
            _plathixRequeryPatched?: boolean;
            observers?: unknown[];
            observe?: (queue: unknown) => void;
        };
        [key: string]: unknown;
    };
    Query?: {
        prototype: Record<string, unknown>;
        [key: string]: unknown;
    };
    [key: string]: unknown;
}

declare interface JQueryResult {
    trigger(event: string, ...args: unknown[]): this;
    on(event: string, callback: (...args: unknown[]) => void): this;
    [key: string]: unknown;
}

declare interface WpMedia {
    (options?: unknown): PlathixWpMediaFrame;
    frame: PlathixWpMediaFrame;
    model: WpMediaModel;
    query?: (args?: unknown) => PlathixWpCollection;
    on(event: string, callback: (...args: unknown[]) => void): void;
    [key: string]: unknown;
}

declare interface WpUploaderQueue {
    on(event: string, callback: (...args: unknown[]) => void): void;
    [key: string]: unknown;
}

declare interface WpUploader {
    queue?: WpUploaderQueue;
    [key: string]: unknown;
}

declare interface WpGlobal {
    media: WpMedia;
    Uploader?: WpUploader;
    uploader?: WpUploader;
    hooks?: {
        doAction(hook: string, ...args: unknown[]): void;
        addAction(hook: string, namespace: string, callback: (...args: unknown[]) => void, priority?: number): void;
        [key: string]: unknown;
    };
    [key: string]: unknown;
}

declare interface AlpineGlobal {
    store(name: string): Record<string, unknown> | undefined;
    store(name: string, value: unknown): void;
    data(name: string, callback: (...args: unknown[]) => unknown): void;
    start(): void;
    [key: string]: unknown;
}

declare interface Window {
    Plathix?: PlathixRuntime;
    PlathixApi?: Record<string, unknown>;
    wp: WpGlobal;
    Alpine?: AlpineGlobal;
    __PlathixState: unknown;
    __PlathixApiReady?: boolean;
    jQuery: ((...args: unknown[]) => JQueryResult) & Record<string, unknown>;
    ajaxurl: string;
}

declare var wp: WpGlobal;

interface XMLHttpRequest {
    _isWpUpload?: boolean;
    _isAjaxUpload?: boolean;
}

interface EventTarget {
    closest?(selector: string): Element | null;
    dataset?: DOMStringMap;
    id?: string;
    value?: string;
    _x_dataStack?: unknown[];
}
