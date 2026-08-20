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
- Replace every attachment while preserving the original message and Outlook metadata
- Preserve unmapped MAPI properties for round-trip scenarios
- Drop down to low-level compound file and RTF helpers when needed

## Quick Start

### Read a message

```php
use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;

$message = Message::fromPath('example.msg');

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

$message = Message::fromPath('example.msg');

echo $message->subject();
echo $message->senderName();
echo $message->senderEmail();
echo $message->preferredBody();
```

Use `Message::from($binary)` for an in-memory MSG payload and `Message::fromPath($path)` for a file.

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
        $name = $attachment->name() ?? "attachment_{$index}";

        file_put_contents(__DIR__."/out/{$name}", $attachment->data());
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
    ->filter(fn (Attachment $attachment) => $attachment->isEmbedded())
    ->each(fn (Attachment $attachment) => print $attachment->message()?->subject());
```

### Replacing attachment payloads

Parsed messages are edited directly. `data()` presents both regular files and embedded messages as bytes, so recursive
processors do not need separate branches:

```php
foreach ($message->attachments() as $attachment) {
    $attachment->withData(
        $processor($attachment->data())
    );
}

$message->save('processed.msg');
```

Reference, storage, and web-reference attachment methods throw a typed
`UnsupportedAttachmentMethodException` when their payload is read or replaced.

### Replacing every attachment

To remove all imported attachments and add replacements while preserving the
rest of the original message:

```php
use Cosmira\OutlookMessage\Message;

$message = Message::fromPath('original.msg');

$message->toBuilder()
    ->flushAttachments()
    ->attachPath('replacement.pdf', 'application/pdf')
    ->save('with-replacement.msg');
```

Use `attachData()` instead of `attachPath()` when the replacement is already in
memory. `flushAttachment()` is available as a singular fluent alias.

Calling `flushAttachments()` is important for parsed messages: it prevents
obsolete attachment storages from being restored with preserved opaque Outlook
metadata. `withoutAttachments()` remains a compatible alias.

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
use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\MessageEditorFormat;
use Cosmira\OutlookMessage\MessageImportance;
use Cosmira\OutlookMessage\MessagePriority;

$draft = Message::make()
    ->from('Jane Doe', 'jane@example.com')
    ->representedBy('Jane on behalf of Support', 'support@example.com')
    ->subject('Ship it')
    ->text('The plain text body')
    ->html('<p>The <strong>HTML</strong> body</p>')
    ->withHeaders("X-App: outlook-msg\r\n")
    ->sentAt(new DateTimeImmutable('2024-01-01 10:00:00', new DateTimeZone('UTC')))
    ->receivedAt(new DateTimeImmutable('2024-01-01 10:01:00', new DateTimeZone('UTC')))
    ->importance(MessageImportance::High)
    ->priority(MessagePriority::Urgent)
    ->requestReadReceipt()
    ->editorFormat(MessageEditorFormat::Html)
    ->messageId('<message@example.com>')
    ->inReplyTo('<parent@example.com>')
    ->references('<first@example.com> <parent@example.com>')
    ->to('Abigail', 'abigail@example.com')
    ->cc('Jess', 'jess@example.com')
    ->bcc('Ops', 'ops@example.com')
    ->attach(Attachment::fromData('Remember the meeting at 11:40', 'notes.txt'))
    ->attach(Attachment::fromData($logoBinary, 'logo.png')->inline('cid:logo'));

$draft->save('message.msg');
```

Messages created by `Message::make()` remain drafts by default. Use
`->draft(false)` for a submitted/sent message. Convenience methods
`attachData()`, `attachPath()`, `attachMessage()`, and `inlineData()` mirror
the corresponding `Attachment` constructors; `detach()`,
`withoutAttachments()`, and `withoutRecipients()` edit the collections.
Non-mail Outlook objects and imported server metadata can be retained with
`messageClass()`, `conversationTopic()`, and `submissionId()`; parsed messages
expose the corresponding getters with the same names except
`messageSubmissionId()` for the raw submission identifier.

You can still use `MessageBuilder::make()` and `MessageWriter::make()` directly if you prefer the lower-level writer
entry points.

## Attachment Objects

The same `Attachment` object is used when reading, editing, and creating messages:

```php
use Cosmira\OutlookMessage\Attachment;

$report = Attachment::fromData($pdfBinary, 'report.pdf')
    ->withMime('application/pdf');

$lazy = Attachment::fromData(fn () => loadReport(), 'report.pdf');
$fromDisk = Attachment::fromPath('/tmp/report.pdf');
$inline = Attachment::fromData($logoBinary, 'logo.png')->inline('cid:logo');

Message::make()->attach($report);
```

## Embedded Message Attachments

You can attach one `.msg` inside another:

```php
use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\Message;

$nested = Message::from(Message::make()
    ->from('Nested Sender', 'nested@example.com')
    ->subject('Nested message')
    ->text('Hello from inside')
    ->toBinary());

$draft = Message::make()
    ->from('Parent Sender', 'parent@example.com')
    ->subject('Parent message')
    ->attach(Attachment::fromMessage($nested, 'forwarded.msg'));

$embedded = $draft->attachments()[0]->message();
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
