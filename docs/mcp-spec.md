# Airlock and the MCP specification

What the [MCP specification 2026-07-28](https://modelcontextprotocol.io/specification/2026-07-28) requires about tool
security, the human in the loop, elicitation, request state and audit, and what Airlock does about each. The
requirements are quoted from the spec; a *client* requirement is listed when Airlock, as a server, does the work for
every client. Read on 2026-11-15.

## Tools

From [Tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools), *Security considerations* and
*User interaction model*.

| Requirement | | What Airlock does | Where |
|---|---|---|---|
| Servers: "Validate all tool inputs" | MUST | The command line is parsed the way bash reads it; a line that cannot be read whole (`$(…)`, a here-document) is asked about every time, never run on a grant. | `src/Shell/ShellParser.php`, `tests/Shell/ShellParserFuzzTest.php` |
| Servers: "Implement proper access controls" | MUST | Policy files refuse outright; grants cover only the parts they were given for, bound to the hashes of the files that decide what a command does; the sandbox keeps a command to the project, `/tmp` and package caches, away from keys and Airlock's own files, and on the network to the registries and the hosts you allow. | `src/Policy`, `src/Grant`, `src/Sandbox`, `tests/Grant/GrantPropertiesTest.php` |
| Servers: "Rate limit tool invocations" | MUST | A command not covered by a grant waits for your answer. Grants run at most `AIRLOCK_RATE_LIMIT` commands a minute (30 by default) without asking; past it, each one goes back to the form, saying why, so a runaway agent stops on your answer. The GitHub read tools are not limited. | `config/packages/rate_limiter.yaml`, `src/Mcp/RunCommandTool.php`, `…PastTheRateLimitAGrantedCommandIsAskedAgain` |
| Servers: "Sanitize tool outputs" | MUST | Secrets masked, the output put between tags it cannot close and marked as data, and read by a model for injection. | `src/Output`, `src/Mcp/OutputReport.php` |
| "There SHOULD always be a human in the loop with the ability to deny tool invocations" | SHOULD | Anything not covered by a grant is a form in your client; *refuse* is always offered, and a dangerous command offers only *once* or *refuse*. | `src/Mcp/ApprovalForm.php` |
| Clients: "Prompt for user confirmation on sensitive operations" | SHOULD | Done by the server, the same in every client: `sudo`, a recursive delete, `git push`, a download piped into a shell, a secret file are asked every time, and no grant covers them. | `src/Policy/Danger.php` |
| Clients: "Show tool inputs to the user before calling the server, to avoid malicious or accidental data exfiltration" | SHOULD | The form shows the exact command line, why it is asked, and a model's reading of it, labelled as such, under the rule. `github_open_pr` shows the repository, branches, title and body. | `src/Mcp/ApprovalForm.php`, `src/Risk/ModelRiskExplainer.php` |
| Clients: "Validate tool results before passing to LLM" | SHOULD | Done by the server: the injection check and the data tags above. | `src/Output/ModelInjectionClassifier.php` |
| Clients: "Implement timeouts for tool calls" | SHOULD | A command is stopped after `AIRLOCK_TIMEOUT` seconds (120 by default); a model call after `AIRLOCK_AI_TIMEOUT` (8). | `src/Execution/CommandRunner.php` |
| Clients: "Log tool usage for audit purposes" | SHOULD | Done by the server: every ending, run, refused or failed, is one JSON line, under a lock, rotated past a size, readable as `airlock://audit` and with `airlock log`. | `src/Audit/AuditLog.php` |
| Clients "MUST consider tool annotations to be untrusted unless they come from trusted servers" | MUST | Airlock decides nothing from what the agent says about a command: only the command line itself is weighed. | `src/Policy` |

## Elicitation

From [Elicitation](https://modelcontextprotocol.io/specification/2026-07-28/client/elicitation).

| Requirement | | What Airlock does | Where |
|---|---|---|---|
| "Servers MUST NOT use form mode elicitation to request sensitive information such as passwords, API keys, access tokens" | MUST NOT | The form asks for one decision only. The model API key is set in a terminal with `airlock configure`, never through the agent; GitHub goes through your own `gh` login. | `src/Mcp/ApprovalForm.php`, `src/Command` |
| Servers "MUST handle cases where the user declines or cancels the elicitation" | MUST | A declined or cancelled form, or an answer that was not offered, is a refusal: nothing runs. | `tests/Mcp/RunCommandOverStdioTest.php` (`testInTheModernEraADeclinedFormRunsNothing`, `…AGrantThatWasNotOfferedIsNoApproval`) |
| "Servers MUST bind elicitation requests to the client and user identity" | MUST | Over stdio only, one process per client: the state is signed with a key made for that process, so no other client's Airlock accepts it. | `config/packages/mcp.yaml`, `src/Configuration/ProcessKeyEnvVarProcessor.php` |
| "Servers SHOULD validate received data matches the requested schema" | SHOULD | The decision is read as an enum and checked against the choices this command was offered. | `src/Mcp/Approvals.php` |
| Clients "SHOULD present elicitation requests in a way that makes it clear what information is being requested and why" | SHOULD | The form opens with the reason (*"Asked every time: `rm -rf build`: it deletes recursively."*), then what a grant would cover. | `src/Mcp/ApprovalForm.php` |
| Servers "SHOULD NOT include URLs intended to be clickable in any field of a form mode elicitation request" | SHOULD NOT | The form holds no link. | `src/Mcp/ApprovalForm.php` |

## Request state (Multi Round-Trip Requests)

From [Multi Round-Trip Requests](https://modelcontextprotocol.io/specification/2026-07-28/basic/patterns/mrtr). The
answer to a form comes back with a state the client carries: here it decides whether a command runs.

| Requirement | | What Airlock does | Where |
|---|---|---|---|
| "Servers MUST treat `requestState` as an attacker-controlled input" and, when it influences authorization, "MUST protect its integrity (e.g. HMAC or AEAD) and MUST reject state that fails verification" | MUST | The MCP bundle signs it (HMAC); a tampered or unsigned state is rejected, and an answer without one is no approval. | `config/packages/mcp.yaml`, `tests/Mcp/RunCommandOverStdioTest.php` (`…ATamperedStateIsRejected`, `…AnAnswerWithoutTheSignedStateIsNoApproval`) |
| Inside the state, "an identifier for the originating request, e.g. the method name and a digest of its salient parameters" | SHOULD | The state holds the SHA-256 of the exact command line: an answer for `make test` cannot run anything else. | `src/Mcp/Approvals.php`, `…AnAnswerForOneCommandDoesNotRunAnother` |
| Inside the state, "a short expiry (TTL)" | SHOULD | Ten minutes, the bundle's default. | `config/packages/mcp.yaml` |
| Inside the state, "the authenticated principal" | SHOULD | No authentication over stdio; the per-process key stands in for it (above). | `src/Configuration/ProcessKeyEnvVarProcessor.php` |
| "Servers for which a given `requestState` must be consumed at most once … MUST enforce that invariant server-side" | MUST | Each state carries a random one-use token, remembered once answered: a replayed answer asks again. | `src/Mcp/Approvals.php`, `…AnAnswerIsGoodForOneRunOnly` |
| "Servers MUST NOT assume that clients will fulfill the `inputRequests` or retry the original request" | MUST NOT | Nothing is held while the form is open: without a retry, nothing runs. | `src/Mcp/RunCommandTool.php` |

## Local servers

From [Security best practices](https://modelcontextprotocol.io/specification/2026-07-28/basic/security_best_practices),
*Local MCP server compromise* and *Token passthrough*.

| Requirement | | What Airlock does | Where |
|---|---|---|---|
| Local servers "SHOULD … Use the `stdio` transport to limit access to just the MCP client" | SHOULD | stdio only; the HTTP transport is turned off. | `config/packages/mcp.yaml` |
| "MCP servers MUST NOT accept any tokens that were not explicitly issued for the MCP server" | MUST NOT | Airlock takes no token from the client, and passes none to a command: `gh` runs outside the sandbox with your own login, and `GH_ENTERPRISE_TOKEN` is not passed on. | `src/GitHub`, `…ACommandGetsNoGitHubTokenTheServerWasStartedWith` |
| For the commands a client runs, the client "SHOULD … Execute … in a sandboxed environment with minimal default privileges" and "Highlight potentially dangerous command patterns" | SHOULD | Written for the server's own launch command; Airlock applies it to every command the agent sends: bubblewrap or `sandbox-exec`, and the dangerous patterns asked every time. | `src/Sandbox`, `src/Policy/Danger.php` |

## What is not covered

- **Rate limiting of the GitHub read tools** (`github_pr_checks`, `github_pr_comments`): they run without asking and
  are not limited; what they read is checked like any output.
- **The client's own shell tool.** No MCP message can take it away, so an agent that keeps it can run anything without
  Airlock. `airlock connect` turns it off from the client's files where the client allows it (all but Cursor).
- **OAuth, URL mode, HTTP**: Airlock serves stdio only and asks for no credential, so those requirements do not apply.
