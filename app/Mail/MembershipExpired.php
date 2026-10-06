<?php

namespace App\Mail;

/**
 * Aviso al cliente: su membresía venció.
 */
class MembershipExpired extends MembershipNoticeMail
{
    public function build()
    {
        return $this->subject('⏰ Tu membresía en ' . $this->gymName . ' venció')
            ->view('emails.membresia_vencida');
    }
}
