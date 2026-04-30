# Runtime Tests Rebuild Design

## Context

The current Runtime test suite is dominated by broad combinatorial tests that mostly verify test-generated strings, fixed matrix sizes, benchmark thresholds, and helper behavior. These tests create confidence noise: failures are hard to localize, while many assertions do not prove Runtime behavior.

The Stream tests provide the target style: small files, concrete testdox names, real operations, focused assertions, little helper code, no console output, and no artificial benchmark or stability loops.

## Goal

Rebuild `tests/Runtime` so it keeps meaningful combinations while returning to unit-test semantics. Runtime tests should verify observable coroutine, scheduler, and main-runtime behavior through real coroutine execution.

## Non-Goals

- Do not preserve the old full Cartesian matrix.
- Do not assert fixed combination counts such as `135`.
- Do not use `echo` in tests.
- Do not include benchmark, stability, or "all combinations" tests.
- Do not mix `Sync`, `Time`, or `Process` module coverage into Runtime tests except as minimal drivers needed to observe runtime behavior.
- Do not keep large abstract fixtures that hide the behavior under test.

## Test Structure

Replace the existing combinatorial suite with a small Runtime test set:

- `tests/Runtime/CoroutineTest.php`
  - Coroutine creation starts in `STATE_CREATED`.
  - Immediate execution can return a value and finish in `STATE_DEAD`.
  - Suspended coroutines can be resumed with a value.
  - Exceptions can be thrown into suspended coroutines and handled inside them.
  - `Co\defer()` runs when a coroutine terminates.

- `tests/Runtime/SchedulerTest.php`
  - Queued coroutines enter the runnable queue and are completed by `Scheduler::dispatcher()`.
  - Immediate enqueue runs the coroutine without leaving it queued.
  - Queued coroutines are dispatched in FIFO order.
  - `Scheduler::future()` runs callbacks on the runtime loop.

- `tests/Runtime/RuntimeMainTest.php`
  - `Co\wait()` drives scheduled work to completion.
  - `Runtime::main()->resume()` wakes a waiting main coroutine with a value.
  - `Co\current()` returns the main coroutine outside a fiber and a fiber coroutine inside a coroutine.

- `tests/Runtime/RuntimeCombinationTest.php`
  - Keep a small provider of meaningful combinations, around 6-10 cases.
  - Each case combines one launch path with one behavior contract and asserts a real observable result.

## Meaningful Combination Set

The combination suite should cover these categories without expanding them into a Cartesian product:

- Launch paths:
  - queued via `Scheduler::enqueue($coroutine)`
  - immediate via `Scheduler::enqueue($coroutine, true)`
  - future callback via `Scheduler::future(...)`

- Behavior contracts:
  - return value propagation
  - suspend/resume value handoff
  - throwing into a suspended coroutine
  - defer cleanup
  - child coroutine scheduling

Example combinations:

- queued + return value
- queued + suspend/resume
- immediate + defer cleanup
- immediate + throw into suspended coroutine
- future + child coroutine scheduling
- `Co\wait()` + queued asynchronous completion

## Assertion Rules

Every Runtime test must assert behavior that production code owns:

- coroutine state transitions
- returned values
- resume or throw results
- execution order
- exception identity or message where meaningful
- defer execution count or order
- scheduler queue state before and after dispatch

Tests must not assert:

- strings generated only by a generic test harness
- matrix size
- "output is not empty"
- arbitrary runtime or memory thresholds
- private implementation details unless no public contract exists and the test is explicitly documenting a boundary

## Cleanup Plan

Remove the old artificial suite:

- `tests/Runtime/CombinatorialTestCase.php`
- `tests/Runtime/Combinatorial/SimpleCombinatorialTest.php`
- `tests/Runtime/Combinatorial/ExponentialCombinatorialTest.php`

Either remove `tests/Runtime/BaseTestCase.php` or reduce it to a minimal reset helper only if repeated setup code justifies it. Prefer direct tests over inherited fixtures.

Update `tests/Runtime/README.md` to describe the new policy briefly: focused Runtime behavior tests, meaningful combinations only, no benchmarks or exhaustive matrices.

## Verification

Run targeted Runtime tests first:

```bash
vendor/bin/phpunit --testsuite Runtime
```

Then run the full suite if targeted tests pass:

```bash
vendor/bin/phpunit
```

If unrelated existing HTTP tests fail because of the current dirty worktree, report that separately and keep Runtime changes isolated.
