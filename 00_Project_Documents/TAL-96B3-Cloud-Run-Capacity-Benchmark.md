# TAL-96B3 Cloud Run CP-SAT Capacity and Solution-Quality Benchmark

- **Experiment date:** 18 July 2026 (Philippine Time)
- **Contract and profile:** `tal94-demand-v2`; `balanced_v1`, version 1
- **Solver:** OR-Tools 9.15.6755; fixed seed `20260718`
- **Immutable image:** `sha256:beedcfb41067aa028b4c793ab6c9dc2283b7f4adb082124530b41afa4d1cebb0`
- **Cloud Run region:** Singapore (`asia-southeast1`)

## Purpose and claim boundary

This experiment determines which private Cloud Run profile should operate TALA's current client workload, measures a bounded larger-workload envelope, and records solution quality, runtime, model growth, resource use, and approximate request-based compute cost. TALA schedules cohort meeting demands; it does not perform whole-university individual student sectioning and is not positioned as a direct replacement for enterprise timetabling suites.

The experiment does not compare optimization algorithms, calculate predictive accuracy, prove a universal minimum or maximum, or change any hard constraint, soft objective, weight, data-contract field, or Laravel publication rule. All benchmark snapshots were captured from `test_tala_db` inside rolled-back transactions. No benchmark schedule run, candidate row, official meeting, or queued job survived.

## Institutional and research basis

The client reports 47 students across BM, IT, and THM: 35 first-year and 12 second-year students. Its current arrangement has one section for each program-year combination, or six cohorts. The accepted synthetic operational baseline converts the disclosed curricula and resources into 54 Scheduling Demands, 12 faculty records, 6 rooms, and 156 half-hour calendar slots. Student population explains the business setting, but Scheduling Demands, candidate assignments, resources, and slots drive CP-SAT model size because TALA schedules cohort meetings rather than individual students.

Published university-timetabling benchmarks are used only to calibrate terminology and caution against universal claims. ITC 2019 reports instances ranging from roughly 500 classes for a school or faculty to thousands of classes for broad university scope. UniTime separately models rooms, instructors, classes, students, and group constraints. Those problem units and constraints are not equivalent to one TALA Scheduling Demand, so their counts are not copied into the experiment or treated as a performance target.

The disclosed test tiers are therefore controlled transformations of TALA's accepted baseline:

| Tier | Demands | Faculty | Rooms | Slots | Experimental purpose |
| --- | ---: | ---: | ---: | ---: | --- |
| Reduced technical | 27 | 6 | 3 | 156 | Harness and monotonic-growth check; not an institutional minimum |
| Client-representative | 54 | 12 | 6 | 156 | Current client-scale operational baseline derived from 47 students and six cohorts |
| Proportional 2× | 108 | 24 | 12 | 156 | Doubles schedulable work and resources while preserving their ratio |
| Contention 2× | 108 | 12 | 6 | 156 | Doubles work without adding faculty or rooms to expose resource pressure |
| Proportional 4× | 216 | 48 | 24 | 156 | Exploratory upper tier for model growth and resource-boundary observation |

These tiers describe computational scale, not institution categories. “Client-representative” does not mean an extracted production timetable, while “proportional 4×” does not mean a supported maximum.

## Method and infrastructure profiles

Every revision used the same image, contract, profile, random seed, request payload rules, concurrency of one, minimum instances of zero, maximum instances of three, and 300-second HTTP request timeout. Python tests ran in Cloud Build; the laptop did not execute CP-SAT stress tests. Laravel locally enforced the guarded environment, snapshot, typed response, validation, sanitization, and zero-persistence rules.

| Profile | vCPU | Memory | CP-SAT workers | 30-second role | Longer-window role |
| --- | ---: | ---: | ---: | --- | --- |
| A | 1 | 2 GiB | 1 | Smallest comparison profile | Not used for larger tiers |
| B | 2 | 4 GiB | 2 | Client-production candidate | Proportional 2× at 120 seconds |
| C | 4 | 8 GiB | 4 | Upper comparison profile | Research-only 120/240-second boundary testing |

The representative comparison used ten sequential runs per profile. Higher tiers used bounded screening followed by confirmation only when a shorter solver window reached a clean compute boundary. The experiment used 80 solve requests in total, exactly the approved ceiling: 15 retained pilot requests, 63 final experiment requests, and 2 post-promotion canonical acceptance requests.

