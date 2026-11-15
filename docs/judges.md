# Airlock: a guide for judges

Airlock is an MCP server that runs an agent's shell commands for it: read the way bash reads them, weighed, asked
about in your client's own form, run in a sandbox, and their output checked before the agent reads it. Any client
that speaks MCP gets the same protection, and the same grants.

This page is the short path: run it, then where to look in the code. The full story is in the
[README](../README.md) and [design.md](design.md).

## Where it sits

Rules inside an application decide which *tools* an agent may call. Airlock is the layer under them: what a shell
command may do to the *machine*. A grant for `make test` never covers `make test; rm -rf ~`, a command cannot read
`~/.ssh` even when allowed, and what it prints reaches the agent marked as data. The two layers do not compete: an
app can keep its tool rules and send its commands through Airlock.

## Run it in five minutes

Linux or macOS, and [Claude Code](https://docs.claude.com/en/docs/claude-code) (any client with MCP forms works;
the [README](../README.md#2-connect-your-mcp-client) lists five).

1. Install the binary (PHP included; the script checks the checksum and, with `gh` logged in, the build
   attestation):

   ```bash
   curl -fsSL https://raw.githubusercontent.com/alpine-ronin/airlock/main/install.sh | sh
   ```

2. Copy the demo project and connect Claude Code to Airlock from it:

   ```bash
   git clone --depth 1 https://github.com/alpine-ronin/airlock.git ~/airlock
   cp -R ~/airlock/demo ~/airlock-demo && cd ~/airlock-demo
   airlock connect claude-code
   ```

3. Optional, for the injection check: `airlock configure`, with a free key from [build.nvidia.com](https://build.nvidia.com)
   and the URL `https://integrate.api.nvidia.com`. Without it everything runs, and outputs say they were not checked.

4. Start `claude` in `~/airlock-demo` and ask, one at a time:

   - `use the airlock run_command tool to run: cat README.md`: allow it once. The demo README hides an instruction
     to agents; the output comes back flagged as a likely prompt injection.
   - `use the airlock run_command tool to run: rm -rf build`: the form gives the rule first, then a model's reading
     of it, and offers only *once* or *refuse*. Refuse: `build/` stays.
   - `read the airlock://audit resource`, or `airlock log` in a terminal: every decision is there.

No Linux or macOS at hand: the README has a [Docker](../README.md#with-docker-no-php-needed) way. The same demo on
video: <https://youtu.be/ve3vKHaWW2k>. If something fails, `airlock doctor` says what.

## What to look at

| Claim | Code | Proved by |
|---|---|---|
| A command is read the way bash reads it | `src/Shell/ShellParser.php` | `tests/Shell/ShellParserFuzzTest.php`: random lines run in bash, every command bash ran was one the parser read |
| A grant never covers more than it was given for | `src/Grant` | `tests/Grant/GrantPropertiesTest.php`: random lines checked for properties |
| A grant stops when the Makefile or script behind it changes | `src/Grant/CommandSources.php` | `testAGrantedScriptIsAskedAboutAgainOnceItChanges` |
| Injected commands never run unasked | `src/Policy` | `tests/Injection/InjectedCommandsTest.php` over a public corpus of 24 scenarios, `tests/Fixtures/injection/commands.yaml` |
| An answer cannot be forged, replayed or reused for another command | `src/Mcp/Approvals.php` | `tests/Mcp/RunCommandOverStdioTest.php`: the server started as a process and spoken to over stdio |
| The sandbox | `src/Sandbox/Bubblewrap.php`, `src/Sandbox/SandboxExec.php` | `tests/Sandbox`, run in CI on Linux; each release binary runs a command on macOS too |
| Output masked, tagged and checked | `src/Output`, `src/Mcp/OutputReport.php` | `tests/Output`, against recorded model responses |

## Symfony AI and the PHP MCP SDK

- **Elicitation as a permission system.** `run_command` returns an MCP 2026-07-28 `input_required` result with the
  form; the SDK turns it into `elicitation/create` for 2025-11-25 clients. Both eras are tested.
  `src/Mcp/RunCommandTool.php`, `src/Mcp/ApprovalForm.php`.
- **Signed request state.** The state comes back through the client, so the MCP bundle signs it, and Airlock binds
  it to the command's hash and a one-use token. `config/packages/mcp.yaml`, `src/Mcp/Approvals.php`.
- **A model reading output as data.** `symfony/ai-platform` with the generic bridge and structured output: the model
  answers a field, `clean` or `injection`. `src/Output/ModelInjectionClassifier.php`,
  `config/packages/ai_generic_platform.yaml`. A second call puts a dangerous command in plain words:
  `src/Risk/ModelRiskExplainer.php`. Neither decides anything.
- **Calibration with the same classes.** `airlock configure` lists an API's models and keeps the ones that flag a
  known injection and pass a clean output, using the server's own classifier: `src/Command/ConfigureCommand.php`.

How each MUST and SHOULD of the MCP spec on tools, elicitation and request state is met, and the one that is not:
[mcp-spec.md](mcp-spec.md).

## Craft

PHP 8.5, Symfony 8.1, every class `final`, `declare(strict_types=1)` everywhere, PHPStan at its highest level with
strict rules and no baseline, PHP CS Fixer, Rector, all in one `composer check` that CI runs on every pull request.
Each decision and what was rejected: [decisions.md](decisions.md). The modules and the path of one call:
[architecture.md](architecture.md).

## Limits

- An agent that keeps its client's own shell tool can run anything without Airlock. `airlock connect` turns it off
  where the client's files allow it; Cursor's cannot be.
- A command reaching a git or SSH remote reaches any host ([network.md](network.md)).
- No Windows. No rate limit on granted commands.
- Airlock protects you from what your agent read, not a machine from its owner.
