# Lessons

## Don't chase dev-environment test failures as if they were regressions

**Pattern:** While fixing a prod bug report, the local Docker PHP image lacks the GD
extension, so ~30 image/media tests (`ImageEditApiTest`, `MediaApiTest`,
`MediaStorageServiceTest`) fail regardless of the change. I started investigating the
failure count instead of the reported bug.

**Rule:** Prove the *delta*, not the absolute count. Stash the change, run the same
suite, compare. Compare failure *identities* — which tests, which assertion, which
message — not just how many: an already-red test can start failing for a new reason and
mask a real regression behind an unchanged count. Same tests failing the same way before
and after = environment noise; state that once and move on. Never report an environment
failure as if it were caused by the change, and never let it derail the actual
investigation.

**Rule:** A user's bug report is about *their* environment. Reproduce against the code
path and the vendor API contract, not against local suite health.

## Scale the process to the work, not to the skill's ceremony

**Pattern:** Asked to apply ~15 small, well-understood code-review fixes, I ran the
subagent-driven-development skill by the book: ten task briefs, an implementer subagent
and a reviewer subagent each, review packages between them. Each subagent rebuilt context
from scratch and re-ran the full 700-test suite plus tsc/lint. Six tasks took ~45 minutes
for changes I could have written directly in a few minutes each. The user was rightly
annoyed.

**Rule:** Subagents pay off when a task needs context I don't already hold, or when work
is genuinely parallel. For a list of small fixes in files I have already read this
session, doing them inline is both faster and better — I already know why the code is
shaped the way it is. Batch the whole list, run the gate once at the end.

**Rule:** A skill describes a maximum-rigour path, not a mandatory one. Before invoking a
per-task implementer/reviewer loop, ask what each dispatch buys over an inline edit. If
the answer is "a second opinion on a three-line change," skip it. Reserve the full loop
for tasks with real design risk — and say up front which tasks get it and why.

**Rule:** Watch wall-clock, not just correctness. If a mechanical task is heading past
~10 minutes of tool time, stop and ask whether the process is the bottleneck.
