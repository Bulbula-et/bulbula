# Engineering Findings — Repository and Documentation Integrity

## 1. Document control

| | |
| --- | --- |
| **Document** | Engineering Findings — repository and documentation integrity |
| **Version** | v1.0 |
| **Status** | **Reported** — nothing here is resolved by this document |
| **Date** | 2026-10-11 |
| **Owner** | Project owner (Bulbula) |
| **Companion** | [`php-application-structure-v1.0.md`](php-application-structure-v1.0.md) |

> **Status note — 2026-10-11.** These findings were produced while designing the
> v1 structure and were reported, not fixed: the documentation-audit process owns
> corrections. Where this file refers to `src/` paths or to files that the
> specification assumes but the repository does not contain, it is describing the
> **abandoned v1 layout**; the active structure is
> [`project-structure-v2.md`](project-structure-v2.md), and the reconciliation of
> the specification set with the built application is recorded in its §11.
| **Relation to the audit** | [`../45-quality/documentation-audit-v1.0.md`](../45-quality/documentation-audit-v1.0.md) owns findings `F-01`…`F-11` and its own corrections. This document uses **FND-xx** and does not restate, reopen or contradict an `F-xx` finding |

**Rule this document follows.** A finding is evidence with a location. Where
something looks wrong but the evidence is incomplete, it is recorded as
`Unverified` — not asserted.

**Why these findings exist.** The structure design reads the whole
specification set at once, which is the only pass in which the documents are
compared line by line rather than one at a time. Four of the findings below are
about the **repository state** and three are about **internal consistency**.
None is a product or security defect. All of them affect how implementation
cites its sources.

---

## 2. Findings

### FND-01 — The Phase 1 foundation is not in this repository

| | |
| --- | --- |
| **Severity** | **High** — every V1 slice is defined as an extension of it |
| **Type** | Repository state · Unresolved |
| **Status** | Reported |

**What the documents require.** `trd-v1.0.md` §6 states: *"Phase 1 delivered a
working, tested, framework-free foundation. **V1 extends it; it does not
replace it.**"* It then lists a `src/` of 15 namespaces and §6.3 gives the
responsibility of each. `architecture.md` §3 reproduces the layout and §3.1
lists the namespaces as **Existing (Phase 1)**. `implementation-plan-v1.0.md`
§4.2 enumerates what is present: **49 files in `src/`**, one migration, one
template `home.html`, `routes/web.php` and `routes/api.php` with five routes,
`config/{app,database,logging}.php`, `bin/console`, three CI workflows and
`tools/`.