## Measures and interpretation of quality

A run is accepted only when it returns `optimal` or `feasible`, assigns every demand, reports zero solver hard violations, passes Laravel's independent hard-constraint validation, supplies complete typed telemetry, and has no authentication, transport, or container failure.

For maximizing objective value $Z_i$, CP-SAT best bound $B_i$, and same-tier best observed objective $Z^*$:

$$
\operatorname{gap}_i = \frac{|Z_i-B_i|}{\max(1,|Z_i|)},
$$

$$
\operatorname{RPD}_i = \frac{|Z^*-Z_i|}{\max(1,|Z^*|)}\times 100.
$$

The relative optimality gap measures the remaining distance between the incumbent and CP-SAT's current bound. Same-tier RPD describes dispersion from the best objective observed in that tier. Neither is predictive accuracy. For TALA, correctness is established by complete coverage and zero hard violations; optimization quality is described by status, objective, best bound, gap, RPD, and runtime.

## Client-representative profile comparison

All 30 comparison runs accepted all 54 demands with zero solver and Laravel hard violations and complete telemetry.

| Measure | Profile A | Profile B | Profile C |
| --- | ---: | ---: | ---: |
| Accepted runs | 10/10 | 10/10 | 10/10 |
| Coverage | 100% each run | 100% each run | 100% each run |
| Objective range | 411,830–411,890 | 428,590–454,120 | 427,640–445,900 |
| Median relative gap | 12.9945% | **4.4632%** | 7.4411% |
| p95 relative gap | 13.0110% | **8.5408%** | 8.7747% |
| Maximum same-tier RPD | 0.0146% | 5.6219% | 4.0951% |
| Median runtime | 31.359 s | **31.017 s** | 31.402 s |
| p95 runtime | 31.879 s | **31.380 s** | 31.866 s |
| Revision-scoped maximum minute p99 CPU | 91.98% | 77.98% | 64.98% |
| Revision-scoped maximum minute p99 memory | 59.98% | 39.98% | 25.99% |

Profile B was selected for the client production workload. Validity was equal across profiles, but the approved selection rule compares solution quality before cost: B produced the smallest median and p95 gap and the fastest median and p95 runtime. It also retained substantial measured memory headroom. Profile C used twice B's CPU and memory without improving the client-scale distribution, while profile A's smaller size produced materially weaker bound evidence. Cost is therefore secondary rather than the reason for preferring A.

## Larger-workload results

At 30 seconds, profiles B and C each returned `unknown` without an incumbent for proportional 2×. Candidate and model telemetry completed, and there was no OOM, transport, authentication, or contract failure. The result was a time/compute boundary, so the next approved window was 120 seconds.

| Tier and profile | Accepted | Result | Objective range | Gap range | Runtime | Model scale |
| --- | ---: | --- | ---: | ---: | ---: | --- |
| Proportional 2×, B, 120 s | 3/3 | `feasible` | 822,960–830,890 | 10.8929%–11.9615% | median 123.947 s | 35,712 candidates; 108,120 variables; 216,384 constraints |
| Proportional 2×, C, 120 s | 3/3 | `feasible` | 839,090–849,900 | 8.4125%–9.8023% | median 124.893 s | same model scale |
| Contention 2×, C, 120 s | 0/1 | diagnostic `infeasible` | — | — | 58.131 s | 20,712 candidates; 62,610 variables; 125,688 constraints |
| Proportional 4×, C, 120 s | 0/1 | `unknown`; compute boundary | — | — | 140.689 s | 131,424 candidates; 396,816 variables; 793,344 constraints |
| Proportional 4×, C, 240 s | 2/3 | 2 `feasible`; 1 infrastructure failure | 1,400,730–1,703,280 | 5.2694%–29.6280% | 258.508–260.196 s for accepted runs | same 4× model scale |

The contention result is diagnostic for the synthetic resource-closed transformation only; it is not a conclusion that an actual 108-demand institution is infeasible. Proportional 2× is the largest repeatably accepted tier, provided the research profile and 120-second solver limit are used. Proportional 4× is the largest tier at which a feasible solution was observed, but it is not a supported envelope: one of three runs exceeded the maximum approved 8 GiB allocation. Cloud Run logged 8,197 MiB used, terminated the instance, and returned HTTP 503. The C240 revision's measured memory utilization consequently reached 100%.

