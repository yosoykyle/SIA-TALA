# TAL-96B2 Representative Solver Evidence

**Evidence date:** 18 July 2026 (Asia/Manila)

**Purpose:** Record one disclosed, repeatable experiment against the accepted TAL-96B1 client-aligned synthetic baseline and hand a bounded capacity contract to TAL-96C.

## Claim boundary

This experiment proves that the current TALA CP-SAT integration can produce, validate, publish, and project a complete candidate schedule for the accepted 54-demand synthetic baseline under the disclosed local configuration. It is not the client's actual production timetable, an institutional minimum, a maximum-capacity result, an algorithm-comparison study, or a Cloud Run sizing guarantee.

## Reproducibility contract

| Item | Recorded value |
| --- | --- |
| Execution mode | Real local HTTP service at `127.0.0.1:8080`; three sequential requests, concurrency 1 |
| Database and fixture | MySQL `test_tala_db`; guarded TAL-96B1 client-aligned synthetic baseline |
| Snapshot SHA-256 | `f2fdcd1b10eaedb5d9a4aa46a5aa6b5dae46aea552e4b81bc14adcafdf6fb148` |
| Runtime source content ID | `500015ac45bc840d4175673b3558149547c33e2cd127ae211924f762c615cf17` for the canonical runtime manifest below |
| Contract / profile | `tal94-demand-v2`; `balanced_v1`, version 1 |
| Solver / OR-Tools | `cloud-cp-sat-tal94-demand-v2`; OR-Tools `9.15.6755` |
| Solver limit | 30 seconds per request |
| Search configuration | 1 worker; fixed random seed `20260718` |
| Local host | 8 logical processors; 7.79 GiB physical memory |
| Observed local service memory | 289.80 MiB peak working set across the three requests |
| Laravel boundary | PHP 8.2.12; guarded PHPUnit real-service test with transactional publication and Faculty projection |

The local host specification describes the machine used for this experiment. It does not mean that CP-SAT used eight workers: the solver was explicitly restricted to one worker. Local process memory is not directly comparable to Cloud Run container memory because the operating system, Python runtime, server process, and measurement boundary differ.

### Runtime source manifest

The experimental runtime content ID covers only the pinned dependency file and Python package executed by the local service. It deliberately excludes documentation, samples, tests, and deployment descriptors so those non-runtime changes cannot invalidate an otherwise identical experiment. Cloud deployments use a separate build-context identifier and immutable image digest.

Run this command from the repository root to reproduce the full content ID:

```powershell
$runtimeFiles = @(
    'cloud/scheduler-solver/requirements.txt'
    'cloud/scheduler-solver/tala_solver/__init__.py'
    'cloud/scheduler-solver/tala_solver/server.py'
    'cloud/scheduler-solver/tala_solver/solver.py'
) | Sort-Object

$manifest = $runtimeFiles | ForEach-Object {
    "$(git hash-object -- $_) $_"
}
$sha = [Security.Cryptography.SHA256]::Create()
$digestBytes = $sha.ComputeHash([Text.Encoding]::UTF8.GetBytes($manifest -join "`n"))
$runtimeContentId = ([BitConverter]::ToString($digestBytes)).Replace('-', '').ToLowerInvariant()
$sha.Dispose()

