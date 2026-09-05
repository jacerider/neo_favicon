# Contributing

## Working on this package

Sites that use neo_favicon install it via Composer with
`preferred-install: source`, which checks out this repository directly into
the site rather than copying a packaged release. If you're contributing,
clone this repository (or work in the checkout Composer created) and commit
against it directly, rather than editing a vendor-style copy that isn't
meant to hold changes.

## Branching

Base new work on the `develop` branch.

## Commit messages

Commits follow [Conventional Commits 1.0.0](https://www.conventionalcommits.org/en/v1.0.0/)
with a leading gitmoji, e.g. `✨ feat: add support for maskable icons`.

## Releases

Releases and tags are cut by the maintainer. Contributors should not tag or
publish releases themselves.