**What is actually in the repository.** `main` at `4c44b50` ("Add files via
upload") contains **71 tracked files, all under `docs/`, and one commit**:

```text
$ git ls-files | wc -l
71
$ git ls-files | grep -v '^docs/'
(no output)
$ ls
docs
```

There is no `src/`, no `composer.json`, no `tests/`, no `bin/`, no `public/`,
no `config/`, no `routes/`, no `tools/` and no `.github/`.

**Why it matters.** A build agent following §20.1 of the implementation plan is
instructed to "inspect the existing implementation — the repository, not a
phase report". In this repository that step has no input. It also cannot run
`composer test`, and the architecture assertions that define "done" for every
slice do not exist yet.

**Consequence for the structure design.** Every file the design attributes to
Phase 1 is marked **`F?`** — "documented by namespace only" — rather than
**`F`**. **42 files** carry that mark.

**Possible explanations, none verified.** The core repository is private and
this one contains only the documentation; the code lives on a different branch
or remote; the upload was documentation-only. **Nothing in the design changes
whichever is true.**

**Recommended resolution.** Confirm which of the following applies before the
first slice:

| Option | Then |
| --- | --- |
| The core repository exists and is accessible | Add it as the working tree; re-mark `F?` → `F`; verify §4.3's baseline numbers |
| It exists but is unavailable | Restore read access before product code |
| It does not exist | Rebuild Phase 1 **to the contract in TRD §6.2/§6.3**, in the order TRD §6.3 lists, and treat `F?` as the build list. The design already specifies every file |

---

### FND-02 — The documented quality baseline cannot be verified from this tree

| | |
| --- | --- |
| **Severity** | **Medium** — the gate is the definition of "done" for every slice |
| **Type** | Repository state · Unresolved |
| **Status** | Reported |

`implementation-plan-v1.0.md` §4.3 records a baseline of **424 tests, 1,033
assertions, 100 % line coverage, 100 % type coverage, 18 architecture
assertions across 49 test files**, and audit §32 records the gate as `READY` and
green. None of it is reproducible here: there is no `src/`, no `tests/`, no
`composer.json`, `phpstan.neon`, `pint.json`, `rector.php` or `infection.json5`.

**Why it matters.** `IMP-4.1`–`IMP-4.3` make the baseline a **floor**.
"Never lower a threshold" is not enforceable against a threshold that cannot be
measured, and "no mutant suppressed" cannot be checked without
`infection.json5`.

**Recommended resolution.** When the foundation is restored, record one
verified measurement of the gate in `docs/45-quality/` before the first V1
slice, so later regressions are provable rather than arguable. Until then,
treat the numbers as **a target to establish**, which is what the structure
design does.

---

### FND-03 — `.ai/` is absent although the documented workflow requires reading it

| | |
| --- | --- |
| **Severity** | **Medium** — the pre-coding ritual cannot be performed |
| **Type** | Repository state · Unresolved |
| **Status** | Reported |

`implementation-plan-v1.0.md` §3 states the authority chain as
`formal documentation → .ai/ context → this plan → code`, and §20.1 makes
"read `.ai/` (project-overview, plus the area context via source-map)" the first
step before coding. Audit §27 describes **12 `.ai/` files baselined at
`3b502eb`** with 28 checks passing, including `architecture-context.md` (which
reproduces the 18 architecture assertions), `code-standards.md`,
`ai-workflow-rules.md` and a source map. Audit F-08 records that all eleven
derived files were rebaselined during the audit.

None of it is in this repository.

**Why it matters.** Three consequences:

1. The first instruction before writing code cannot be followed.
2. `tools/verify-ai-context.py` — which audit F-08 says now enforces baseline
   freshness — is also absent (FND-04), so staleness cannot be detected.
3. **Any change to `docs/` — including this document — invalidates the `.ai/`
   baseline.** Audit F-08's check makes that mechanical: a file may not claim
   `Context status: Current` when `docs/` has moved since its recorded baseline.

**Recommended resolution.** Restore or author `.ai/`, then **rebaseline** it to
the commit that adds `docs/70-engineering/`, and update the source map and
progress tracker in the same change. The rebaseline is part of the change, not
a follow-up.

---

### FND-04 — `tools/` and CI are absent, so `docs/` cannot be validated

| | |
| --- | --- |
| **Severity** | **Medium** — it constrains what may be added under `docs/` |
| **Type** | Repository state · Unresolved |
| **Status** | Reported |

`implementation-plan-v1.0.md` §4.2 lists four read-only verifiers —
`tools/verify-docs.py`, `tools/verify-ai-context.py`, `tools/qa35.py`,
`tools/qa36.py` — and `.github/workflows/{ci,mutation,security}.yml`. Audit
F-06 records that the verifiers were **committed** precisely because "QA that
exists only in one working session is not QA". §20.3 of the plan requires
running two of them before a pull request "when `docs/` or `.ai/` changed".

They are not in this repository, and the three workflows are not either.

**Why it matters — and why it is recorded here rather than in the design.**
This document and its companion **add a new folder under `docs/`**
(`docs/70-engineering/`) and a new file under `tools/`
(`scaffold-structure.sh`). Both are precisely the changes those verifiers exist
to check. Without them:

- A documentation-structure rule the verifier enforces is unknowable here; the
  new folder **may** fail a check none of us can read.
- `tools/scaffold-structure.sh` is a **generator**, while `tools/` is described
  as the home of **read-only verifiers**. It is placed there by convention, not
  by rule.

**Recommended resolution.**

| Option | Then |
| --- | --- |
| Restore the verifiers | Run them against this change and fix whatever they report |
| They cannot be restored | Author equivalents, or record explicitly that documentation changes are unverified until they exist |
| Either way | Decide whether a generator belongs in `tools/` — the alternative is `docs/70-engineering/` beside the document it serves |

---

### FND-05 — Six duplicate requirement identifiers and five mis-citations

| | |
| --- | --- |
| **Severity** | **Medium** — a comment citing `TR-69` is ambiguous to a human and unresolvable to a tool |
| **Type** | Document consistency · New (not covered by `F-01`…`F-11`) |
| **Status** | Reported · **Not corrected** — the audit process owns corrections |

**Duplicate definitions inside `trd-v1.0.md`.** Six identifiers are each
defined twice, once in §18 (Review and moderation) and once in §19
(Advertising):

```text
$ grep -oE '^\| TR-[0-9]+[a-z]? \|' 30-technical/trd-v1.0.md | sort | uniq -c | awk '$1>1'
   2 TR-69      §18 rating is a required integer 1–5   ·  §19 delivery selection runs after organic ordering
   2 TR-70      §18 one active Review per (Customer, Branch)  ·  §19 a Campaign serves only when approved, in period, eligible
   2 TR-71      §18 30-day edit window            ·  §19 Campaign start and end evaluated at request time
   2 TR-72      §18 author deletion is a withdrawal ·  §19 Placement inventory enforced at creation
   2 TR-73      §18 published Reviews newest first ·  §19 every Sponsored placement carries the label
   2 TR-74      §18 no minimum account age        ·  §19 impressions and clicks never influence price or ranking
```

The TRD contains **223 distinct `TR-` identifiers**, running `TR-01` to
`TR-224` (with lettered forms such as `TR-01a`–`TR-01e`). Six of them are
ambiguous. The advertising series should almost certainly occupy a block that
does not collide with the review series — but **renumbering is a documentation
change and is not made here.**

**Mis-citations across documents.** Each row was checked against the TRD's own
definition of the identifier:

| Identifier | Defined in the TRD as | Mis-cited by | Where the rule actually lives |
| --- | --- | --- | --- |
| `TR-110` | Surface differences confined to a documented adapter | `auth-identity.md` LK-5 — "Linking never silently grants access… (TRD TR-110)" | Identity linking rules, `auth-identity.md` §5 |
| `TR-183` | The repository is the source of truth | `search-design.md` §2 — "Shared hosting; a second daemon is not deployable (TRD TR-183)" | TR-182 (one deployable unit), NG-10 |
| `TR-183` | The repository is the source of truth | `performance-and-caching.md` SA-6 — "No CDN dependency for core rendering (TRD TR-183)" | TRD §37 / §8 degradation table |
| `TR-189` | Deployment must work on shared hosting | `auth-identity.md` GA-5 — "Client identifiers and secrets come from environment configuration (TRD TR-189)" | TR-186, TR-192, CF-2 |
| `TR-189` | Deployment must work on shared hosting | `security-architecture-v1.0.md` SEC-5.3 — "Secrets reach the application from configuration only \| TR-01e, TR-189" | TR-01e is correct; `TR-189` is not |

**Why it matters.** `TR-01a`–`TR-01e` show the house pattern: a rule is cited
by identifier *and* restated. Where only the identifier is cited, the citation
is the whole traceability claim — and in these five cases it points at a
different rule. The risk is concrete: an implementation agent that greps for
`TR-110` to answer "may I branch on the surface here?" finds the adapter rule,
which is the right answer by accident; one that greps `TR-183` for the CDN rule
finds the repository rule and learns nothing.

**Recommended resolution.**

| Step | Action |
| --- | --- |
| 1 | Cite by **content as well as identifier** in code comments and `@see` tags, so a stale identifier degrades rather than misleads. This document does so throughout |
| 2 | Add a citation check to the tools: every `TR-`/`D-`/`TD-`/`OT-` reference in `docs/` and `src/` resolves to exactly one definition. **[P]** `tools/verify-citations.py`, M1 |
| 3 | Route the six duplicates and five mis-citations to the documentation-audit process, which owns corrections and their record (`documentation-audit` §30). **Not corrected here** |

---

### FND-06 — Route policies are declared per prefix, but two authenticated destinations sit in the public prefix

| | |
| --- | --- |
| **Severity** | **Low** — an implementation ambiguity, not a product defect |
| **Type** | Document consistency · Resolved by proposal **PT-03** |
| **Status** | Reported, with a proposed resolution |

`trd-v1.0.md` §6.1 sets policy per prefix: `/` is "None" for authentication,
`/account/*` is "Customer session", `/ops/*` is "Staff session + permission",
and the reason given is that *"separate prefixes exist so that authorization,
rate limiting, caching and indexability are set per family rather than per
route"*.

The information architecture places two authenticated destinations **inside the
public prefix**:

| Destination | IA definition | Prefix default |
| --- | --- | --- |
| `/saved` | `C-34 · [auth]` | `/` — no authentication |
| `/b/{business}/review` | `C-13 · [auth]` | `/` — no authentication |

Both documents are internally consistent; they disagree with each other.

**Why it matters little, and should still be written down.** The URL grammar is
the constrained artefact (IAR-1…IAR-8, SEO-2), so the URLs must not move. What
is undecided is **where the requirement is declared**. Left unrecorded, three
implementations do three different things.

**Proposed resolution (PT-03).** Keep the grammar; make the requirement
per-route with family defaults, so a route may *narrow* its family (add a
session requirement and become `no-store` + `noindex`) but never widen it.
Recorded in the structure design §10.1 and requiring a TRD/`architecture.md`
note.

---

### FND-07 — Two formal documents group the same components differently

| | |
| --- | --- |
| **Severity** | **Low** — a naming/ownership question, resolved by proposal **PT-01** |
| **Type** | Document consistency · Resolved by proposal **PT-01** |
| **Status** | Reported, with a proposed resolution |

| Document | Groups by | Result |
| --- | --- | --- |
| `architecture.md` §3.1 — authority for the component map (TRD §1.2) | Namespace | 11 V1 namespaces: Directory, Operations, Identity, Community, Advertising, Search, Media, Mail, Analytics, Audit, Telegram |
| `trd-v1.0.md` §11 — authority for the constraints | Data-ownership area | 6 areas, where **Platform** owns the audit log, analytics, notifications, background jobs and cache |

Two questions are being answered as one: *what a namespace is called* and
*which component owns a table*. TRD §9.1 anticipates the difference — "namespaces
are indicative; the canonical component map is in `architecture.md`" — but does
not say how the six areas map onto the eleven namespaces, which leaves
`Audit`, `Analytics` and `Mail` orphaned from the area model while their tables
belong to Platform.

**Why it matters.** TR-16 ("a component must not read another area's tables
directly") is only checkable if table ownership is unambiguous. Under one
reading, `Audit` and `Analytics` are Platform; under the other they are peers.

**Proposed resolution (PT-01).** Namespaces follow `architecture.md`;
ownership follows TRD §11, with the Platform area realised by `Audit`,
`Analytics`, `Mail` and `Platform` and each table's owner named explicitly.
This satisfies both documents and changes no product behaviour (A-3).

---

## 3. Summary

| ID | Finding | Severity | Type | Status |
| --- | --- | --- | --- | --- |
| **FND-01** | The Phase 1 foundation is not in this repository | High | Repository state | Reported — blocks the first product slice |
| **FND-02** | The documented quality baseline cannot be verified here | Medium | Repository state | Reported |
| **FND-03** | `.ai/` is absent although the workflow requires it | Medium | Repository state | Reported |
| **FND-04** | `tools/` and CI are absent; `docs/` changes cannot be validated | Medium | Repository state | Reported |
| **FND-05** | 6 duplicate `TR-` identifiers; 5 mis-citations across 3 identifiers | Medium | Document consistency | Reported — routed to the audit process |
| **FND-06** | Prefix-level route policy vs two authenticated public URLs | Low | Document consistency | Resolved by **PT-03** |
| **FND-07** | Namespaces vs areas: two groupings of the same components | Low | Document consistency | Resolved by **PT-01** |

| Rule | Statement |
| --- | --- |
| FND-R1 | No finding here is fixed by this document. Two are resolved by a **proposal** that requires recording through the normal process |
| FND-R2 | None of the seven is a product, security or privacy defect, and none changes a V1 capability |
| FND-R3 | FND-01 and FND-04 are the only two that **block work**: one blocks product code, the other blocks validating documentation changes |
| FND-R4 | A finding is not closed by being reported. It closes when the repository changes, the register gains an entry, or the owner records the decision to accept it |

---

## 4. What was checked and found sound

Recorded so the pass is auditable, and so the negative result is not lost:

| Checked | Result |
| --- | --- |
| The canonical vocabulary (glossary §1–§8) against all 71 documents | **Held.** "Save" is used everywhere in current documents; "favourite" survives only as a deprecated synonym. No business-account term is used as a live role |
| The 40 capabilities `C-01`…`C-40` against the IA's coverage table | **Complete.** Every capability has a surface; none was found without one |
| The exclusions in `scope-v1.md` §2 against `data-model.md` §10 and `component-spec` §46 | **Consistent.** The same seventeen-odd exclusions appear in all three, with no contradiction |
| The 20 invariants in `test-strategy` §3 against the TRD's acceptance criteria §39.3 | **Consistent.** Each invariant carries a source rule |
| Decision statuses against the register's own counts (23 Approved, 60 tracked) | **Held.** 24 `### D-` entries = 23 Approved + D-32 (Closed); the four M0 approvals are consistently reflected in the PRD, TRD, data model, API spec and search design |
| `D-46a`/`D-46b` citations | **Already fixed by the audit** (F-02). They survive only in `00-discovery/`, annotated as historical |
| The Phase 1 namespaces in `architecture.md` §3.1 against TRD §6.3 | **Match**, item for item |
| The API endpoint list in `api-spec` §2–§5 against PRD capabilities | **Complete**, and the sponsored-separation contract appears identically in the API spec, the search design and the PRD |
