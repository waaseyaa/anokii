# Release and split-publication procedure

Anokii has two deliberately separate publication paths. Neither is implied by
merging a pull request.

## Development package splits

The **Split selected package main branches** workflow may publish only the
allowlisted `core`, `identity`, and `operator` package directories to their
corresponding split repositories. A dispatcher supplies:

- the exact 40-character SHA currently at Anokii `main`;
- the reviewed package names; and
- a durable reason or issue URL.

The workflow requires an authorized actor, the successful `Quality` workflow
for that exact SHA, existing literal destination repositories, and a
force-with-lease update. It writes development `main` only. It has no authority
to create tags, GitHub releases, or Packagist releases.

Consumers must lock the reviewed split commit. `dev-main` selects a development
line; it is not permission to deploy a moving reference.

## Alpha distribution tags

An alpha tag is a separately authorized maintainer operation. Start from a clean
checkout of the exact current remote `main`, then run:

```bash
git fetch origin main --tags
git switch --detach origin/main
composer install --no-interaction --prefer-dist
composer validate --strict
composer audit --locked --no-interaction
composer check
```

Confirm the `Quality` workflow succeeded for the same full commit SHA and that
`CHANGELOG.md` describes the intended release. Verify the proposed tag does not
already exist locally or remotely. Only after explicit tag authorization:

```bash
git tag -a v0.1.0-alpha.N -m "Anokii v0.1.0-alpha.N" <full-main-sha>
git push origin refs/tags/v0.1.0-alpha.N
```

Use an annotated signed tag when signing is available. Never move or overwrite
an existing tag. Packagist publication, if enabled, must index that exact tag;
it is not performed by the split workflow. Record the tag, source SHA, green
workflow URL, and any Packagist result in the release evidence.

Do not create a tag merely because package `main` was split. Do not backfill a
tag for a package that did not exist in the tagged monorepo tree.