This distinction is deliberate:

- **repeatably accepted capacity evidence:** proportional 2×, 3/3 on B and 3/3 on C at 120 seconds;
- **largest accepted observation:** proportional 4×, 2/3 on C at 240 seconds; and
- **observed resource boundary:** proportional 4× can exhaust 8 GiB, so it requires future optimization or a separately approved resource/architecture study before it can be promised operationally.

## Production promotion and Laravel acceptance

Profile B revision `tala-scheduler-solver-b3b-e427393e2d06` was promoted to 100% canonical traffic after the scheduling queue was paused and the database proved zero active runs, scheduling jobs, and related failures. The service remained private; only `tala-scheduler-invoker@tala-dev-ocr-3s.iam.gserviceaccount.com` retained `roles/run.invoker`.

Post-promotion acceptance used two real authenticated client-representative solves to remain within the 80-request ceiling. Both returned `feasible`, assigned 54/54 demands, reported zero hard violations, used two workers and seed `20260718`, and passed Laravel validation. Laravel then exercised candidate ingestion, Registrar publication of 54 meetings, and the Faculty schedule projection inside a database transaction. After rollback, `test_tala_db` again contained zero schedule runs, candidate rows, official meetings, and queued jobs; the scheduling queue was resumed.

Cleanup removed the malformed, pilot, A, longer-window B, and C zero-traffic revisions after their evidence was retained. The selected B revision and immediately previous serving revision remain. A zero-traffic service-template revision, `tala-scheduler-solver-b3base2-e427393e2d06`, mirrors the exact B digest and configuration so a future service update cannot silently inherit the experimental 240-second C profile.

## Cost estimate

For request-based execution, the experiment uses

$$
C = T\left(vr_{cpu}+mr_{mem}\right)+N_r r_{req},
$$

where $T$ is observed client elapsed time used as a billable-time proxy, $v$ is vCPU, $m$ is GiB, and $N_r$ is request count. The 18 July 2026 Singapore request-based list rates used were US$0.000011244 per vCPU-second, US$0.000001235 per GiB-second, and US$0.40 per million requests. Recalculated across all 80 requests, the bounded estimate is approximately **US$0.214198** before free-tier credits.

This is an experimental request-cost estimate, not an invoice or monthly forecast. It excludes rounding differences between client time and Cloud Run billable instance time, network and registry charges, build charges, taxes, discounts, free-tier eligibility, and unrelated project usage. Cloud Run bills measured use rather than reserving the configured maximum continuously because minimum instances remain zero.

## Safety, reproducibility, and limitations

- Cloud Build `50f5fcd2-0009-431c-801e-7fa0920f1034` succeeded and ran all 26 Python tests.
- Every tested profile used the exact accepted image digest and private IAM.
- Concurrency remained one because each CP-SAT request is independently CPU- and memory-intensive.
- Fixed seed and fixed configuration reduce search variation, but wall-clock limits and multi-worker scheduling do not guarantee byte-identical incumbents.
- `feasible` proves a valid incumbent, not optimality; `unknown` does not prove infeasibility.
- Published schedules remain authoritative if the solver is unavailable, and Laravel retains validation, review, revalidation, publication, and controlled manual-continuity authority.
- The benchmark supports the disclosed TALA model and tiers only. A material change to demand composition, constraints, objective semantics, solver version, Cloud profile, or institutional workload requires renewed evidence.

## External references

- [Real-world university course timetabling at ITC 2019](https://link.springer.com/article/10.1007/s10951-023-00801-w)
- [UniTime University Course Timetabling benchmark datasets](https://www.unitime.org/uct_datasets.php)
- [UniTime University Course Timetabling data format](https://www.unitime.org/uct_dataformat_v24.php)
- [PyJobShop: Solving scheduling problems with constraint programming in Python](https://arxiv.org/abs/2502.13483)
- [OR-Tools CP-SAT solver guide](https://developers.google.com/optimization/cp/cp_solver)
- [Cloud Run CPU configuration](https://docs.cloud.google.com/run/docs/configuring/services/cpu)
- [Cloud Run pricing](https://cloud.google.com/run/pricing)
