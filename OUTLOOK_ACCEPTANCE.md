# Classic Outlook acceptance tests

The `Classic Outlook Acceptance` workflow validates generated MSG files with
the real Classic Outlook Object Model. It is intentionally manual until a
dedicated runner is available.

## Runner requirements

- A dedicated Windows 11 x64 virtual machine.
- Licensed and activated Classic Outlook. New Outlook does not expose COM.
- A dedicated Windows user with a prepared Outlook/MAPI profile.
- A repository runner with the labels `Windows`, `X64`, and
  `outlook-classic`.
- The runner started by `run.cmd` in the logged-on user's interactive desktop,
  not installed as a Windows service.

Use a disposable VM without personal mail or credentials. The workflow only
runs manually from `master`, has read-only repository permissions, and uses
the `outlook-acceptance` environment. Do not enable it for pull requests from
forks: workflow code executes directly on the self-hosted machine.

## Acceptance sequence

1. PHP reads every known-valid mail fixture.
2. It removes all source attachments, adds one binary replacement, and writes
   a new MSG.
3. Classic Outlook opens the generated MSG through `OpenSharedItem`.
4. The PowerShell validator checks the message class, subject, attachment
   count, name, and extracted payload hash.
5. Outlook saves the item as a Unicode MSG and opens that file again.
6. The PHP parser reads every Outlook-resaved file and repeats the portable
   semantic and attachment checks.

The workflow uploads generated, extracted, and Outlook-resaved files for
diagnostics. A timeout protects against modal Outlook dialogs; restore the VM
to a clean snapshot if Outlook or its profile becomes unhealthy.
