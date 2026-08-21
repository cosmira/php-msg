<?php

declare(strict_types=1);

namespace Cosmira\OutlookMessage\Writer;

use Cosmira\OutlookMessage\Message;
use Cosmira\OutlookMessage\Support\BinarySource;
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
     * @var WeakMap<Message, SourceMessageMetadata>|null
     */
    private static ?WeakMap $messages = null;

    /**
     * The source metadata copied to message builder instances.
     *
     * @var WeakMap<MessageBuilder, SourceMessageMetadata>|null
     */
    private static ?WeakMap $builders = null;

    /**
     * Remember the original CFB payload and semantic state for a parsed message.
     */
    public static function remember(Message $message, string $binary): void
    {
        self::rememberSource($message, $binary);
    }

    /**
     * Remember an original CFB source and semantic state for a parsed message.
     */
    public static function rememberSource(Message $message, string|BinarySource $source): void
    {
        self::$messages ??= new WeakMap();
        self::$messages[$message] = new SourceMessageMetadata(
            $source,
            MessageBuilderFingerprint::forMessage($message),
            MessageBuilderFingerprint::attachmentsForMessage($message),
            MessageBuilderFingerprint::recipientsForMessage($message),
        );
    }

    /**
     * Carry the original payload metadata onto a builder created from the message.
     */
    public static function copyToBuilder(Message $message, MessageBuilder $builder): void
    {
        $metadata = self::$messages[$message] ?? null;
        $hasMetadata = $metadata instanceof SourceMessageMetadata;

        if (! $hasMetadata) {
            return;
        }

        self::$builders ??= new WeakMap();
        self::$builders[$builder] = $metadata;
    }

    /**
     * Retrieve the original CFB payload associated with the given builder.
     */
    public static function forBuilder(MessageBuilder $builder): string|BinarySource|null
    {
        $metadata = self::$builders[$builder] ?? null;

        return $metadata instanceof SourceMessageMetadata ? $metadata->binary : null;
    }

    /**
     * Determine whether the builder still represents its original parsed message.
     */
    public static function isUnchanged(MessageBuilder $builder): bool
    {
        $metadata = self::$builders[$builder] ?? null;

        $hasMetadata = $metadata instanceof SourceMessageMetadata;

        if (! $hasMetadata) {
            return false;
        }

        return hash_equals($metadata->fingerprint, MessageBuilderFingerprint::make($builder));
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

        $metadata = self::$builders[$builder] ?? null;
        $source = $metadata instanceof SourceMessageMetadata ? $metadata->attachments : [];
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
        $metadata = self::$builders[$builder] ?? null;
        $source = $metadata instanceof SourceMessageMetadata ? $metadata->recipients : [];
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
            $candidate = $source[$index] ?? null;

            $isMatch = is_string($candidate) && hash_equals($candidate, $fingerprint);

            if ($isMatch) {
                $matches[] = $index;
            }
        }

        return $matches;
    }
}
