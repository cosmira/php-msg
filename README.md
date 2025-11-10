# Msg Viewer (PHP)

Modern PHP 8.2 library to parse Microsoft Outlook .MSG files (Compound File Binary). It exposes a high-level `MsgParser` for message content, recipients, and attachments, plus low-level APIs for compound file internals and RTF decompression.

## Features
- Read MSG compound file structure (Header, DIFAT, FAT, mini-FAT, Directory).
- Extract message properties (subject, sender, recipients, headers, body, HTML, RTF).
- Extract attachments (filename, display name, MIME, raw content).
- RTF decompression utility for compressed RTF bodies.
- Binary-safe, uses BigInteger for 64-bit values.

## Requirements
- PHP >= 8.2
- ext-mbstring (string encodings)
- ext-dom (for HTML template utility, optional)

## Installation

Inside `php/` directory (this repo layout), run:

```bash
composer install
```

If you plan to integrate as a dependency in another project (after publishing to a VCS or Packagist), add to your application's `composer.json`:

```json
{
  "require": {
    "tabuna/msg-viewer": "^1.0"
  }
}
```

Then run:

```bash
composer update
```

## Quick start

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use MsgViewer\MsgParser;

$path = __DIR__ . '/example.msg';
$binary = file_get_contents($path);

$message = MsgParser::parse($binary);

echo "Subject: " . ($message->content->subject ?? '(none)') . PHP_EOL;
echo "From: " . ($message->content->senderName ?? '(unknown)') . PHP_EOL;
echo "To: " . ($message->content->toRecipients ?? '(none)') . PHP_EOL;
echo "CC: " . ($message->content->ccRecipients ?? '(none)') . PHP_EOL;
echo "Date: " . ($message->content->date?->format('c') ?? '(unknown)') . PHP_EOL;

echo PHP_EOL . "Plain Body:" . PHP_EOL;
echo $message->content->body ?? '(empty)';
```

## Attachments

```php
foreach ($message->attachments as $i => $a) {
  $name = $a->fileName ?? $a->displayName ?? ("attachment_" . $i);
  $mime = $a->mimeType ?? 'application/octet-stream';
  $bytes = $a->content ?? '';

  file_put_contents(__DIR__ . "/out/{$name}", $bytes);
  echo "Saved attachment #{$i}: {$name} ({$mime}), bytes=" . strlen($bytes) . PHP_EOL;
}
```

## HTML and RTF bodies

- `MessageContent->bodyHTML` may already contain HTML.
- `MessageContent->bodyRTF` is the raw (possibly compressed) RTF stream. Use the RTF decompressor if needed.

```php
use MsgViewer\Rtf\RtfDecompressor;

$rtf = $message->content->bodyRTF ?? null;
if ($rtf !== null) {
  // $rtf is binary string; pass to decompressor
  $rtfText = RtfDecompressor::decompress($rtf);
  file_put_contents(__DIR__ . '/out/body.rtf', $rtfText);
}
```

## Creating MSG files

Use the writer API to compose a new message and serialize it back to the MSG container:

```php
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\RecipientPayload;
use MsgViewer\Writer\AttachmentPayload;
use MsgViewer\Writer\MessageWriter;

$draft = new MessageBuilder(
    subject: 'Hello',
    senderName: 'Alice Sender',
    senderEmail: 'alice@example.com',
    bodyPlain: 'Hi Bob!',
    bodyHtml: '<p>Hi Bob!</p>'
);

$draft->recipient(new RecipientPayload('Bob', 'bob@example.com'));
$draft->attachment(new AttachmentPayload(
    fileName: 'note.txt',
    displayName: 'note.txt',
    mimeType: 'text/plain',
    content: "Remember our meeting at 10."
));

$binary = MessageWriter::write($draft);
file_put_contents(__DIR__ . '/out/message.msg', $binary);
```

## CLI usage

The package ships with a simple CLI helper to print basic info:

```bash
php bin/parse-msg.php /path/to/message.msg
```

Output example:

```
Subject: Example subject
From: John Doe
Recipients: alice@example.com; bob@example.com
Attachments: 2
```

## API Overview

- `MsgViewer\MsgParser::parse(string $binary): MsgViewer\Message\Message`
  - `Message->content` (`MessageContent`):
    - `subject`, `senderName`, `senderEmail`, `headers`
    - `toRecipients`, `ccRecipients`
    - `date` (DateTimeImmutable|null)
    - `body` (plain text), `bodyHTML` (HTML), `bodyRTF` (binary string)
  - `Message->attachments` (array of `Attachment`):
    - `fileName`, `displayName`, `extension`, `mimeType`, `language`
    - `content` (binary string)
    - `embeddedMsgObj` (low-level directory entry if present)
  - `Message->recipients` (array of `Recipient`):
    - `name`, `email`

Advanced (low-level) types for interacting with the compound file:

- `MsgViewer\CompoundFile\CompoundFile`
- `MsgViewer\CompoundFile\Directory\Directory` and `DirectoryEntry`

## Testing

```bash
./vendor/bin/phpunit
```

## Notes

- This library aims for read-only parsing of MSG files.
- If you parse non-UTF8 code pages, ensure `ext-mbstring` is enabled (used for internal conversions).


