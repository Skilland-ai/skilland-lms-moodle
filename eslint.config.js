const globals = require('globals')

const commonRules = {
  'no-unused-vars': ['warn', { argsIgnorePattern: '^_', varsIgnorePattern: '^_', caughtErrors: 'none' }],
  'no-undef': 'error',
  semi: ['error', 'never'],
  quotes: ['error', 'single', { avoidEscape: true }],
}

module.exports = [
  {
    files: ['**/*.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'commonjs',
      globals: {
        ...globals.node,
        ...globals.es2021,
        M: 'readonly',
        document: 'readonly',
      },
    },
    rules: commonRules,
  },
  {
    files: ['src/amd/src/**/*.js'],
    languageOptions: {
      sourceType: 'script',
      globals: {
        ...Object.fromEntries(Object.keys(globals.node).map((k) => [k, 'off'])),
        ...globals.es2021,
        ...globals.browser,
        ...globals.amd,
        M: 'readonly',
      },
    },
    rules: {
      ...commonRules,
      semi: ['error', 'always'],
    },
  },
]
