<?php

declare(strict_types=1);

namespace App\Workforce\Application\Supervision;

/**
 * The words that travel with each link. The application decides what to say;
 * the browser decides whether it goes through the share sheet, WhatsApp or the
 * clipboard. Nothing here knows about WhatsApp.
 */
final class SupervisionShareMessages
{
    public static function invitationPath(string $token): string
    {
        return '/invitacion/responsable/'.$token;
    }

    public static function verificationPath(string $token): string
    {
        return '/app/equipo/responsable/verificar/'.$token;
    }

    public static function invitation(string $teamLabel, string $workplaceName, string $url): string
    {
        return "Hola, estamos usando Turnin para gestionar nuestros cambios de turno.\n\n"
            .'Puedes entrar como responsable de '.self::team($teamLabel, $workplaceName)." desde aquí:\n\n"
            .$url."\n\n"
            .'Solo tendrás que entrar con tu cuenta de Google.';
    }

    public static function verification(string $teamLabel, string $workplaceName, string $url): string
    {
        return 'Hola, me he registrado en Turnin como responsable de '.self::team($teamLabel, $workplaceName).".\n\n"
            ."Para que Turnin pueda verificar que soy vuestro responsable necesito que los miembros del equipo lo confirmen.\n\n"
            ."Puedes comprobarlo entrando con tu cuenta de Google aquí:\n\n"
            .$url;
    }

    private static function team(string $teamLabel, string $workplaceName): string
    {
        return $teamLabel === $workplaceName ? $teamLabel : $teamLabel.' · '.$workplaceName;
    }
}
