<?php

namespace App\Enums;

/** A change to sign-in details, which the account owner is always told about by email. */
enum AccountChange: string
{
    case PasswordChanged = 'PASSWORD_CHANGED';
    case PasswordReset = 'PASSWORD_RESET';
    case EmailChanged = 'EMAIL_CHANGED';
}
