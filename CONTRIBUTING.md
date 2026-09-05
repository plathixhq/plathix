# Contributing to Plathix

Thanks for your interest in contributing.

## Setup

```bash
git clone https://github.com/plathixhq/plathix.git
cd plathix
composer install
npm install
```

Requirements: PHP >= 8.1, Node.js (see `.wp-env.json` if present, or use a recent LTS).

## Build

```bash
npm run build
```

This compiles `resources/css/` and `resources/js/` into `assets/css/` and `assets/js/`
(both git-ignored — they are build output, not source).

## Tests

```bash
composer test
npm run test:unit:js
```

## Not for production

Code built from this repository at an arbitrary commit is **not recommended for use in a
production environment**. Use a tagged release and the packaged plugin zip attached to the
corresponding [GitHub Release](https://github.com/plathixhq/plathix/releases) instead.

## Documentation

User-facing plugin documentation lives in `docs/user/` in this repository (when present) or
on the [Plathix website](https://plathix.com). `readme.txt` follows the standard WordPress
plugin readme format.

## Pull requests

- Keep changes focused — one concern per pull request.
- Run the local build and test commands above before submitting.
- Describe what changed and why in the PR description.
