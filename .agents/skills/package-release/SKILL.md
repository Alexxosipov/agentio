---
name: package-release
description: "Use this skill when preparing Laravel package releases: CHANGELOG.md updates, generated release notes, GitHub release workflows, version checks, tags, release validation, or release automation changes. Never publish autonomously."
license: MIT
metadata:
  author: laravel
---

# Package Release

## Primary Goal

Prepare a safe package release checklist and implementation without tagging, pushing, or publishing unless the user explicitly approves that action.

## Workflow

1. Review `CHANGELOG.md`, generated release notes config, GitHub release workflows, open diff, and pending package changes.
2. Validate the release state with `composer test` before recommending a release (the only full test run; specific tests run through `vendor/bin/pest`).
3. Confirm whether version metadata needs to change: this package relies on Git tags (`vMAJOR.MINOR.PATCH` on `main`), not a version in `composer.json`; host projects install it from the GitHub repository as a `vcs` repository.
4. Review tag naming, release branch, and GitHub release workflow behavior before any release command: pushing a `v*` tag runs `.github/workflows/release.yml`, which needs a `## [vX.Y.Z]` section in `CHANGELOG.md` and publishes it as the release notes. The steps are in the Releasing section of `.github/CONTRIBUTING.md`.
5. Do not tag, push, or publish without explicit user approval.

## References

- `CHANGELOG.md`
- `.github/release.yml`
- `.github/workflows/release.yml`
- `.github/CONTRIBUTING.md`
- `.github/workflows/tests.yml`
- `composer.json`

## Examples

- Prepare a release by checking changelog coverage, confirming generated release notes categories, running `composer test`, and drafting the tag command for user approval.
- Update release notes grouping in `.github/release.yml` (used when a release is drafted by hand in the GitHub UI) when a new label convention is added.

## Anti-Patterns

- Creating tags, pushing branches, or publishing releases without explicit approval.
- Skipping `composer test` before a release recommendation.
- Treating generated release notes as a replacement for meaningful `CHANGELOG.md` entries.
- Changing release workflows without checking the supported matrix in `.github/workflows/tests.yml`.
