# multiflexi-realpad

Realpad CRM integration support for [MultiFlexi](https://multiflexi.eu).

## Description

This package provides a shared Realpad credential prototype for MultiFlexi,
so apps that talk to the Realpad API (e.g. `pohoda-realpad`,
`realpad2mailkit`) can reference one credential set instead of each
duplicating the same login fields.

## Credential Fields

- **REALPAD_USERNAME** — Realpad API username
- **REALPAD_PASSWORD** — Realpad API password

## Installation

### From source (development)

```bash
composer install
```

## License

MIT
