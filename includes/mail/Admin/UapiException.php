<?php

namespace HOD\Mail\Admin;

use RuntimeException;

/** A refusal or failure of a cPanel call; the message is safe to show (never carries a password) */
class UapiException extends RuntimeException
{
}
