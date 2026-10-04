# Contribution Guide

Thank you for considering contributing to Agentio! Please review the following guidelines before submitting a pull request.

For significant changes, please open an issue first so we can discuss the approach.

## Process

1. Fork the project
2. Create a new branch
3. Code, test, commit, and push
4. Open a pull request detailing your changes

## Guidelines

- Ensure the coding style passes by running `composer lint`.
- Send a coherent commit history, making sure each commit in your pull request is meaningful.
- You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.
- Please remember that we follow [SemVer](http://semver.org/).

## Setup

Clone your fork, then install the dev dependencies:

```bash
composer install
```

## Lint

Lint your code:

```bash
composer lint
```

## Tests

Run all tests:

```bash
composer test
```

## Releasing

Releases are git tags on `main` (`vMAJOR.MINOR.PATCH`, [SemVer](http://semver.org/); while the version is `0.x` a minor release may break things). Composer reads the tags from GitHub, so a pushed tag is all a host project needs; there is no version in `composer.json`.

1. Make sure `main` is green: `composer test` locally and the `tests` workflow on GitHub.
2. In `CHANGELOG.md`, rename `## [Unreleased]` to the new version with today's date, link it to its release page and add an empty `Unreleased` section above it:

   ```markdown
   ## [Unreleased](https://github.com/Alexxosipov/agentio/compare/v0.2.0...main)

   ## [v0.2.0](https://github.com/Alexxosipov/agentio/releases/tag/v0.2.0) - 2026-11-01
   ```

3. Commit and tag:

   ```bash
   git commit -am "Release v0.2.0"
   git tag -a v0.2.0 -m "v0.2.0"
   git push origin main v0.2.0
   ```

The `release` workflow (`.github/workflows/release.yml`) then checks that the tag is on `main` and that the changelog has a section for it, runs `composer test` and publishes the GitHub release with the notes of that section. If it fails, fix `main`, delete the tag (`git push origin :v0.2.0 && git tag -d v0.2.0`) and tag again — but never move a tag that a release has already been published from: Composer caches it.
