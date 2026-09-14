import { Api } from '../api.js';
import { t } from '../i18n.js';
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
            
            
            
            
            if (error?.code === 'rest_write_indeterminate') {
                this.error = t('rest_write_indeterminate', 'The server accepted the request, but the response could not be read. Refreshing to confirm the result.');
                this.refreshFolders({ silent: true, skipCacheClear: true }).catch(() => {});
                this.notify('info', this.error);
                return;
            }
            
            if (idx !== -1 && this.folders[idx] && Number(this.folders[idx].id) === Number(id)) {
                this.folders[idx].color = prevColor;
            }
            throw error;
        }
    },
};
