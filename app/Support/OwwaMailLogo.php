<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Part\DataPart;

class OwwaMailLogo
{
    /**
     * Inline logo for email. Real sends use a CID attachment so clients do not
     * fetch the image from the public site. Previews fall back to a data URI.
     */
    public static function src(?object $message, string $key): string
    {
        $relative = (string) config('owwa_mail.logos.'.$key);
        $path = public_path($relative);

        if (! is_file($path)) {
            return URL::to($relative);
        }

        $mime = mime_content_type($path) ?: 'image/png';

        if ($message instanceof Message) {
            $cid = $key.'@owwa.mail';
            $part = (new DataPart((string) file_get_contents($path), null, $mime))->asInline();
            $part->setContentId($cid);
            $message->getSymfonyMessage()->addPart($part);

            return 'cid:'.$cid;
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }
}
