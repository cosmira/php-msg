# oxmsg writer interoperability fixtures

All six `.msg` files are unchanged copies of
[`tutao/oxmsg`](https://github.com/tutao/oxmsg/tree/1760052c543dc6b3bb41ce9a30fcdde904a58739/test)
at commit `1760052c543dc6b3bb41ce9a30fcdde904a58739` (2026-08-04).

The repository metadata says MIT, but its committed `LICENSE.txt` contains
GPL-3.0. The more restrictive committed license is therefore reproduced as
`LICENSE.GPL-3.0.txt`; no oxmsg source code is copied.

The files compare oxmsg writer output with MSGKit output for the same sender,
recipient, subject, HTML body, and optional PNG attachment. They provide six
different Compound File layouts for two semantic messages. `test_internal.msg`
adds a real-world Tutanota/Exchange message with Unicode identities, RTF, and a
JPEG attachment.

The current oxmsg automated suite imports only TypeScript unit tests for MIME,
RTF, MAPI, time, CRC, address, string, and attachment helpers; it does not parse
these committed MSG outputs. PHP tests therefore adapt the repository's
documented cross-writer comparison and require semantic equality plus
byte-identical unchanged round-trips. TypeScript helper algorithms are not
copied as tests of this PHP implementation.
