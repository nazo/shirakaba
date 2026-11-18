# Agent evaluation tasks

Tasks that measure how well coding agents work with Shirakaba, with and without the bundled
documentation ([static analysis and agents](../../packages/docs/design/13-static-analysis-and-agents.md)).

Each task in `tasks/<name>/` has:

- `prompt.md`: what the agent is asked to do;
- `overlay/` (optional): files laid over the template application before the agent starts;
- `hidden/`: tests copied in after the agent finishes, which decide success with PHPStan;
- `solution/`: a reference solution, applied with `--reference` to check that the task is
  solvable and the hidden tests are right.

`bin/run` builds a fresh application from `template/` for each task, variant and run in a
temporary directory, installs Shirakaba from this repository, and for the `docs` variant also
installs `shirakaba/docs` and runs `agents:sync --claude`. It then runs the agent command, adds the
hidden tests, runs PHPStan and PHPUnit, and writes the results to `docs/research/evals/`.

```sh
# Check the tasks themselves (no agent): every task must pass with its reference solution.
php tools/evals/bin/run --reference

# Run an agent; {prompt} is replaced with the prompt file, {dir} with the workspace.
php tools/evals/bin/run --agent='claude -p "$(cat {prompt})" --dangerously-skip-permissions' --runs=3
```

Running agents costs time and API usage; the maintainer decides when to run them.
