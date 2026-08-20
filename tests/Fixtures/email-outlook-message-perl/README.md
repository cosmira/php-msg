# Email::Outlook::Message interoperability fixtures

All six `.msg` files are unchanged copies of
[`mvz/email-outlook-message-perl`](https://github.com/mvz/email-outlook-message-perl/tree/8a29797d80021537be44075ebaae34f77ba8305d/t/files)
at commit `8a29797d80021537be44075ebaae34f77ba8305d` (2026-08-02).
The upstream README licenses the project under the same terms as Perl itself;
its copyright and license notice is reproduced as `LICENSE.NOTICE.txt`.

The upstream suite has nine fixture/parser test files. Its six MSG inputs and
their portable expectations are represented here: ANSI and Unicode bodies,
a non-ASCII wide character, CP1252 punctuation, plain and RTF alternatives,
JPEG payload bytes, and a PGP-signature payload stored in an S/MIME attachment.
The paired `.eml` files are upstream conversion outputs, not additional MSG
inputs, so they are used as semantic oracles and are not copied.

MIME boundary generation, exact generated header counts, Perl object
construction, POD coverage, and private helper behavior are implementation
specific. The Perl converter also chooses an unsent item's MAPI creation time
for its generated `Date` header, while this library's `date()` API represents
submission/delivery time; that conversion-policy difference is not asserted as
binary parsing equivalence.
