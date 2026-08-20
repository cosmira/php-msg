# jotlmsg reader/writer interoperability fixtures

All ten `.msg` files are unchanged copies of
[`ctabin/jotlmsg`](https://github.com/ctabin/jotlmsg/tree/f2e77ec462c19083e4eb11e5addfe8020643945d/src/test/resources/ch/astorm/jotlmsg)
at commit `f2e77ec462c19083e4eb11e5addfe8020643945d`
(2026-06-30). The upstream BSD-3-Clause license is reproduced as
`LICENSE.BSD-3-Clause.txt`.

The five `generated` files are golden outputs from jotlmsg's Apache POI based
writer. They cover an empty envelope, mixed To/Cc/Bcc recipients, three text
and HTML attachments, 40 recipients, and 40 attachments. The five
`msoutlook` files were created by Outlook and cover ANSI bodies, recipient
groups, a sent timestamp, a regular attachment, and two Reply-To entries.
All ten SHA-256 values are unique both within the upstream set and across the
173 MSG fixtures that preceded this import.

The referenced commit has 35 JUnit tests. PHP adaptations cover every
committed MSG, all portable field and payload expectations, semantic parity
between the jotlmsg and PHP writers, and byte-identical unchanged round-trips.
Jakarta Mail conversion, Java stream lifecycle, Apache POI structures, and
Java helper serialization are implementation-specific. This library does not
currently expose jotlmsg's structured Reply-To collection, so the Reply-To
fixture is retained as a preservation/round-trip case without claiming that
API capability.
