# Outlook MSG for PHP

Modern PHP library for reading and writing Microsoft Outlook `.msg` files.

[![Tests](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml)
[![Coding Guidelines](https://github.com/cosmira/php-msg/actions/workflows/code-style.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/code-style.yml)
[![Quality Assurance](https://github.com/cosmira/php-msg/actions/workflows/quality.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/quality.yml)
[![Code Coverage](https://github.com/cosmira/php-msg/actions/workflows/coverage.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/coverage.yml)

It gives you a clean high-level API for message content, recipients, attachments, embedded messages, and raw MAPI
properties, while still exposing the low-level building blocks when you need them.

## Installation

```bash
composer require cosmira/outlook-msg
```

## What You Get

- Read Outlook `.msg` files into a friendly `Message` object
- Access subject, sender, recipients, headers, plain text, HTML, and RTF
- Extract regular attachments and embedded `.msg` attachments
- Create new `.msg` files with a fluent builder API
- Preserve unmapped MAPI properties for round-trip scenarios
- Work with low-level compound file and RTF helpers when needed

## Read a Message

```php
use Cosmira\OutlookMessage\Message;

$message = Message::from(
    file_get_contents('example.msg')
);

echo $message->subject();
echo $message->senderName();
echo $message->senderEmail();
echo $message->preferredBody();
```

`Message::from()` and `Message::parse()` are equivalent.

## Work With Recipients

```php
foreach ($message->recipients as $recipient) {
    printf(
        "[%d] %s <%s>\n",
        $recipient->type,
        $recipient->name ?? '',
        $recipient->email ?? ''
    );
}

echo $message->to();
echo $message->cc();
echo $message->bcc();
```

## Work With Attachments

```php
foreach ($message->attachments as $index => $attachment) {
    $name = $attachment->fileName
        ?? $attachment->displayName
        ?? "attachment_{$index}";

    file_put_contents(__DIR__."/out/{$name}", $attachment->content);
}
```

### Inline attachments

```php
foreach ($message->attachments as $attachment) {
    if ($attachment->isInline) {
        echo $attachment->contentId;
    }
}
```

### Embedded `.msg` attachments

```php
foreach ($message->attachments as $attachment) {
    if ($attachment->embedded !== null) {
        echo $attachment->embedded->subject();
    }
}
```

## HTML, Plain Text, and RTF

For the best available body, use:

```php
$body = $message->preferredBody();
```

If you want explicit access:

- `$message->body()` for plain text
- `$message->bodyHtml()` for HTML
- `$message->bodyRtf()` for decompressed RTF text

To work with raw compressed RTF payloads directly:

```php
use Cosmira\OutlookMessage\Rtf\RtfDecompressor;

$rtf = RtfDecompressor::decompress($rawRtfBinary);
```

## Create a Message

The best writing experience is the fluent builder API:

```php
use DateTimeImmutable;
use DateTimeZone;
use Cosmira\OutlookMessage\Message;

$draft = Message::make()
    ->from('Taylor Otwell', 'taylor@example.com')
    ->subject('Ship it')
    ->text('The plain text body')
    ->html('<p>The <strong>HTML</strong> body</p>')
    ->withHeaders("X-App: outlook-msg\r\n")
    ->sentAt(new DateTimeImmutable('2024-01-01 10:00:00', new DateTimeZone('UTC')))
    ->to('Abigail', 'abigail@example.com')
    ->cc('Jess', 'jess@example.com')
    ->bcc('Ops', 'ops@example.com')
    ->attach('notes.txt', 'Remember the meeting at 11:40')
    ->attachInline('logo.png', $logoBinary, 'cid:logo');

$draft->save('message.msg');
```

## Named Payload Constructors

If you want more control, you can create payload objects directly:

```php
use Cosmira\OutlookMessage\Writer\AttachmentPayload;
use Cosmira\OutlookMessage\Writer\RecipientPayload;

$to = RecipientPayload::to('Abigail', 'abigail@example.com');
$cc = RecipientPayload::cc('Jess', 'jess@example.com');
$bcc = RecipientPayload::bcc('Ops', 'ops@example.com');

$file = AttachmentPayload::file('report.pdf', $pdfBinary);
$inline = AttachmentPayload::inline('logo.png', $logoBinary, 'cid:logo');
```

## Embedded Message Attachments

You can attach one `.msg` inside another:

```php
use Cosmira\OutlookMessage\Writer\MessageBuilder;

$nested = MessageBuilder::make()
    ->from('Nested Sender', 'nested@example.com')
    ->subject('Nested message')
    ->text('Hello from inside');

$draft = MessageBuilder::make()
    ->from('Parent Sender', 'parent@example.com')
    ->subject('Parent message')
    ->attachEmbedded($nested, 'forwarded.msg');
```

## Raw MAPI Properties

Known message fields are mapped onto friendly objects. Everything else can still be preserved and inspected via raw
properties:

```php
$raw = $message->rawProperties();
```

This is useful when:

- you need round-trip fidelity
- you care about Outlook-specific metadata not mapped by the library
- you want to inspect or write custom MAPI values

## Low-Level APIs

The package also includes lower-level APIs for advanced scenarios:

- `Cosmira\OutlookMessage\CompoundFile\CompoundFile` for CFBF/OLE storage access
- `Cosmira\OutlookMessage\Support\BinaryBuffer` for binary reads
- `Cosmira\OutlookMessage\Rtf\RtfDecompressor` for compressed RTF payloads

## Testing

```bash
php vendor/bin/phpunit
php vendor/bin/rector process
php vendor/bin/phpstan a src --level=max
```
