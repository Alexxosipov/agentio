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

## Configuration

Set the YouTrack connection in `.env` (the token is only read from the environment):

```dotenv
YOUTRACK_URL=https://example.youtrack.cloud
YOUTRACK_TOKEN=
AGENTIO_PROJECT=TP
```

Other settings (`AGENTIO_BASE_BRANCH`, `AGENTIO_MERGE_POLICY`, `AGENTIO_MAX_PARALLEL`, `AGENTIO_MAX_PARALLEL_TASKS`, `AGENTIO_INTERVAL`, `AGENTIO_WORKTREES_PATH`, `AGENTIO_CLAUDE_BIN`, `AGENTIO_UI_*`) are documented in `config/agentio.php`. When `AGENTIO_MERGE_POLICY` is empty, the `MERGE_POLICY:` line of the project's `CLAUDE.md` is used.

## Dashboard Authorization

The dashboard is served at `/agentio`. In the `local` environment everyone may open it; elsewhere only the emails listed in `AGENTIO_ALLOWED_EMAILS` (comma separated) may. Define your own `viewAgentio` gate, or replace the check entirely:

```php
use Illuminate\Http\Request;
use Obrazmisli\Agentio\Agentio;

Agentio::auth(fn (Request $request): bool => $request->user()?->isAdmin() === true);
```

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
