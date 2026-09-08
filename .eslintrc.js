module.exports = {
    env: {
        browser: true,
        es2022: true,
    },
    parserOptions: {
        ecmaVersion: 'latest',
        sourceType: 'module',
        ecmaFeatures: {
            jsx: true,
        },
    },
    rules: {
        camelcase: 'off',
    },
};
