Closes # <!-- Or "Part of #N" for one bounded slice. -->

## Outcome


## Ownership boundary

<!-- Why is this Anokii-owned rather than Framework- or consumer-owned? -->

## Split projections moved

<!-- Check every projection whose main branch must move when this merges. -->

- [ ] `anokii-core`
- [ ] `anokii-identity`
- [ ] `anokii-operator`
- [ ] None

## Verification

<!-- Paste commands actually run and summarize output. -->

- [ ] `composer validate --strict`
- [ ] `composer test`
- [ ] `composer analyse`
- [ ] `composer style`

Additional consumer or integration evidence:

## Dependency evidence

- Exact Framework commit or released cohort used, if relevant:
- Exact consumer package locks used, if relevant:

## Data and custody

<!-- State whether fixtures are synthetic and whether any private, tenant, workforce, or production data enters the change. -->

## Friction

<!-- Record new friction or link its tracking issue. Write "None" when none was encountered. -->

## Checklist

- [ ] The PR title includes the linked issue number.
- [ ] Changes to split projections are authored only in this monorepo.
- [ ] No consumer application behavior is copied into Anokii.
- [ ] No release or deployment is included unless the linked issue explicitly authorizes it.
