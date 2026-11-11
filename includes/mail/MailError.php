<?php

namespace HOD\Mail;

use RuntimeException;

/**
 * Something the person can fix or must be told, in plain English: a field to correct (422), no access (403),
 * not found (404), too large (413). Pages and api/mail_* show getMessage() as it is and use $field to put
 * it next to the right input. Never carries a password.
 *
 *   throw MailError::field('local_part', 'That address is already taken.');
 *   throw MailError::denied();
 */
class MailError extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?string $field = null,
        public readonly int $status = 422,
    ) {
        parent::__construct($message, $status);
    }

    public static function field(string $field, string $message): self
    {
        return new self($message, $field, 422);
    }

    public static function invalid(string $message): self
    {
        return new self($message, null, 422);
    }

    public static function denied(string $message = 'This mailbox is not open to you.'): self
    {
        return new self($message, null, 403);
    }

    public static function notFound(string $message = 'Not found: the mailbox or the message may have been removed.'): self
    {
        return new self($message, null, 404);
    }

    public static function tooLarge(string $message): self
    {
        return new self($message, null, 413);
    }
}