$runtimeContentId
```

## Input and model clarification table

| Category | Count | Meaning |
| --- | ---: | --- |
| Programs | 3 | Client-aligned academic program context |
| Contextual students | 47 | Demo and downstream context; CP-SAT schedules cohort delivery demands, not individual students |
| Courses | 40 | Academic catalog represented by the baseline |
| Course specifications/components | 41 | Component rules feeding demand generation |
| Ready Scheduling Demands | 54 | Canonical units assigned by CP-SAT |
| Faculty | 12 | Eligible scheduling resources in the snapshot |
| Rooms | 6 | Physical scheduling resources in the snapshot |
| Half-hour time slots | 156 | Six-day institutional grid |
| Admissible candidates | 10,356 | Demand/faculty/room/time combinations remaining after deterministic filtering |
| Model variables | 31,488 | Boolean selection plus auxiliary load and objective variables before presolve |
| Model constraints | 63,120 | Complete pre-presolve CP-SAT model constraints |
| `NoOverlap` constraints | 426 | Faculty/day, room/day, and delivery-group/day interval groups |
| Presolved Boolean variables | 10,074 | Boolean variables reported by CP-SAT during search |

The candidate, variable, and constraint counts describe this exact snapshot and model build. They are not fixed constants for every school or term.

## Three-run results

| Run | Status | Coverage | Hard violations | Objective | Best bound | Relative gap | Branches | Conflicts | Deterministic time (s) | CP-SAT wall time (s) | End-to-end solver time (s) |
| ---: | --- | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: | ---: |
| 1 | `feasible` | 54/54 | 0 | 420,560 | 465,618 | 10.7138102% | 121,642 | 438 | 5.085232314 | 30.0351426 | 31.829863 |
| 2 | `feasible` | 54/54 | 0 | 420,560 | 465,618 | 10.7138102% | 121,478 | 438 | 5.041772216 | 30.0331555 | 31.710046 |
| 3 | `feasible` | 54/54 | 0 | 421,020 | 465,084 | 10.4660111% | 122,241 | 444 | 5.207162056 | 30.0257759 | 31.406494 |

All three responses passed Laravel's independent assignment validation. In the guarded publication path, 54 candidate rows became 54 active official meetings, and the selected Faculty account displayed its five assigned meetings. The immutable snapshot hash was identical for all three requests.

Two distinct valid candidate solutions were observed. A fixed seed and one worker reduce uncontrolled search variation, but a wall-clock cutoff can stop deterministic search at slightly different points because machine scheduling and request overhead are not deterministic. TALA therefore requires validity, disclosed settings, full coverage, and recorded solution-quality evidence; it does not claim byte-identical timetables from time-bounded `feasible` runs.

## Interpreting “accuracy” and quality

Scheduling is constraint optimization, not prediction, so a classification-style accuracy score would be misleading. The defensible measures for this experiment are:

- **assignment coverage:** 54 of 54 demands in every run, or 100%;
- **hard-constraint satisfaction:** zero solver-reported and zero Laravel-detected hard violations in every run;
- **status:** three `feasible` results, meaning a valid schedule was found without proving optimality in 30 seconds;
- **optimality evidence:** the reported objective, best bound, and relative gap; and
- **operational acceptance:** successful typed-response ingestion, publication, and Faculty projection.

The objective value is a weighted ranking score under `balanced_v1`, not a percentage and not an accuracy measure. A smaller nonnegative optimality gap indicates that the incumbent objective is closer to CP-SAT's current bound. A zero gap with `optimal` status would prove optimality for that model and input.

## TAL-96C capacity handoff

TAL-96C must retain the exact metric names and disclose every dataset, source ID, dependency version, seed, worker count, time limit, execution mode, CPU, memory, concurrency, and request timeout. It must add generated proportional-growth and contention tiers rather than relabeling this representative fixture as “minimum” or “maximum.” At each tier it must report:

1. input, candidate, model-variable, model-constraint, and `NoOverlap` counts;
2. status, coverage, hard-constraint validation, objective, best bound, and relative gap;
3. Boolean variables, branches, conflicts, deterministic time, wall time, transport time, and memory;
4. repeated-run variation and the reason for stopping a tier; and
5. whether the result is locally valid, Cloud-deployable, or merely an exploratory stress result.

The current private Cloud Run service remains on its documented 1 GiB baseline after the prior 1,045 MiB and 1,154 MiB terminations. A 2 GiB, concurrency-1 candidate is only an approved recovery hypothesis for the separate `Deploy TAL-96B2` gate. This local experiment does not authorize deployment or establish final Cloud Run capacity.
