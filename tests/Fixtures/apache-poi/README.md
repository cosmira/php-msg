# Apache POI HSMF interoperability fixtures

The 41 files in this directory's root are unchanged copies of
[`test-data/hsmf`](https://github.com/apache/poi/tree/29c9cacc354613eedf6c7ee24007a597f64c1951/test-data/hsmf)
from Apache POI commit `29c9cacc354613eedf6c7ee24007a597f64c1951`
(2026-08-17). `poifs/MailSentPropertyMultiple.msg` and
`poifs/unknown_properties.msg` come from the same commit's `test-data/poifs`
directory and are referenced directly by HSMF tests.

The files are distributed under Apache License 2.0. The upstream `LICENSE` and
`NOTICE` files are reproduced beside this document.

## Coverage audit

The commit contains 88 JUnit test methods across 18 HSMF test classes. The PHP
tests adapt the externally observable MSG scenarios: ANSI/message/HTML
codepages, locale fallback, subjects and bodies, transport headers, sender and
recipient data, all six message classes, conversation topics, submission IDs,
regular/inline/embedded attachments, named-property storage, large recipient
sets, malformed Compound File rejection, and byte-identical unchanged
round-trips.

Apache POI labels its 1979/1980 submission-ID expectations as known Y2K
failures and currently maps them to 2079/2080. These PHP tests intentionally
apply the conventional two-digit-year pivot and require the correct
1979/1980/1981 sequence instead.

Tests for Java chunk constructors, enum tables, sort comparator internals,
POIFS object identity, extractor-specific text formatting, and mutable POIFS
reconstruction are implementation-specific and are not copied as PHP tests.
The assertions here were written independently against this library's public
API; upstream expected values and fixture selection are used as interoperability
oracles.

Two minimized HSMF fuzzer cases and the upstream-disabled
`unknown_properties.msg` are structurally malformed and are asserted to fail
with a public `ParseException`. The other 40 fixtures are required to parse and
round-trip unchanged byte-for-byte.
