<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\MailMessage;

class GlazierWelcomeNotification extends Notification
{
    use Queueable;

    public $password;
    public $loginUrl;

    public function __construct($password)
    {
        $this->password = $password;
        // Frontend Login Page Ka URL (e.g. config/env ya hardcoded portal link)
        $this->loginUrl = config('app.frontend_url', 'https://app.theglasspeople.com/login');
    }

    public function via($notifiable)
    {
        return ['mail'];
    }

    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->subject('Welcome to The Glass People - Your Glazier Account Details')
            ->view('emails.glazier_welcome', [
                'user' => $notifiable,
                'password' => $this->password,
                'loginUrl' => $this->loginUrl,
            ]);
    }
}