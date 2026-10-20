# Admin authentication maintenance candidate

Status: the maintainer explicitly approved publication as v0.1.0-alpha.14 on 2026-10-20 and subsequent governed RHT Circle qualification/deployment. The authorization is recorded in the originating task.

This branch starts at published Anokii v0.1.0-alpha.13 (a7b02fc17c1b2ad6c30ed5f1152ec01b52f34344). It backports the shared authentication repair from upstream issue #47 / PR #48 for existing embedded-distribution consumers on Framework alpha.305. Main is undergoing a larger package split; importing that distribution into an existing host would activate unrelated providers and entities.

The backport uses the framework-owned AuthManager and HTTP principal. It carries the existing upstream AccountBoundary and LoginThrottle helpers. AdminShell accepts an audited UserSessionSnapshot while retaining its released argument order; legacy workspace callers retain their public API. It does not copy a framework authentication, permission or session engine into a host.

Regression tests use real sealed User objects, audited readers, the framework authentication policy and database rate limiter. They cover valid and invalid login, inactive and ineligible accounts, permission denial, session generation/revocation/rotation, logout and return links. The remaining legacy workspace surface is not being migrated to the new standalone distribution in this patch.

The explicit maintenance authorization is an exception because current main's docs/releasing.md only permits alpha tags from current main. Approved exception: publish the reviewed maintenance commit as v0.1.0-alpha.14 after upstream and maintenance checks pass, then qualify the consumer against that published package and promote through its existing release procedure. Do not move an existing tag, merge this old distribution tree into main, or deploy a local vendor patch.
