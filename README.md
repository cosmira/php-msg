# cosmira/php-msg

[![Tests](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml)

## Introduction

Modern PHP library to work with Microsoft Outlook `.MSG` files (Compound File Binary).

> [!CAUTION]
> This library is currently a **proof of concept (POC)** and **not ready for production use**.

It exposes a high-level for message content, recipients, and attachments, plus low-level APIs for compound file
internals and RTF decompression.

## Features

- Read MSG compound file structure (Header, DIFAT, FAT, mini-FAT, Directory).
- Extract message properties (subject, sender, recipients, headers, body, HTML, RTF).
- Extract attachments (filename, display name, MIME, raw content).
- RTF decompression utility for compressed RTF bodies.
- Binary-safe, uses BigInteger for 64-bit values.
- Create new MSG files with recipients and attachments.

## Installation

Go to the project directory and run the command:

```shell
composer require tabuna/php-msg
```

## Getting Started

To get started, simply parse a `.msg` file into a `Message` object:

```php
use MsgViewer\MessageParser;

$message = MessageParser::parse(
    file_get_contents(__DIR__ . '/example.msg')
);

echo "Subject: {$message->content->subject}" . PHP_EOL;
echo "From: {$message->content->senderName}" . PHP_EOL;
echo "To: {$message->content->toRecipients}" . PHP_EOL;

echo PHP_EOL . "Body:" . PHP_EOL;
echo $message->content->body ?? '(empty)';
```

## Attachments

You can access all message attachments through the `$message->attachments` collection:

```php
foreach ($message->attachments as $index => $attachment) {
    $name = $attachment->fileName ?? $attachment->displayName ?? "attachment_{$index}";
    $path = __DIR__ . "/out/{$name}";

    file_put_contents($path, $attachment->content);

    echo "Saved attachment: {$name} (" . strlen($attachment->content) . " bytes)" . PHP_EOL;
}
```

## HTML and RTF bodies

- `MessageContent->bodyHTML` may already contain HTML.
- `MessageContent->bodyRTF` is the raw (possibly compressed) RTF stream. Use the RTF decompressor if needed.

```php
use MsgViewer\Rtf\RtfDecompressor;

$rtf = $message->content->bodyRTF ?? null;

if ($rtf !== null) {
    $text = RtfDecompressor::decompress($rtf);
    file_put_contents(__DIR__ . '/out/body.rtf', $text);
}
```

## Creating MSG files

The library also includes a simple API for composing new `.MSG` files:

```php
use MsgViewer\Writer\MessageBuilder;
use MsgViewer\Writer\RecipientPayload;
use MsgViewer\Writer\AttachmentPayload;
use MsgViewer\Writer\MessageWriter;

$draft = new MessageBuilder(
    subject: 'Hello',
    senderName: 'Alexandr Chernyaev',
    senderEmail: 'alexandr@example.com',
    bodyPlain: 'Hi Lena!',
    bodyHtml: '<p>Hi Lena!</p>'
);

$draft->recipient(new RecipientPayload('Lena', 'lena@example.com'));
$draft->attachment(new AttachmentPayload(
    fileName: 'note.txt',
    displayName: 'note.txt',
    mimeType: 'text/plain',
    content: "Remember our meeting at 11:40"
));

file_put_contents(
    __DIR__ . '/out/message.msg',
    MessageWriter::write($draft)
);
```

## Testing

```bash
./vendor/bin/phpunit
```
