<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Attachment;
use Cosmira\OutlookMessage\AttachmentMethod;
use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\RawProperty;
use Cosmira\OutlookMessage\Recipient;

/**
 * @internal
 */
final class MessageBuilderFingerprint
{
    /**
     * Fingerprint each attachment currently assigned to a builder.
     *
     * @return list<string>
     */
    public static function attachmentsForBuilder(MessageBuilder $builder): array
    {
        return array_values(array_map(
            static fn (Attachment $attachment): string => hash('sha256', serialize(self::attachment($attachment))),
            $builder->attachments(),
        ));
    }

    /**
     * Fingerprint each attachment parsed from a source message.
     *
     * @return list<string>
     */
    public static function attachmentsForMessage(Message $message): array
    {
        return array_values(array_map(
            static fn (Attachment $attachment): string => hash('sha256', serialize(self::attachment($attachment))),
            $message->attachments,
        ));
    }

    /**
     * Fingerprint each recipient currently assigned to a builder.
     *
     * @return list<string>
     */
    public static function recipientsForBuilder(MessageBuilder $builder): array
    {
        return array_values(array_map(
            static fn (RecipientPayload $recipient): string => hash('sha256', serialize(self::recipient($recipient))),
            $builder->recipients(),
        ));
    }

    /**
     * Fingerprint each recipient parsed from a source message.
     *
     * @return list<string>
     */
    public static function recipientsForMessage(Message $message): array
    {
        return array_values(array_map(
            static fn (Recipient $recipient): string => hash('sha256', serialize([
                $recipient->name,
                $recipient->email,
                $recipient->type,
                array_map(self::rawProperty(...), $recipient->rawProperties),
            ])),
            $message->recipients,
        ));
    }

    /**
     * Create a stable semantic fingerprint for the given message builder.
     */
    public static function make(MessageBuilder $builder): string
    {
        return hash('sha256', serialize([
            $builder->subject,
            $builder->senderName,
            $builder->senderEmail,
            $builder->body,
            $builder->bodyHtml,
            $builder->bodyRtf,
            $builder->headers,
            $builder->date?->format('U.uP'),
            $builder->bodyRtfCompressed,
            $builder->receivedAt?->format('U.uP'),
            $builder->representingName,
            $builder->representingEmail,
            $builder->importance,
            $builder->priority,
            $builder->draft,
            $builder->readReceiptRequested,
            $builder->iconIndex,
            $builder->editorFormat,
            $builder->internetMessageId,
            $builder->internetReferences,
            $builder->inReplyToId,
            $builder->messageClass,
            $builder->conversationTopic,
            $builder->shouldDeriveConversationTopic(),
            $builder->messageSubmissionId,
            $builder->codepage,
            $builder->messageLocaleId,
            array_map(self::recipient(...), $builder->recipients()),
            array_map(self::attachment(...), $builder->attachments()),
            array_map(self::rawProperty(...), $builder->rawProperties()),
            $builder->nameIdStreams(),
        ]));
    }

    /**
     * Create a stable semantic fingerprint for a parsed message.
     */
    public static function forMessage(Message $message): string
    {
        return hash('sha256', serialize(self::message($message)));
    }

    /**
     * Normalize a recipient into a deterministic fingerprint payload.
     *
     * @return array{?string, ?string, int, list<array<mixed>>}
     */
    private static function recipient(RecipientPayload $recipient): array
    {
        return [
            $recipient->name,
            $recipient->email,
            $recipient->type,
            array_values(array_map(self::rawProperty(...), $recipient->rawProperties)),
        ];
    }

    /**
     * Normalize an attachment and its editable payload into fingerprint data.
     *
     * @return array<mixed>
     */
    private static function attachment(Attachment $attachment): array
    {
        return [
            $attachment->name(),
            $attachment->displayName(),
            $attachment->extension(),
            $attachment->mime(),
            $attachment->language(),
            $attachment->contentId(),
            $attachment->isInline(),
            $attachment->method()?->value,
            self::attachmentDataHash($attachment),
            self::embeddedMessage($attachment),
            array_map(self::rawProperty(...), $attachment->rawProperties()),
            AttachmentStorageMetadata::renderingPosition($attachment),
        ];
    }

    /**
     * Hash the editable payload of a by-value attachment.
     */
    private static function attachmentDataHash(Attachment $attachment): ?string
    {
        if ($attachment->method() !== AttachmentMethod::ByValue) {
            return null;
        }

        return hash('sha256', $attachment->data());
    }

    /**
     * Normalize the embedded message carried by an attachment when available.
     *
     * @return array<mixed>|null
     */
    private static function embeddedMessage(Attachment $attachment): ?array
    {
        $message = $attachment->message();
        if (! $message instanceof Message) {
            return null;
        }

        return self::message($message);
    }

    /**
     * Normalize an embedded message into a deterministic fingerprint payload.
     *
     * @return array<mixed>
     */
    private static function message(Message $message): array
    {
        return [
            $message->subject(),
            $message->actualSenderName(),
            $message->actualSenderEmail(),
            $message->body(),
            $message->bodyHtml(),
            $message->bodyRtf(),
            $message->headers(),
            $message->date()?->format('U.uP'),
            $message->content->bodyRtfCompressed,
            $message->receivedAt()?->format('U.uP'),
            $message->representingName(),
            $message->representingEmail(),
            $message->content->importance,
            $message->content->priority,
            $message->isDraft(),
            $message->readReceiptRequested(),
            $message->iconIndex(),
            $message->content->editorFormat,
            $message->internetMessageId(),
            $message->internetReferences(),
            $message->inReplyToId(),
            $message->messageClass() ?? 'IPM.Note',
            $message->conversationTopic(),
            $message->conversationTopic() !== null,
            $message->messageSubmissionId(),
            $message->content->codepage,
            $message->content->messageLocaleId,
            array_map(static fn (Recipient $recipient): array => [
                $recipient->name,
                $recipient->email,
                $recipient->type,
                array_map(self::rawProperty(...), $recipient->rawProperties),
            ], $message->recipients),
            array_map(self::attachment(...), $message->attachments),
            array_map(self::rawProperty(...), $message->rawProperties),
            $message->nameIdStreams,
        ];
    }

    /**
     * Normalize a raw MAPI property without altering its stored value.
     *
     * @return array{string, int, mixed, int}
     */
    private static function rawProperty(RawProperty $property): array
    {
        return [$property->id, $property->typeId, $property->value, $property->flags];
    }
}
