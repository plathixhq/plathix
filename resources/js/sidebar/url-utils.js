export function stripInitialFolderParamForMediaFrame() {
    const initUrl = new URL(window.location.href);
    const hadFolderParam = initUrl.searchParams.has('plathix_folder');
    if (!hadFolderParam) {
        return;
    }

    initUrl.searchParams.delete('plathix_folder');
    history.replaceState(null, '', initUrl.toString());
}
