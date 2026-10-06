<?php

namespace App\Mail;

/**
 * Aviso al cliente: su membresía quedó suspendida por falta de pago.
 */
class MembershipSuspended extends MembershipNoticeMail
{
    public function build()
    {
        return $this->subject('Tu membresía en ' . $this->gymName . ' está suspendida')
            ->view('emails.membresia_suspendida');
    }
}
