#!/usr/bin/env php
<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use MsgViewer\MsgParser;

if ($argc < 2) {
    fwrite(STDERR, "Usage: parse-msg.php <path-to-msg>\n");
    exit(1);
}

$path = $argv[1];
if (! is_file($path)) {
    fwrite(STDERR, "File not found: {$path}\n");
    exit(1);
}

$binary = file_get_contents($path);
if ($binary === false) {
    fwrite(STDERR, "Unable to read file: {$path}\n");
    exit(1);
}

$message = MsgParser::parse($binary);

echo 'Subject: '.($message->content->subject ?? '(none)').PHP_EOL;
echo 'From: '.($message->content->senderName ?? '(unknown)').PHP_EOL;
echo 'Recipients: '.($message->content->toRecipients ?? '(none)').PHP_EOL;
echo 'Attachments: '.count($message->attachments).PHP_EOL;
