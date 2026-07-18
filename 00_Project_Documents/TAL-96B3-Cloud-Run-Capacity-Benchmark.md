# TALA Cloud Run CP-SAT Capacity and Solution-Quality Benchmark

**Evidence revision:** 18 July 2026
**Replacement reason:** TAL-96B4 corrected shared-cohort conflict identity. Every result below was regenerated with that correction; no pre-correction measurement is mixed into this report.

## Purpose and limits

This benchmark evaluates the implemented TALA scheduling pipeline. It answers three questions:

1. Can the client-aligned 54-demand workload produce a complete, valid timetable?
2. Which approved Cloud Run profile is the most defensible production default for that workload?
3. What larger synthetic workload can the tested deployment accept before a compute, model, or memory boundary appears?

The benchmark does not claim a universal minimum or maximum school size. TALA schedules course-delivery demands for cohorts, not individual students as independent optimization decisions. The client population of 47 students supplies six real program-year cohort sizes and room-capacity context; synthetic faculty, rooms, qualifications, and availability complete the controlled test input.

## Corrected model boundary

The client baseline has six logical cohorts but 54 course-specific delivery-group records. Each demand retains its course-specific delivery-group identifier for traceability and also carries a shared cohort identifier. All demands attended by the same program-year cohort use the same shared identifier. CP-SAT and Laravel both enforce no-overlap using that shared identity, so two subjects for the same students cannot be scheduled at the same time even when their course-specific delivery-group records differ.

The correction did not change the mathematical meaning of the cohort non-overlap rule, the objective, or the database schema. It corrected the request mapping and both Python and Laravel enforcement.

## Immutable deployment evidence

| Item | Verified value |
| --- | --- |
| Google Cloud project / region | `tala-dev-ocr-3s` / `asia-southeast1` |
| Cloud Build | `c4d41f0f-a638-4179-a5ad-0c3fa460298c` — `SUCCESS` |
| Artifact image | `tala-scheduler-solver:tal96b4-ad9177e472f8` |
| Image digest | `sha256:3b46df2a712949bba3caf99bcc4c3dc75a3e474959b0586ad079b85b4e7e4612` |
| Serving revision | `tala-scheduler-solver-b4f-ad9177e472f8` |
| Traffic | 100% to the serving revision |
| Runtime identity | `tala-scheduler-runtime@tala-dev-ocr-3s.iam.gserviceaccount.com` |
| Invoker boundary | One dedicated scheduler invoker; no public invoker |
| Production resources | Profile B: 2 vCPU, 4 GiB, 2 CP-SAT workers, concurrency 1 |
| Limits | 30-second CP-SAT search; 300-second HTTP request |
| Deterministic control | OR-Tools 9.15.6755; seed `20260718` |

A **CP-SAT worker** is one solver search thread inside a request. **Cloud Run concurrency 1** permits one request at a time on each service instance. They are different settings. The 30-second limit bounds mathematical search; the 300-second limit bounds the complete authenticated HTTP request.

## Workload design

| Tier | Logical cohorts | Course-specific delivery groups | Demands | Faculty | Rooms | Purpose |
| --- | ---: | ---: | ---: | ---: | ---: | --- |
| Client-representative | 6 | 54 | 54 | 12 | 6 | Production-profile selection and full timetable acceptance |
| Proportional 2x | 12 | 108 | 108 | 24 | Doubles both work and scheduling resources |
| Contention 2x | 12 | 108 | 108 | 12 | Doubles work while retaining baseline faculty and rooms |
| Proportional 4x | 24 | 216 | 216 | 48 | Explores an upper computational boundary |

The multiplier copies and identifier-remaps the complete 54-demand scheduling structure. It does not assert that the client has twice or four times its reported student population. The contention tier is intentionally restrictive and is diagnostic rather than a supported-capacity candidate.

## Acceptance and quality measures

A run is accepted only when it returns `optimal` or `feasible`, assigns every demand, reports zero hard violations, passes Laravel's independent validation, contains the required telemetry, and has no authentication, transport, or container failure.

- **Coverage** is assigned demands divided by required demands.
- **Relative optimality gap** is the normalized distance between the best valid objective found and CP-SAT's bound. Smaller is stronger; it is not predictive accuracy.
- **Relative percentage deviation (RPD)** compares one objective with the best objective observed in the same tier. It describes repeat-run dispersion, not correctness.
- **Unknown** means no accepted incumbent was returned within the test boundary. It is inconclusive, not infeasible.
- **Infeasible** means CP-SAT proved that the exact input cannot satisfy every hard rule.
- **Infrastructure failure** means the request failed outside solver-status semantics, such as a Cloud Run memory termination.

## Stage 1: 54-demand production-profile comparison

All profiles used a 30-second search limit and ten sequential repetitions.

| Measure | Profile A: 1 vCPU / 2 GiB / 1 worker | Profile B: 2 vCPU / 4 GiB / 2 workers | Profile C: 4 vCPU / 8 GiB / 4 workers |
| --- | ---: | ---: | ---: |
| Accepted runs | 4/10 | **10/10** | **10/10** |
| Result | Compute boundary in 6 runs | Accepted | Accepted |
| Accepted objective range | 393,460 | 388,620–406,570 | 385,300–404,140 |
| Median relative gap | 14.8274% | 14.1544% | **13.3831%** |
| p95 relative gap | 14.8274% | **15.8039%** | 17.3505% |
| Median runtime | 31.182 s | **31.152 s** | 31.439 s |
| p95 runtime | 31.471 s | **31.380 s** | 31.849 s |

