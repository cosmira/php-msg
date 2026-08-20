# `MSGKit` writer interoperability assets

`peterpan.jpg` and `tinkerbell.jpg` are unchanged copies of the writer-demo
assets from
[`Sicos1977/MSGKit`](https://github.com/Sicos1977/MSGKit/tree/7d195fde49cea355877b320e721df2114e108784/MsgKitTestTool/Images)
at commit `7d195fde49cea355877b320e721df2114e108784`.

MSGKit is MIT licensed. It does not contain a unit-test project or committed
`.msg` output fixtures; its authoritative executable example is the test-tool
writer scenario. `tests/Writer/MsgKitCompatibilityTest.php` adapts the common
email portion of that API and verifies the result through this library's
writer and parser.

The mapped scope includes sender and represented identity, recipients,
importance and priority, draft/read-receipt state, submission and delivery
times, editor/icon hints, RFC threading identifiers, plain/HTML/RTF bodies,
regular/inline/embedded attachments, and collection removal.

MSGKit's Appointment, Contact, and Task APIs are intentionally not represented
as partial `MessageBuilder` methods: those object types require their own
message classes and named-property schemas. Its structured reply-to collection,
transport receiving/receiving-representing identities, and by-reference/link
attachments are also not claimed by this compatibility test; they require
additional MAPI structures rather than a safe one-property fluent alias.
