<?php

namespace App\Mail;

/**
 * Aviso al cliente: su membresía vence en 3 días.
 */
class MembershipExpiringSoon extends MembershipNoticeMail
{
    public function build()
    {
        return $this->subject('💪 Te queremos seguir viendo en - ' . $this->gymName)
            ->view('emails.aviso_cliente');
    }
}
