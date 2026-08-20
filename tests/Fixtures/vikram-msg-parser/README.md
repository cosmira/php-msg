# vikramarsid/msg_parser interoperability fixtures

All three `.msg` files are unchanged copies of
[`vikramarsid/msg_parser`](https://github.com/vikramarsid/msg_parser/tree/d16260df42658531a4342baf0d895c3d4ba5d1e4/tests/files)
at commit `d16260df42658531a4342baf0d895c3d4ba5d1e4` (2022-03-19).
The upstream BSD-2-Clause license is reproduced as
`LICENSE.BSD-2-Clause.txt`.

The upstream suite has eight tests covering successful parsing, JSON output,
EML export, and the CLI. Its three inputs add a large ten-attachment message,
nested MSG attachments, an attached delivery-status EML with Chinese text,
and several ordinary Office/PDF/image payloads. The PHP adaptation checks the
portable parsed semantics, nested messages, attachment methods and MIME types,
and byte-identical unchanged round-trips. JSON/EML/CLI presentation is outside
this library's API.
