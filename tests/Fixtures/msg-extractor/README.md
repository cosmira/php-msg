# `msg-extractor` interoperability fixtures

These files are copied from the public
[`TeamMsgExtractor/msg-extractor`](https://github.com/TeamMsgExtractor/msg-extractor)
test suite at commit `57309233dc25fd698501e7d32a8cb6af466c803c`:

- `multi-to.msg` (unchanged)
- `multi-to-to.msg` (unchanged)
- `strange-date.msg` (upstream name: `strangeDate.msg`, content unchanged)
- `unicode-header.msg` (unchanged)
- `unicode.msg` (unchanged)
- `export-results/strange-date.msg` (upstream name:
  `export-results/strangeDate.msg`, content unchanged)
- `export-results/unicode.msg` (unchanged)

They are used only by the interoperability tests in
`tests/MsgParser/UpstreamFixtureTest.php`. The original project is licensed
under GPL-3.0; its license is reproduced in `LICENSE.GPL-3.0.txt` in this
directory. The PHP assertions in this repository were written independently
for this library's public API, with scenario selection informed by the
upstream test suite.

## Coverage audit

The referenced upstream commit contains 26 Python test methods. They are not
all portable tests of MSG behavior:

- all seven committed `.msg` inputs/expected exports are included here;
- the six message tests map to recipient/header parsing, except for their EML
  conversion assertions because this library has no EML exporter;
- the normal-attachment scenario maps to filename, MIME type, method, and
  payload-hash assertions; the two forced broken/unsupported Python attachment
  subclasses have no corresponding public API here;
- the export scenario is checked both against upstream semantics and against
  this writer's output semantics;
- four low-level Python `OleWriter` editing tests, two property-constructor
  tests, two CLI tests, five utility tests, and two Python enum/constant
  validation tests exercise implementation-specific APIs rather than MSG
  interoperability; the conditional user-directory export test has no
  committed fixtures to import.

The upstream and PHP writers produce different byte layouts (stream ordering,
padding, and other serialization choices), so cross-writer equality means the
same parsed message fields and attachment payloads, not identical `.msg`
bytes. Unchanged messages handled by this library are separately required to
round-trip byte-for-byte.

The upstream expected-output TIFF files are not duplicated here: their bytes
are identical to the payloads embedded in `unicode.msg`. The test compares the
extracted payloads with the upstream SHA-256 values instead.

`strange-date.msg` also contains its own attribution and CC BY-SA 3.0 notice
inside the message body.
