# Outlook MSG for PHP

Read and write Microsoft Outlook `.msg` files with a clean, fluent PHP API.

[![Tests](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/phpunit.yml)
[![Coding Guidelines](https://github.com/cosmira/php-msg/actions/workflows/code-style.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/code-style.yml)
[![Quality Assurance](https://github.com/cosmira/php-msg/actions/workflows/quality.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/quality.yml)
[![Code Coverage](https://github.com/cosmira/php-msg/actions/workflows/coverage.yml/badge.svg)](https://github.com/cosmira/php-msg/actions/workflows/coverage.yml)

It gives you a clean API for subjects, bodies, recipients, attachments, embedded messages, and raw MAPI properties,
while still leaving the low-level pieces available when you need them.

## Installation

```bash
composer require cosmira/outlook-msg
```

## Why You'll Like It

- Read Outlook `.msg` files into a friendly `Message` object
- Work with recipients and attachments through fluent collections
- Access subject, sender, headers, plain text, HTML, and RTF through expressive methods
- Create new `.msg` files with a clean builder API
- Attach regular files, inline files, and embedded `.msg` messages
- Preserve unmapped MAPI properties for round-trip scenarios
- Drop down to low-level compound file and RTF helpers when needed

## Quick Start

### Read a message

```php
use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;

$message = Message::from(file_get_contents('example.msg'));

echo $message->subject();
echo $message->senderName();
echo $message->preferredBody();

$message
    ->attachments()
    ->filter(static fn (Attachment $attachment): bool => $attachment->isInline())
    ->each(static fn (Attachment $attachment): void => print $attachment->contentId());
```

### Create a message

```php
use Cosmira\OutlookMessage\Message;

Message::make()
    ->from('Jane Doe', 'jane@example.com')
    ->to('Abigail', 'abigail@example.com')
    ->subject('Ship it')
    ->text('The plain text body')
    ->save('message.msg');
```

## Reading Messages

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

`Message::from()` and `Message::parse()` are equivalent. If you prefer, the original public properties are still
available too.

## Working With Recipients

```php
use Cosmira\OutlookMessage\Recipient;

$message
    ->to()
    ->each(function (Recipient $recipient) {
        printf("%s <%s>\n", $recipient->name() ?? '', $recipient->email() ?? '');
    });

// Additional recipient groups:
$message->cc()
    ->each(static fn (Recipient $recipient): void => print $recipient->email());

// Formatted header lines from the original message:
echo $message->displayTo();
echo $message->displayCc();
echo $message->displayBcc();
```

## Working With Attachments

```php
use Cosmira\OutlookMessage\Attachment;

$message
    ->attachments()
    ->each(static function (Attachment $attachment, int $index): void {
        $name = $attachment->fileName()
            ?? $attachment->displayName()
            ?? "attachment_{$index}";

        file_put_contents(__DIR__."/out/{$name}", $attachment->content() ?? '');
    });
```

### Inline attachments

```php
use Cosmira\OutlookMessage\Attachment;

$message
    ->attachments()
    ->filter(fn (Attachment $attachment) => $attachment->isInline())
    ->each(fn (Attachment $attachment) => print $attachment->contentId());
```

### Embedded `.msg` attachments

```php
use Cosmira\OutlookMessage\Attachment;

$message
    ->attachments()
    ->filter(fn (Attachment $attachment) => $attachment->embedded() !== null)
    ->each(fn (Attachment $attachment) => print $attachment->embedded()?->subject());
```

## Bodies: HTML, Plain Text, and RTF

If you just want the best available body, use:

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

## Creating Messages

The smoothest writing experience is the fluent builder API:

```php
use DateTimeImmutable;
use DateTimeZone;
use Cosmira\OutlookMessage\Message;

$draft = Message::make()
    ->from('Jane Doe', 'jane@example.com')
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

You can still use `MessageBuilder::make()` and `MessageWriter::make()` directly if you prefer the lower-level writer
entry points.

## Named Payload Constructors

If you want more control, you can build payload objects directly:

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

Known message fields are mapped onto friendly methods. Everything else can still be preserved and inspected through raw
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
php vendor/bin/phpstan analyse --no-progress
```
