# HiraokaHyperTools/msgreader interoperability fixtures

These 44 unique `.msg` files are unchanged copies of
[`HiraokaHyperTools/msgreader`](https://github.com/HiraokaHyperTools/msgreader/tree/35db61e85302e004931e6c42d0781e6c1978c856)
at commit `35db61e85302e004931e6c42d0781e6c1978c856` (2026-08-18).
The upstream Apache-2.0 license is reproduced as
`LICENSE.Apache-2.0.txt`.

Upstream commits 45 MSG files. `test/Outer mail.msg` is not duplicated here
because its SHA-256 is identical to the existing MSGReader fixture
`EmailWithInnerMailAndAttachments.msg`; the other 44 are all included. The
suite has 80 Mocha tests and exact JSON/RTF oracles for 34 fixture scenarios.

The adapted PHP tests cover Shift-JIS MAPI text paired with ISO-2022-JP MIME
metadata, Unicode Outlook output, To/Cc/Bcc grouping, inline content IDs,
attachment ordering and MIME inference, nested messages up to two levels,
22 embedded entities, 200 recipients, 64-KiB FAT and 8-MiB DIFAT files,
contacts, sticky notes, appointments, voting messages, and byte-identical
unchanged round-trips for every unique file. Calendar recurrence and contact
named properties are preserved as raw/name-ID streams because this library
does not yet expose dedicated Appointment or Contact models. JavaScript buffer
and Compound File writer unit tests are implementation-specific and are not
copied.
