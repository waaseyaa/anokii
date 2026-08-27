---
name: Integration defect
about: Report incorrect behavior at an Anokii consumer boundary
labels: bug
---

## Consumer outcome

<!-- What user or operator outcome failed? -->

## Consumed projection

<!-- Select every package involved. The split repositories are read-only projections; changes are authored in this monorepo. -->

- [ ] `waaseyaa/anokii-core`
- [ ] `waaseyaa/anokii-identity`
- [ ] `waaseyaa/anokii-operator`
- [ ] Standalone `waaseyaa/anokii` distribution

## Exact locked commit

<!-- Required: paste the immutable commit for each selected package from composer.lock. A dev-main constraint or version range does not identify the code under test. -->

| Package | Exact source commit | Verification command or lockfile field |
| --- | --- | --- |
| `waaseyaa/anokii-*` | 40-character commit | `composer show -l ...` or `composer.lock` source.reference |

## Ownership claim

<!-- Which side owns the failing behavior: Anokii projection, Anokii distribution, consuming site, or Framework? Cite the contract or call site that establishes the boundary. -->

## Reproduction

<!-- Give the smallest deterministic reproduction using synthetic or redacted data. -->

1.
2.
3.

## Expected behavior


## Actual behavior


## Evidence

<!-- Logs, test output, screenshots, and file/symbol references. Separate observations from inference. -->

## Impact and safe fallback

<!-- State affected consumers and whether the boundary fails closed. Do not propose a consumer-side copy of Anokii behavior. -->
