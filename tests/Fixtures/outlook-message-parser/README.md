# `outlook-message-parser` interoperability fixtures

All 29 `.msg` files in this directory are unchanged copies of
[`bbottema/outlook-message-parser`](https://github.com/bbottema/outlook-message-parser/tree/c6543f8ebd1d1d972c042332826d8c0cbe6184e6/src/test/resources/test-messages)
at commit `c6543f8ebd1d1d972c042332826d8c0cbe6184e6`.

The upstream project is Apache-2.0 licensed. Its `LICENSE-2.0.txt` and
`NOTICE.txt` files are reproduced in this directory. The two issue-16 fixtures
also retain the upstream `issue-16-MSGReader-license.txt` notice.

The PHP assertions in `tests/MsgParser/BbottemaFixtureTest.php` were adapted
independently to this library's public API. They cover parsing and unchanged
round-trips, sender identity fallbacks, submit timestamps, recipient groups,
Unicode text, nested messages, inline content, attachment payloads, inferred
MIME types, and S/MIME payload metadata.

The upstream suite contains 60 Java tests: 28 fixture-backed integration tests
and 32 unit tests for Java-specific helpers and models. All 28 integration
scenarios are represented here, grouped by behavior instead of copied
one-method-for-one-method. Every upstream `.msg` is also included in the
parse-and-byte-identical-round-trip matrix.

Java-only behavior that has no corresponding API here is not asserted:
Reply-To extraction, the dedicated S/MIME object model, and RTF-to-HTML
conversion. Java resource-loader fallbacks and mutable model setters are also
implementation details that do not exist in this library.
