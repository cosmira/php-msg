# `MSGReader` interoperability fixtures

All 15 `.msg` files and the four `rtf/LZFu.*` / `rtf/MELA.*` files in this
directory are unchanged copies of
[`Sicos1977/MSGReader`](https://github.com/Sicos1977/MSGReader/tree/30b9d590c3f71bd416989daadcac23c2b0ce5166/MsgReaderTests/SampleFiles)
at commit `30b9d590c3f71bd416989daadcac23c2b0ce5166`.

The upstream project is MIT licensed. Its `license.txt` is reproduced here as
`LICENSE.txt`.

The upstream suite contains 58 MSTest methods. Tests that exercise MSG parsing,
compressed RTF, attachment bytes and names, nested messages, Unicode subjects,
body decoding, Exchange sender fallback, and reaction-property preservation are
adapted to this library's public API.

Tests for EML/MIME parsing, EML mutation, RTF-to-HTML conversion, mutable
attachment removal, .NET culture fallback, and MSGReader-specific reaction
presentation have no corresponding API here and are not copied literally.
The compressed LZFu sample is compared after newline normalization because the
two implementations seed the RTF dictionary with different CR/LF ordering;
this library retains the MS-OXRTFCP CRLF seed and the decoded RTF content is
otherwise identical. For embedded messages this library exposes the stored
MAPI display name, while MSGReader synthesizes a `.msg` suffix. Exact output
sizes from the .NET serializer are likewise not portable API expectations.

## Public issue corpus

The `issues/` directory contains 71 unique MSG files published by reporters in
55 parsing-related issues in the same repository. They were retrieved from the
public issue and comment attachments on 2026-08-21, kept byte-for-byte
unchanged, and grouped by issue number. One additional attached sample from
[issue 497](https://github.com/Sicos1977/MSGReader/issues/497) is byte-identical
to the existing `sender_not_found_from_exchange.msg` fixture and is therefore
not duplicated.

The corpus covers ANSI Outlook files and Windows-1251 Russian text, Japanese
Shift-JIS, Chinese GBK/Big5, Hebrew, malformed and compressed RTF, OLE/storage
attachments, signed messages, appointments, distribution lists, nested
messages, inline images, missing properties, and conflicting codepage tags.
Every directory links directly to its source discussion as
`https://github.com/Sicos1977/MSGReader/issues/<directory-name>`.

The regression suite requires every sample to parse, retain an unchanged
byte-identical round trip, survive a forced fresh serialization and reparse
without semantic loss, and preserve non-editable OLE storages from the source
message. Focused assertions document the reported outcomes for
[31](https://github.com/Sicos1977/MSGReader/issues/31),
[32](https://github.com/Sicos1977/MSGReader/issues/32),
[104](https://github.com/Sicos1977/MSGReader/issues/104),
[198](https://github.com/Sicos1977/MSGReader/issues/198),
[298](https://github.com/Sicos1977/MSGReader/issues/298),
[328](https://github.com/Sicos1977/MSGReader/issues/328),
[402](https://github.com/Sicos1977/MSGReader/issues/402),
[520](https://github.com/Sicos1977/MSGReader/issues/520),
[521](https://github.com/Sicos1977/MSGReader/issues/521),
[529](https://github.com/Sicos1977/MSGReader/issues/529), and
[530](https://github.com/Sicos1977/MSGReader/issues/530).
