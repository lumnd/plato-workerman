# Security Policy

## Supported versions

This adapter is pre-1.0 and follows `lumnd/platophp`. Only the latest release is supported: a fix
goes into the next tag, and there are no backports to earlier ones.

| Version | Supported |
| --- | --- |
| latest release | yes |
| anything older | no |

## Reporting a vulnerability

**Do not open a public issue, a pull request or a discussion for a security problem.**

Report it privately, either way:

- **GitHub** — the *Security* tab of this repository, *Report a vulnerability*. This opens a private
  advisory that only the maintainers can see.
- **Email** — seatle888@gmail.com, with `[platophp security]` in the subject.

What helps, in rough order of usefulness:

- the versions of this package, of `lumnd/platophp` and of Workerman
- the listener configuration it reproduces on — the `listen` value above all
- the smallest client that shows it: the handshake, the frames, the payload
- what an attacker gets out of it: whose connection, whose identity, from which position

You will get an acknowledgement within a few days, and credit in the advisory unless you ask not to
be named. Please give a reasonable window before disclosing publicly; the length depends on the
finding and will be agreed with you rather than dictated.

## Scope

In scope — a defect in this package that lets an attacker do something the framework and this
adapter are meant to stop:

- one client's identity reaching another client's dispatch: a connection object reused across
  peers, an attribute surviving a close, `plato::$auth` leaking between messages
- a message reaching the dispatcher that is not one whole application message, or a listener
  accepting a protocol this package documents as refused
- the handshake attribute carrying something other than what the client actually sent
- a worker taken down by one client's message, which is a denial of service against every other
  connection of that process
- a pid, status or log file written somewhere an unprivileged user can influence

Out of scope:

- vulnerabilities in Workerman itself — report those at
  [walkor/workerman](https://github.com/walkor/workerman); this package is a thin adapter over it
- vulnerabilities in `lumnd/platophp` — report those at
  [lumnd/platophp](https://github.com/lumnd/platophp), whose policy this one mirrors
- an application authenticating in the open hook incorrectly, or serving a listener on a public
  interface without TLS or a proxy in front of it
- anything needing an attacker who can already run PHP in the process

## What this package promises about its defaults

- **A default must never be the one that switches a check off.** A protocol that cannot frame
  messages is refused rather than accepted with a warning, and a coroutine event loop — which would
  serve two dispatches inside one process against the framework's concurrency contract — is refused
  outright.
- **The shipped listener binds `127.0.0.1`.** Facing the internet is a decision an application makes
  explicitly, not one it inherits.
- **`ssl` settings are paths.** No key material belongs in a configuration file or in this
  repository.
- A change that makes any default more permissive has to say so explicitly in the pull request.
