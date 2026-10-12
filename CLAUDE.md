# platform-gate — Claude Code rules

## Project Overview

`funnelchat20/platform-gate` is the Laravel package every Funnelchat domain mounts on its public API. It does
two jobs and nothing else:

- **The gate:** classifies the caller (`api_key`, `web`, `unverified`), checks the ability a route requires
  (`{domain}:{grade}`, `send` ⇒ `write` ⇒ `read`, plus `operate`), applies the daily call cap per `key_id` and
  writes one `api_call_log` row per call. It reads `personal_access_tokens` directly, with no cache, so a
  revoked key fails on the next request.
- **The webhook standard:** `Event` (envelope `{id, type, version, occurred_at, data}`), `EventName`,
  `Signature` (`X-Funnelchat-Signature: t=…,v1=…`) and `RetryPolicy`.

It is **not** a domain: no database of its own, no container, no routes, no queues. In the workspace map it is
a **component** (`kind: library`): work arrives through `fcdev delegate platform-gate` in an isolated worktree,
and ships through `fcdev deliver platform-gate`.

Proprietary software (see `LICENSE`).

## Who depends on this

| Consumer | Constraint today | How it upgrades |
|---|---|---|
| accounts | `0.6.0` (pinned) | its own `fcdev delegate accounts` + `deliver` |
| communities | `0.6.0` (pinned) | its own `fcdev delegate communities` + `deliver` |
| conversations | `^0.6` | its own `fcdev delegate conversations` + `deliver` |
| super-agents (planned) | Python port of the gate | shares this repo's test vectors |

A change here does nothing until each consumer upgrades. Never change a consumer from this repo.

## Non-Negotiable Execution Rules

1. **Fail closed.** When the gate cannot decide (unknown `kind`, missing ability, unreadable config), it
   denies. Never turn a denial into an allow to make a test or a consumer pass.
2. **Every behaviour change ships as a new version.** Tags are `vMAJOR.MINOR.PATCH`. While in `0.x`: a change
   that can break a consumer bumps MINOR, a compatible fix bumps PATCH. Never move, delete or reuse a tag.
3. **Every release carries a compatibility note** in `README.md`, as a section «Actualizar desde <versión
   anterior>» like the existing «Actualizar desde 0.5»: what changed, whether consumers must change config or
   code, and in which order to upgrade.
4. **Tests for every behaviour change.** `composer install && vendor/bin/phpunit` must pass. No network in
   tests. Security-relevant paths (abilities, cap, `kind`, signature, edge secret) get a test for the deny path,
   not only the allow path.
5. **Shared test vectors are a contract.** Signature and gate vectors consumed by other implementations (the
   Python gate, the portal's signature snippets) change only with a version bump and a note.
6. **No secrets in code, fixtures or logs.** Secrets come from the consuming app's config/env.
7. **No `git push`, no `gh pr create`, no tags pushed** without the Issue–PR Gate and the dev's explicit
   approval.

## Language

- Always respond in Spanish. Technical terms and code identifiers stay in English.
- Commit messages and PR descriptions in English. README and user-facing docs in Spanish, with correct accents.
- The company is written «Funnelchat».

## Git — branch creation

Every new branch starts from a freshly pulled `main` (`git checkout main && git pull origin main && git checkout -b <name>`).
A worker in a worktree verifies its base against `origin/main` and says which SHA it worked on.

## Issue–PR Gate

**Mandatory before `gh pr create`, in every delivery path this repo has** — bug fix, hotfix, feature
(fast lane included) and `/opsx:apply`. The linked GitHub issue — not the PR, not the commit — is what
the PO, the support team and the reporter read to learn what was happening or needed, what was done, how
it works now and how to see it. A PR opened without passing this gate is a doctrine violation, not a
shortcut. Urgency does not exempt it: a production incident is exactly the change support most needs to
be able to explain.

The full procedure, the comment format and the language rules live in the **`issue-pr-gate` skill**
(plugin from the `funnelchat` marketplace, installed by `fcdev claude-setup`). Invoke it the moment the
user asks for the PR. This section is the contract; the skill is the how. If the skill is not available
in the session, warn the user and run the outline below by hand — never skip it.

1. **Find** the issue: branch name → ticket ID in the conversation or commits (a JSM ticket is a find
   signal, not a substitute) → an issue the user named → `gh issue list --search "<keywords>" --state open`.
   Verify the candidate with `gh issue view`. Never open a duplicate.
2. **Ask, never guess.** None found → `AskUserQuestion` with three options: create a new issue now /
   link an existing one / no user-visible impact (exemption). Never create or skip silently. No answer →
   no PR.
3. **Draft the plain-language summary** from the session's own context, in the reporter's language
   (Spanish for Funnelchat): a `RESOLUCIÓN:` one-liner plus **Qué pasaba / qué se necesitaba**, **Qué se
   hizo**, **Cómo quedó**, **Cómo probarlo o verlo**. No class, file, table, endpoint or module names, no
   jargon; the PR number is the one exception. "Cómo probarlo o verlo" means steps in the product, not
   test commands.
4. **Show it to the dev and get explicit approval** (`AskUserQuestion`: aprobar / ajustar / cancelar) —
   the draft, the issue action (link / create / rewrite body) and the PR title, together. Iterate until
   approved. **No approval → nothing is written to GitHub, no PR.**
5. **Make the issue true**: create it with the approved title and body, or fix a body that no longer
   describes the real problem. Rewriting the body of an issue someone else reported requires the approval
   from step 4.
6. **Create the PR** with **`Closes #<n>`** in the `## Issue` block — the first section of
   `.github/pull_request_template.md`. `gh pr create --body-file` bypasses the template: reproduce its
   sections yourself. A bare `#n` links but does not close, and does not pass the gate.
7. **Comment the outcome** on the issue right after the PR exists (`gh issue comment <n>`): the approved
   summary verbatim, with `PR #<n>` as the last line. The format is byte-identical across domains —
   `RESOLUCIÓN:` on the first line is what notion-sync and the release tooling parse. No bug, or fix
   declined → comment the conclusion anyway, without the `PR #` line.

**Exemption — no user-visible impact** (refactor, dependency bump, CI, deploy config, docs, internal
tooling): state it out loud in the conversation ("no user-visible impact → Issue–PR Gate skipped"), put
exactly `N/A — sin-impacto-usuario` in the `## Issue` block and label the PR with
`gh pr edit <n> --add-label sin-impacto-usuario` (the label exists in this repo). Silence is not an
exemption; if it is unclear whether a change is user-visible, it is not exempt.

The gate runs at PR time, not at branch creation: it does not rename branches. This section is identical
in every Funnelchat domain repo — keep it that way; repo-specific notes go after it, not inside.

### platform-gate notes

A gate change is user-visible whenever a consumer's API answers differently (a status code, an error body, a
header, a cap). Those are never exempt. A release PR also states the new tag and the upgrade order.
