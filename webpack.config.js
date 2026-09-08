const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');


const webpack = require(require.resolve('webpack', {
    paths: [require.resolve('@wordpress/scripts/config/webpack.config')],
}));


const {
    assignDeterministicIds,
    getFullModuleName,
    getUsedModuleIdsAndModules,
} = require(require.resolve('webpack/lib/ids/IdHelpers', {
    paths: [require.resolve('@wordpress/scripts/config/webpack.config')],
}));




//



const BUNDLED_LICENSES = {
    // Alpine.js 3.16.1 — MIT, Caleb Porzio, https://alpinejs.dev
    alpine: '! @license Alpine.js v3.16.1 | (c) Caleb Porzio | MIT License | https://alpinejs.dev',
};


const BANNER_BY_ENTRY = {
    sidebar: [BUNDLED_LICENSES.alpine],
};






//


const minimizer = (defaultConfig.optimization?.minimizer || []).map((plugin) => {
    if (plugin?.constructor?.name !== 'TerserPlugin') {
        return plugin;
    }

    const options = plugin.options || {};




    const inner = options.minimizer?.options || {};

    return new plugin.constructor({
        test: options.test,
        include: options.include,
        exclude: options.exclude,
        parallel: options.parallel,
        extractComments: options.extractComments,
        minify: options.minimizer?.implementation,
        terserOptions: {
            ...inner,
            output: {
                ...inner.output,
                comments: /translators:|@license/i,
            },
        },
    });
});

const plugins = (defaultConfig.plugins || [])



    .filter((plugin) => plugin?.constructor?.name !== 'RtlCssPlugin')
    .map((plugin) => {
        if (plugin?.constructor?.name === 'MiniCssExtractPlugin') {
            return new plugin.constructor({
                ...plugin.options,
                filename: 'css/[name].css',
                chunkFilename: 'css/[name].css',
            });
        }

        return plugin;
    });

plugins.push(
    new webpack.BannerPlugin({
        banner: ({ chunk }) => {
            const lines = BANNER_BY_ENTRY[chunk.name];

            return lines ? lines.join('\n') : '';
        },
        entryOnly: false,



        test: /\.js$/,



    })
);













class RemoveEmptyJsEmitPlugin {
    apply(compiler) {
        compiler.hooks.thisCompilation.tap('RemoveEmptyJsEmitPlugin', (compilation) => {
            compilation.hooks.processAssets.tap(
                {
                    name: 'RemoveEmptyJsEmitPlugin',
                    stage: webpack.Compilation.PROCESS_ASSETS_STAGE_REPORT,
                },
                (assets) => {
                    for (const name of Object.keys(assets)) {
                        if (name.endsWith('.js') && assets[name].size() === 0) {
                            compilation.deleteAsset(name);
                        }
                    }
                }
            );
        });
    }
}

plugins.push(new RemoveEmptyJsEmitPlugin());


















//







//










class StableModuleIdsPlugin {
    apply(compiler) {
        compiler.hooks.compilation.tap('StableModuleIdsPlugin', (compilation) => {
            compilation.hooks.moduleIds.tap('StableModuleIdsPlugin', () => {
                const chunkGraph = compilation.chunkGraph;
                const context = compiler.context;
                const [usedIds, modules] = getUsedModuleIdsAndModules(compilation);

                const getStableName = (module) => {
                    const fullName = getFullModuleName(module, context, compiler.root);
                    const nodeModulesIndex = fullName.lastIndexOf('node_modules/');
                    if (nodeModulesIndex === -1) {
                        return fullName;
                    }
                    return fullName.slice(nodeModulesIndex);
                };

                assignDeterministicIds(
                    modules,
                    getStableName,



                    (a, b) => {
                        const nameA = getStableName(a);
                        const nameB = getStableName(b);
                        if (nameA < nameB) return -1;
                        if (nameA > nameB) return 1;
                        return 0;
                    },
                    (module, id) => {
                        const size = usedIds.size;
                        usedIds.add(`${id}`);
                        if (size === usedIds.size) {
                            return false;
                        }
                        chunkGraph.setModuleId(module, id);
                        return true;
                    },
                    [10 ** 3],
                    10,
                    usedIds.size,
                    0
                );
            });
        });
    }
}

plugins.push(new StableModuleIdsPlugin());

module.exports = {
    ...defaultConfig,
    optimization: {
        ...defaultConfig.optimization,


        moduleIds: false,
        minimizer,
    },
    entry: {
        'admin-ui': path.resolve(__dirname, 'resources/js/admin-ui.js'),



        'admin-ui/preset': path.resolve(__dirname, 'src/Modules/Preset/assets/preset.js'),



        'admin-ui/settings': path.resolve(__dirname, 'src/Modules/Settings/assets/settings.js'),



        'admin-ui/dashboard': path.resolve(__dirname, 'src/Modules/Dashboard/assets/dashboard.js'),



        'admin-ui/system-info': path.resolve(__dirname, 'src/Modules/SystemInfo/assets/system-info.js'),
        'admin-menu': path.resolve(__dirname, 'resources/js/admin-menu.js'),




        'lib/escape-shared': path.resolve(__dirname, 'resources/js/lib/escape-shared.js'),




        'lib/transport-shared': path.resolve(__dirname, 'resources/js/lib/transport-shared.js'),
        sidebar: path.resolve(__dirname, 'resources/js/sidebar/index.js'),


        import: path.resolve(__dirname, 'resources/js/import/index.js'),
        'media-upload': path.resolve(__dirname, 'resources/js/media-upload.js'),


        'replace-media': path.resolve(__dirname, 'resources/js/replace/standalone.js'),


        'folder-switch': path.resolve(__dirname, 'resources/js/folder-switch/standalone.js'),

        'search': path.resolve(__dirname, 'resources/js/sidebar/search-entry.js'),



        'color': path.resolve(__dirname, 'resources/js/sidebar/color/color-entry.js'),



        'trash': path.resolve(__dirname, 'resources/js/sidebar/trash/trash-entry.js'),



        'favorites': path.resolve(__dirname, 'resources/js/sidebar/favorites/favorites-entry.js'),




        'free-wizard': path.resolve(__dirname, 'resources/js/free-wizard/standalone.js'),




        'propage': path.resolve(__dirname, 'resources/js/propage/standalone.js'),





        'tools': path.resolve(__dirname, 'resources/js/tools/standalone.js'),


    },
    output: {
        ...defaultConfig.output,
        path: path.resolve(__dirname, 'assets'),
        filename: 'js/[name].js',
        chunkFilename: 'js/[name].js',
        clean: {





            keep: /^(fonts|img|presets)\//,
        },
    },
    plugins,
};
