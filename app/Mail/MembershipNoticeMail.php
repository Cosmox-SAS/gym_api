<?php

namespace App\Mail;

use App\Models\Membership;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/**
 * Base de los correos de estado de membresía que envía app:update-membership-status.
 */
abstract class MembershipNoticeMail extends Mailable
{
    use Queueable, SerializesModels;

    public $member;
    public string $gymName;
    public Carbon $fechaVencimiento;

    public function __construct(public Membership $membership)
    {
        $membership->loadMissing(['member.gimnasio', 'plan']);

        $this->member = $membership->member;
        $this->gymName = $membership->member?->gimnasio?->nombre ?? 'tu gimnasio';
        $this->fechaVencimiento = Carbon::parse($membership->end_date);
    }
}
