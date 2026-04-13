# cosmira/php-msg

[![Tests](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml)
[![Coding Guidelines](https://github.com/cosmira/php-msg/actions/workflows/code-style.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/code-style.yml)

## Introduction

Modern PHP library to work with Microsoft Outlook `.MSG` files (Compound File Binary).

> [!CAUTION]
> This library is currently a **proof of concept (POC)** and **not ready for production use**.

It exposes a high-level API for message content, recipients, and attachments, plus low-level APIs for compound file internals and RTF decompression.

## Features

- Read MSG compound file structure (Header, DIFAT, FAT, mini-FAT, Directory).
- Extract message properties (subject, sender, recipients, headers, body, HTML, RTF).
- Extract attachments (filename, display name, MIME, raw content).
- Recursively parse embedded `.msg` attachments.
- RTF decompression utility for compressed RTF bodies.
- Binary-safe, uses BigInteger for 64-bit values.
- Create new MSG files with recipients and attachments.

## Installation

```shell
composer require tabuna/php-msg
```

## Getting Started

Parse a `.msg` file using `Message::parse()` or `MessageParser::parse()`:

```php
use MsgViewer\Message;

$message = Message::parse(file_get_contents('example.msg'));

echo "Subject: {$message->content->subject}";
echo "From:    {$message->content->senderName} <{$message->content->senderEmail}>";
echo "To:      {$message->content->to}";
echo "Body:    " . ($message->content->body ?? '(empty)');
```

## Attachments

```php
foreach ($message->attachments as $index => $attachment) {
    $name = $attachment->fileName ?? $attachment->displayName ?? "attachment_{$index}";

    file_put_contents(__DIR__ . "/out/{$name}", $attachment->content);
}
```

### Embedded MSG attachments

When an attachment is itself a `.msg` file, it is automatically parsed and available as `$attachment->embedded`:

```php
foreach ($message->attachments as $attachment) {
    if ($attachment->embedded !== null) {
        echo "Embedded message subject: {$attachment->embedded->content->subject}";
    }
}
```

You can also inspect the full nesting tree:

```php
$tree = $message->toArray();
```

## HTML and RTF bodies

- `$message->content->bodyHtml` — HTML body, if present.
- `$message->content->bodyRtf` — raw (possibly compressed) RTF stream.

```php
use MsgViewer\Rtf\RtfDecompressor;

if ($message->content->bodyRtf !== null) {
    $rtf = RtfDecompressor::decompress($message->content->bodyRtf);
    file_put_contents(__DIR__ . '/out/body.rtf', $rtf);
}
```

## Recipients

```php
foreach ($message->recipients as $recipient) {
    echo "{$recipient->name} <{$recipient->email}>";
}
```

## Creating MSG files

```php
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\MessageWriter;
use MsgViewer\Writer\RecipientPayload;
use MsgViewer\Writer\AttachmentPayload;

$draft = new MessageBuilder(
    subject: 'Hello',
    senderName: 'Alexandr Chernyaev',
    senderEmail: 'alexandr@example.com',
    body: 'Hi Lena!',
    bodyHtml: '<p>Hi Lena!</p>',
);

$draft->recipient(new RecipientPayload('Lena', 'lena@example.com'));
$draft->attachment(new AttachmentPayload(
    fileName: 'note.txt',
    displayName: 'note.txt',
    mimeType: 'text/plain',
    content: 'Remember our meeting at 11:40',
));

file_put_contents('message.msg', MessageWriter::write($draft));
```

## Testing

```bash
./vendor/bin/phpunit
```
