You are right: reading every module into one `/grill-with-docs` session would produce another oversized PRD. The modules are shared context; development PRDs should follow complete institutional journeys.

**Execution Order**

| Order | Feature PRD | Depends On |
|---|---|---|
| 1 | Secure Role Entry and Workspaces | None |
| 2 | Applicant Intake to Official Student Record | 1 |
| 3 | Academic Structure to Active Curriculum | 1 |
| 4 | Term Offering to Published Schedule Core | 3 |
| 5 | Enrollment Readiness to Assessment | 2, 3, 4 |
| 6 | Manual Payment to Official Enrollment and Outputs | 5 |
| 7 | Faculty Roster to Released Grades | 6 |
| 8 | Hold Resolution and Reactivation | 2, 6 |
| 9 | Student Lifecycle Changes | 6, 8 |
| 10 | Graduation Eligibility Evaluation | 3, 7, 8, 9 |
| 11 | Operational Oversight and Compliance | 1–10 |
| 12 | Google OR-Tools CP-SAT Integration | Main PRDs complete; technically depends on 4 |
| 13 | PayMongo Integration | Main PRDs complete; technically depends on 6 |

Student Hub, authorization, audit, notifications, and reports are not separate horizontal builds. Each relevant journey must carry them through end-to-end.

## Common Prompt

Use this as the replacement workflow. Paste the **common prompt and one selected scope together in one message**.

## Grill Prompt

```text
/grill-with-docs

We are defining one feature-sized TALA PRD, not the whole system.

Selected scope:
[PASTE EXACTLY ONE SCOPE PROMPT HERE]

Read CONTEXT.md, the specified PRD modules, and relevant live code and tests. Treat PRD modules as intended product context and existing code as evidence, not automatic authority.

Preserve these invariants:
- TALA is a College-focused mature SIS.
- TALA owns official SIS records.
- Institution-handled actions create or update a named TALA Result Record.
- External services provide computation or payment evidence only.
- Build the smallest complete, demonstrable vertical journey.
- Do not introduce unrelated or superseded features.

Interview me one question at a time and provide your recommended answer. Explore the repository instead of asking questions that code or tests can answer.

Resolve:
- Actors and permissions
- Preconditions and workflow states
- Main, failure, correction, and recovery paths
- Records created or changed
- Audit and privacy effects
- Student Hub and staff visibility
- Boundaries with adjacent PRDs
- Highest practical external-behavior testing seam

Testing analysis:
- Classify existing tests by the approved product behavior they protect.
- Do not assume every historical test must survive.
- Identify behavior that remains valid, changes, or is removed.
- Preserve tests for valid unaffected behavior.
- Update tests when approved behavior changes.
- Recommend deletion when a test exclusively protects explicitly removed behavior.
- Do not preserve obsolete behavior merely to make an old test pass.
- Do not delete unrelated tests merely because they fail.
- Test deletion must be explicitly authorized by the resulting PRD or issue.
- Prefer focused integration-style tests through public behavior.

Do not implement code, publish a PRD, create issues, or run the full test suite during grilling. Finish only when the feature is sufficiently resolved for /to-prd.
```

## PRD Prompt

After grilling is complete, use:

```text
/to-prd

Synthesize only the resolved vertical journey from this thread.

First propose the highest practical behavioral testing seam and wait for my confirmation.

In Testing Decisions, classify:
- Behaviors and tests that remain valid
- Behaviors and tests requiring updates
- Explicitly removed behaviors whose obsolete tests may be deleted
- Unrelated existing failures that must not expand scope

Authorize obsolete-test deletion only when the PRD explicitly removes the corresponding product behavior. Describe behavior, not specific file paths.

Then publish the Matt-format parent PRD as a ready-for-agent GitHub issue.
```

## Issues Prompt

After the PRD is published:

```text
/to-issues

Use the parent PRD created in this thread.

Draft independently demonstrable tracer-bullet vertical slices. Each issue must cross the necessary behavior, persistence, authorization, UI, audit, and testing boundaries.

For every proposed issue show:
- Title
- Blocked by
- User stories covered
- Focused behavioral test seam
- Existing behavior that must remain protected
- Changed or removed behavior requiring test update or approved deletion

Do not create horizontal database-only, backend-only, UI-only, or test-cleanup issues.

Do not create a general “fix all tests” issue. Unrelated existing failures remain outside the slice.

Quiz me on granularity and dependencies before publishing anything.
```

## Implementation Prompt

Use this in a fresh thread for each approved issue:

```text
/implement

Implement only this approved tracer-bullet issue using the parent PRD as context.

Use /tdd at the agreed behavioral seam.

Before editing:
- Inspect the relevant existing tests.
- Run only the focused test file or filter to establish its baseline.
- Classify relevant tests as valid, changed, obsolete, or unrelated.

During implementation:
- Work one RED-GREEN behavioral test at a time.
- Run focused test files or filters regularly.
- Preserve valid unaffected tests.
- Update tests whose approved behavior changed.
- Delete a test only when the approved PRD or issue explicitly removes the behavior it protects.
- When a file contains both valid and obsolete tests, remove only the obsolete cases.
- Add replacement behavioral coverage when removed tests are replaced by new behavior.
- Do not repair unrelated failures or expand the issue merely to make the suite green.
- Do not change production behavior solely to satisfy an obsolete test.

Verification:
- Run formatting and relevant static checks.
- Run the complete focused regression set for the affected journey.
- Run the full test suite once at the end, as required by /implement.
- Classify every full-suite failure as relevant regression, approved obsolete behavior, or unrelated/pre-existing failure.
- Fix relevant regressions.
- Handle approved obsolete tests according to the issue.
- Report unrelated failures without modifying them.
- Never claim the full suite passes when it does not.

Complete the requested review and commit only the approved issue scope.
```

