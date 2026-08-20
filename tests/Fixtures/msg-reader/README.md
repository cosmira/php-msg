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
