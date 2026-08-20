# msgxtractr encoding interoperability fixtures

The three `.msg` files are unchanged copies of
[`hrbrmstr/msgxtractr`](https://github.com/hrbrmstr/msgxtractr/tree/40098e012f98e345848dd784eb41a27996e8ac57/inst/extdata)
at commit `40098e012f98e345848dd784eb41a27996e8ac57` (2021-05-06).
The project declares AGPL and its detailed `inst/COPYRIGHTS` notice is
reproduced as `COPYRIGHTS.txt`.

The upstream `unicode.msg` is excluded because it is byte-identical to the
TeamMsgExtractor fixture already present. The remaining ANSI, default, and
Unicode variants encode the same message and XLSX attachment through different
Outlook serialization modes. The upstream README exercises all three; its
automated R test covers the shared `unicode.msg` plus repeated-open file-handle
behavior. PHP tests require semantic equality across all three variants,
attachment payload identity, and byte-identical unchanged round-trips. R data
frame presentation and native-extension resource management are not portable
API expectations.