## Scope Prompts

**1. Secure Role Entry and Workspaces**

```text
Scope: Read modules 01, 02, and relevant parts of 13. Define the complete journey from account creation or activation through authentication, role-scoped workspace entry, denied unauthorized access, account recovery, and audit evidence. Exclude admissions, academic setup, scheduling, enrollment, and finance behavior.
```

**2. Applicant Intake to Official Student Record**

```text
Scope: Read modules 01, 02, 03, 11, 12, and 13. Define the journey from applicant intake and identity evidence through Admission Checklist Item review, correction, decision, duplicate handling, handover, student-number assignment, official student profile, and activated Student Hub access. Stop before enrollment.
```

**3. Academic Structure to Active Curriculum**

```text
Scope: Read modules 04 and 13. Define the journey from term, course, and curriculum configuration through import or encoding, validation, recorded offline approval, activation, cohort locking, supersession, and readiness for offerings. Exclude term offerings, timetable generation, and student enrollment.
```

**4. Term Offering to Published Schedule Core**

```text
Scope: Read modules 04, 05, 06, and 13. Define the provider-neutral journey from active curriculum through term offerings, sections, faculty, rooms, Scheduling Demand, readiness checks, isolated candidate schedules, human review, validation, and official publication. Do not integrate Google Cloud Run or execute CP-SAT yet; determine the stable contract and local/manual path that the later integration will use.
```

**5. Enrollment Readiness to Assessment**

```text
Scope: Read modules 03, 04, 05, 07, 08.3, 11, and 12. Define the journey from a handed-over student through enrollment gates, prerequisites, curriculum eligibility, regular or irregular section selection, schedule-conflict checks, capacity reservation, and active assessment. End in Payment Pending; exclude payment confirmation and official enrollment.
```

**6. Manual Payment to Official Enrollment and Outputs**

```text
Scope: Read modules 07, 08 excluding PayMongo-specific sections, 09, 11, 12, and 13. Define the journey from active assessment through cashier-issued paper OR, manual payment evidence, ledger posting, finance clearance, official enrollment, COR, SOA, payment acknowledgement, and Student Hub visibility. Establish the provider-neutral payment evidence boundary but exclude PayMongo.
```

**7. Faculty Roster to Released Grades**

```text
Scope: Read modules 02, 10, 12, and 13. Define the journey from official enrollment and published schedule through faculty roster access, period-equivalent encoding, submission, Registrar posting and release, approved offline correction recording, and Student Hub grade visibility. Exclude a raw-score gradebook and online correction approval.
```

**8. Hold Resolution and Reactivation**

```text
Scope: Read modules 03, 07, 08.7, 11.1–11.3.2, 12, and 13. Define the journey from a condition creating an explicit Hold through student-facing visibility, staff resolution or waiver, audit evidence, promissory-note bypass boundaries, and Registrar reactivation. Preserve RA 11984 boundaries.
```

**9. Student Lifecycle Changes**

```text
Scope: Read modules 04.4, 07.6, 08.3–08.4, 11.3–11.4, 12, and 13. Define complete journeys for subject drop, full drop, withdrawal, leave of absence, section change, and program shift. Resolve office-handled clearance, final Registrar action, curriculum-credit impact, ledger impact, status transitions, COR impact, and Student Hub visibility.
```

**10. Graduation Eligibility Evaluation**

```text
Scope: Read modules 03.4, 04.4–04.5, 10, 11.3.1, 12, and 13. Define the journey from an official student academic record through curriculum-completion evaluation, grades, credits, deficiencies, holds, approved exceptions, eligibility snapshot, staff review, and student-facing result. Exclude diploma, TOR, credential issuance, courier, and claiming workflows.
```

**11. Operational Oversight and Compliance**

```text
Scope: Read module 13 and the reporting, audit, import, export, notification, monitoring, retention, and configuration requirements referenced by modules 01–12. Define complete administrator journeys for controlled configuration, operational reports, sensitive CSV export, export audit, integration monitoring, retention, and disposal. Do not use this PRD to postpone audit or authorization that belongs inside earlier journeys.
```

**12. Google OR-Tools CP-SAT Integration**

```text
Scope: Run only after the provider-neutral scheduling core exists. Read modules 05, 06, and 13. Define the vertical integration from an authorized solver request through validated snapshot creation, authenticated Cloud Run dispatch, timeout and retry handling, CP-SAT result ingestion, infeasibility reporting, candidate isolation, human review, and publication through the existing scheduling core. Do not redesign official schedule ownership or bypass candidate review.
```

**13. PayMongo Integration**

```text
Scope: Run only after manual payment evidence, ledger posting, official enrollment, and outputs work. Read modules 05.4, 07.3, 08.5, 08.5.1, 08.10, 12, and 13. Define the vertical integration from checkout initiation and capacity reservation through verified idempotent webhook handling, payment evidence, ledger posting, exception review, finance clearance, official enrollment, OR mapping, and Student Hub confirmation. Do not let a success page or PayMongo become the ledger.
```

## After Each Grill

Remain in the same thread:

```text
/to-prd

Synthesize only the resolved vertical journey from this thread. First propose the highest practical behavioral testing seam and wait for my confirmation. Then publish the Matt-format parent PRD as a ready-for-agent GitHub issue.
```

Then:

```text
/to-issues

Use the parent PRD just created. Draft independently demonstrable tracer-bullet vertical slices, show dependencies and covered user stories, and quiz me on granularity before publishing any issues.
```

After approval, each resulting issue gets a fresh `/implement` session. Do not generate all thirteen PRDs in one context.