Profile A is not production-acceptable because six requests did not return an accepted schedule. Profiles B and C both passed validity. C had a slightly lower median gap, but B had the stronger p95 gap and faster median and p95 runtime while using half of C's CPU and memory. Profile B is therefore the justified production default for the actual client-aligned workload.

## Stage 2: doubled workload

At 30 seconds, both B and C reached a clean compute boundary on proportional 2x: model telemetry was returned, but no accepted incumbent existed. The 120-second confirmation produced the following results.

| Profile and window | Accepted | Result | Accepted gap range | Accepted runtime | Infrastructure evidence |
| --- | ---: | --- | ---: | ---: | --- |
| B, 120 s | 2/3 | Two `feasible`; one infrastructure failure | 12.5155%–12.5212% | 124.745–124.965 s | One request exceeded 4 GiB and returned HTTP 503 |
| C, 120 s | **3/3** | Three `feasible` | 6.8713%–9.7205% | median 125.651 s | No failure |

Every accepted run assigned 108/108 demands and passed zero-hard-violation validation. Profile C is the only repeatably accepted 2x configuration in the corrected experiment. This is expansion evidence, not a reason to charge the current client workload for Profile C by default.

## Stage 3: contention and 4x boundary

| Tier / Profile / window | Accepted | Outcome | Runtime / failure |
| --- | ---: | --- | --- |
| Contention 2x / C / 120 s | 0/1 | Diagnostic `infeasible` | Proof completed in 63.227 s |
| Proportional 4x / C / 120 s | 0/1 | `unknown`; compute boundary | 146.428 s end-to-end |
| Proportional 4x / C / 240 s | 0/3 | Infrastructure failure | All three instances exceeded 8 GiB and returned HTTP 503 |

The contention result proves only that the disclosed intentionally restrictive transformation is infeasible. The 4x result is the largest attempted tier and an observed memory boundary; it is not a supported maximum. All three 240-second requests were terminated after using approximately the full 8 GiB allocation.

## Model scale after shared-cohort correction

| Tier | Candidate assignments | Model variables | Model constraints | `NoOverlap` groups |
| --- | ---: | ---: | ---: | ---: |
| Client-representative | 10,356 | 31,488 | 62,832 | 138 |
| Proportional 2x | 35,712 | 108,120 | 215,808 | 276 |
| Contention 2x | 20,712 | 62,610 | 125,112 | 174 |
| Proportional 4x | 131,424 | 396,816 | 792,192 | 552 |

`NoOverlap` groups are organized by day for faculty, physical rooms, and shared logical cohorts. The count is lower than the superseded evidence because 54 course-specific delivery groups now map to six actual attendance cohorts.

## Production promotion and end-to-end acceptance

The final Profile B revision was first tested through its zero-traffic tagged URL. It returned a complete 54-demand candidate, passed Laravel validation, produced 54 candidate rows and 54 official meetings inside a rolled-back transaction, and populated all six cohort projections.

After the scheduling queue was paused and MySQL `test_tala_db` proved zero runs, candidates, meetings, jobs, and failed jobs, the revision received 100% canonical traffic. Two post-promotion authenticated solves both returned `feasible`, assigned 54/54 demands, reported zero hard violations, used two workers and seed `20260718`, passed Laravel validation, and exercised Registrar publication plus Faculty and Student projections. The queue was then resumed. The database again contained zero temporary scheduling records.

The first-year Student projections contained 10 meetings for `DTBM-1A`, 8 for `DIT-1A`, and 10 for `DTHM-1A`. Across all six cohorts, the published counts were 10, 9, 8, 8, 10, and 9, totaling 54.

## Cost interpretation

The retained corrected experiment contains 59 authenticated solve requests, including tagged and post-promotion acceptance. Using observed request duration as a billable-time proxy and the dated Singapore request-based rates of US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, and US$0.40 per million requests, the estimate is US$0.196810 before free-tier credits.

This is an experiment estimate, not a monthly forecast or invoice. It excludes billing rounding, networking, registry and build charges, taxes, discounts, invalid deployment attempts, and unrelated project usage. Cloud Run charges depend on allocated resources and billable duration; a larger profile therefore costs more per active second even when minimum instances remain zero.

## Final conclusion

- The corrected client-representative pipeline is operationally accepted at 54 demands and six shared cohorts.
- Profile B remains the production default because it is valid in 10/10 comparison runs and two canonical post-promotion runs, provides stronger tail evidence than C, is slightly faster, and uses half of C's resources.
- Profile C at 120 seconds is the only repeatably accepted configuration for the 108-demand proportional experiment.
- Contention 2x is diagnostically infeasible; proportional 4x is an observed 8-GiB memory boundary.
- These measurements do not change the CP-SAT equations. They delimit the tested implementation and deployment.
