<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Message;
use WeakMap;

/**
 * Source CFB payload retained only for parsed-message round trips.
 *
 * @internal
 */
final class MessageStorageMetadata
{
    /**
     * The source metadata associated with parsed message instances.
     *
     * @var WeakMap<Message, array{binary: string, fingerprint: string, attachments: list<string>, recipients: list<string>}>|null
     */
    private static ?WeakMap $messages = null;

    /**
     * The source metadata copied to message builder instances.
     *
     * @var WeakMap<MessageBuilder, array{binary: string, fingerprint: string, attachments: list<string>, recipients: list<string>}>|null
     */
    private static ?WeakMap $builders = null;

    /**
     * Remember the original CFB payload and semantic state for a parsed message.
     */
    public static function remember(Message $message, string $binary): void
    {
        self::$messages ??= new WeakMap();
        self::$messages[$message] = [
            'binary'      => $binary,
            'fingerprint' => MessageBuilderFingerprint::forMessage($message),
            'attachments' => MessageBuilderFingerprint::attachmentsForMessage($message),
            'recipients'  => MessageBuilderFingerprint::recipientsForMessage($message),
        ];
    }

    /**
     * Carry the original payload metadata onto a builder created from the message.
     */
    public static function copyToBuilder(Message $message, MessageBuilder $builder): void
    {
        $metadata = self::$messages[$message] ?? null;
        if (! is_array($metadata)) {
            return;
        }

        self::$builders ??= new WeakMap();
        self::$builders[$builder] = [
            'binary'      => $metadata['binary'],
            'fingerprint' => $metadata['fingerprint'],
            'attachments' => $metadata['attachments'],
            'recipients'  => $metadata['recipients'],
        ];
    }

    /**
     * Retrieve the original CFB payload associated with the given builder.
     */
    public static function forBuilder(MessageBuilder $builder): ?string
    {
        return self::$builders[$builder]['binary'] ?? null;
    }

    /**
     * Determine whether the builder still represents its original parsed message.
     */
    public static function isUnchanged(MessageBuilder $builder): bool
    {
        $metadata = self::$builders[$builder] ?? null;

        return is_array($metadata)
            && hash_equals($metadata['fingerprint'], MessageBuilderFingerprint::make($builder));
    }

    /**
     * Return source attachment indexes whose complete editable state is unchanged.
     *
     * @return list<int>
     */
    public static function unchangedAttachmentIndexes(MessageBuilder $builder): array
    {
        if ($builder->sourceAttachmentsFlushed()) {
            return [];
        }

        $source = self::$builders[$builder]['attachments'] ?? [];
        $current = MessageBuilderFingerprint::attachmentsForBuilder($builder);

        return self::matchingIndexes($source, $current);
    }

    /**
     * Return source recipient indexes whose complete editable state is unchanged.
     *
     * @return list<int>
     */
    public static function unchangedRecipientIndexes(MessageBuilder $builder): array
    {
        $source = self::$builders[$builder]['recipients'] ?? [];
        $current = MessageBuilderFingerprint::recipientsForBuilder($builder);

        return self::matchingIndexes($source, $current);
    }

    /**
     * @param list<string> $source
     * @param list<string> $current
     *
     * @return list<int>
     */
    private static function matchingIndexes(array $source, array $current): array
    {
        $matches = [];
        foreach ($current as $index => $fingerprint) {
            if (isset($source[$index]) && hash_equals($source[$index], $fingerprint)) {
                $matches[] = $index;
            }
        }

        return $matches;
    }
}
