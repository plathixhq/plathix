import { Api } from '../api.js';
import { cacheInvalidateFolder } from '../static-list/cache.js';



export const colorEditModule = {
    async setFolderColor(id, color) {





        const idx = this.folders.findIndex((f) => Number(f.id) === Number(id));
        const prevColor = idx !== -1 ? this.folders[idx].color : null;
        if (idx !== -1) {
            this.folders[idx].color = color;
        }
        try {
            await this.withLoading(async () => {
                await Api.setFolderColor(id, color);
                cacheInvalidateFolder(id);



                this.refreshFolders({ silent: true, skipCacheClear: true }).catch(() => {});
            }, { rethrow: true });
        } catch (error) {

            if (idx !== -1 && this.folders[idx] && Number(this.folders[idx].id) === Number(id)) {
                this.folders[idx].color = prevColor;
            }
            throw error;
        }
    },
};
