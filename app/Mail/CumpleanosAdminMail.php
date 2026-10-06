<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class CumpleanosAdminMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param Collection $hoy      Cumpleaños de hoy (formato de BirthdayService::upcoming)
     * @param Collection $proximos Cumpleaños de los próximos días
     */
    public function __construct(
        public string $adminName,
        public string $gymName,
        public Collection $hoy,
        public Collection $proximos,
    ) {
    }

    public function build()
    {
        $subject = $this->hoy->count() === 1
            ? "🎂 Hoy cumple años {$this->hoy->first()['name']}"
            : "🎂 Hoy cumplen años {$this->hoy->count()} clientes";

        return $this->subject($subject)->view('emails.cumpleanos_admin');
    }
}
