const DEFAULT_COLOR = '#2271b1';
const HEX6 = /^[0-9a-f]{6}$/;



export function colorPickerComponent() {
    return {






        color: DEFAULT_COLOR,

        get _folderId() {
            return Number(this.$store.plathix.contextMenuFolderId) || 0;
        },

        get _folder() {
            const id = this._folderId;
            return id > 0 ? this.$store.plathix.folders.find((f) => Number(f.id) === id) : null;
        },

        
        get hasColor() {
            return !!this._folder?.color;
        },

        

        syncFromStore() {




            if (this._folderId === 0) {
                return;
            }
            const c = this._folder?.color;
            this.color = HEX6.test(String(c || '').replace(/^#/, '').toLowerCase()) ? c : DEFAULT_COLOR;
        },

        

        set(value) {
            const hex = String(value || '').trim().replace(/^#/, '').toLowerCase();
            const id = this._folderId;
            if (HEX6.test(hex) && id > 0) {
                this.color = '#' + hex;
                this.$store.plathix.setFolderColor(id, '#' + hex);
            }
        },
    };
}
