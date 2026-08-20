# msg-parser-rs interoperability fixtures

These eight unique `.msg` files are unchanged copies of
[`marirs/msg-parser-rs`](https://github.com/marirs/msg-parser-rs/tree/a5579ed5d1379c794392f4e01891f35f9d25aa8e/data)
at commit `a5579ed5d1379c794392f4e01891f35f9d25aa8e` (2026-05-24).
The upstream MIT license is reproduced as `LICENSE.MIT.txt`.

The ninth upstream fixture, `unicode.msg`, is not duplicated: its SHA-256 is
`3c67dc70854b994e469fc14496d23bf778e718831806ebafc72436c840439f9c`,
identical to `tests/Fixtures/msg-extractor/unicode.msg`, which is already tested.

Portable Rust expectations are adapted for malformed-file rejection, ANSI
fields, To/Cc/Bcc grouping, message classes, dates, by-value and embedded
attachments, MIME types, payload magic bytes, nested messages, and unchanged
round-trips. Low-level Rust/OLE storage, JSON serialization, reader ownership,
and internal decode tests have no equivalent public PHP API.

One upstream assertion expects the first `test_email.msg` To address to be
`marirs@outlook.com`. The actual recipient SMTP property is
`marirs@gmail.com`; both this library and TeamMsgExtractor/extract-msg
independently return the latter. The PHP test records the independently agreed
value rather than reproducing that stale upstream expectation.
