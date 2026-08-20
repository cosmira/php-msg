# ruby-msg interoperability fixtures

All eight `.msg` files are unchanged copies of
[`aquasync/ruby-msg`](https://github.com/aquasync/ruby-msg/tree/a31ffaff0e0f4064e9c51bb5b6d9898671c31a5a/test)
at commit `a31ffaff0e0f4064e9c51bb5b6d9898671c31a5a` (2026-02-27).
The upstream MIT license is reproduced as `COPYING.txt`.

The repository has 14 Ruby tests. Its direct MSG test validates a Post item
and custom named-property namespaces; other tests cover property sets, contact
to-vCard conversion, RTF/MIME conversion, and helpers. The PHP adaptation
checks all eight committed binaries, message classes, ANSI/Unicode contacts,
sticky notes, recipient changes across custom-property variants, preserved
name-ID streams, and byte-identical unchanged round-trips. vCard/MIME output
and Ruby property lookup syntax have no matching public API here.
