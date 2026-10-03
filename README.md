<div align="center">
    <h1>Agentio</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://img.shields.io/packagist/v/obrazmisli/agentio.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://img.shields.io/packagist/php-v/obrazmisli/agentio.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://badge.laravel.cloud/badge/obrazmisli/agentio?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/obrazmisli/agentio/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/obrazmisli/agentio/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/obrazmisli/agentio"><img src="https://img.shields.io/packagist/dt/obrazmisli/agentio.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Autonomous agentic development cycle for Laravel: YouTrack as the source of truth, Claude Code as PM, analyst, architect, developer and reviewer.

## Installation

You can install the package via Composer:

```bash
composer require obrazmisli/agentio
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="agentio"
```

Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="agentio-config"
```

### Publishing the Views

```bash
php artisan vendor:publish --tag="agentio-views"
```

## Usage

<!-- Add a basic usage example here. -->

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Agentio! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [Alexxosipov](https://github.com/obrazmisli)
- [All Contributors](../../contributors)

## License

Agentio is open-sourced software licensed under the [MIT license](LICENSE.md).
