# Lessons

## Don't chase dev-environment test failures as if they were regressions

**Pattern:** While fixing a prod bug report, the local Docker PHP image lacks the GD
extension, so ~30 image/media tests (`ImageEditApiTest`, `MediaApiTest`,
`MediaStorageServiceTest`) fail regardless of the change. I started investigating the
failure count instead of the reported bug.

**Rule:** Prove the *delta*, not the absolute count. Stash the change, run the same
suite, compare. Same failures before and after = environment noise; state that once and
move on. Never report an environment failure as if it were caused by the change, and
never let it derail the actual investigation.

**Rule:** A user's bug report is about *their* environment. Reproduce against the code
path and the vendor API contract, not against local suite health.
