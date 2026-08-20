# Apache Tika MSG fixtures

These fixtures are copied from the Apache Tika test-document corpus at revision
`0ba16e72ae4839b772e91064128f008d407c1241`:

- `test-outlook.msg` — SHA-256 `ba546a434638c46fc6f1edd802c9084b95752afef2fa0aee8301388ab1f8131e`
- `testMSG.msg` — SHA-256 `972901d1a0049ed54dc993ba57f6612db4c370acea818601b2133423885070cf`
- `testMSG_forwarded.msg` — SHA-256 `9f1d5e911e5fa182054e3034f836cd15adc64e1034fefb7026b80ee19280b599`

Source directory:
<https://github.com/apache/tika/tree/0ba16e72ae4839b772e91064128f008d407c1241/tika-parsers/tika-parsers-standard/tika-parsers-standard-modules/tika-parser-microsoft-module/src/test/resources/test-documents>

Only byte-unique Outlook Compound File samples are included. Nine other Tika
MSG fixtures were excluded because they are byte-identical to fixtures already
present in `tests/Fixtures/apache-poi`.

Apache Tika is distributed under the Apache License 2.0:
<https://github.com/apache/tika/blob/0ba16e72ae4839b772e91064128f008d407c1241/LICENSE.txt>
