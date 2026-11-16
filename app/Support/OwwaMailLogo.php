<?php

namespace App\Support;

use Illuminate\Mail\Message;
use Illuminate\Support\Facades\URL;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\AbstractPart;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\AlternativePart;
use Symfony\Component\Mime\Part\Multipart\MixedPart;
use Symfony\Component\Mime\Part\Multipart\RelatedPart;
use Symfony\Component\Mime\Part\TextPart;

class OwwaMailLogo
{
    /**
     * Inline logo for email. Real sends use Laravel's embed() (CID). Previews
     * fall back to a data URI (Gmail blocks data URIs on real sends).
     *
     * prepareForGmail() then rebuilds the MIME body: Gmail shows a part as a
     * download when it has a filename, and it drops the header image when a
     * quoted-printable soft break splits the cid URL.
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
            return $message->embed($path);
        }

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($path));
    }

    public static function prepareForGmail(Email $email): void
    {
        $html = $email->getHtmlBody();

        if (! is_string($html) || ! str_contains($html, 'cid:')) {
            return;
        }

        $inline = [];
        $attached = [];

        foreach ($email->getAttachments() as $part) {
            if ($part->getDisposition() === 'inline' && $part->hasContentId()) {
                $inline[] = (new DataPart($part->getBody(), null, $part->getContentType()))
                    ->asInline()
                    ->setContentId($part->getContentId());

                continue;
            }

            $attached[] = $part;
        }

        if ($inline === []) {
            return;
        }

        $email->setBody(self::body($html, $email->getTextBody(), $inline, $attached));
    }

    /**
     * @param  array<int, DataPart>  $inline
     * @param  array<int, DataPart>  $attached
     */
    private static function body(string $html, mixed $text, array $inline, array $attached): AbstractPart
    {
        $htmlPart = new TextPart($html, 'utf-8', 'html', 'base64');
        $main = is_string($text) && $text !== ''
            ? new AlternativePart(new TextPart($text), $htmlPart)
            : $htmlPart;

        $related = new RelatedPart($main, $inline[0], ...array_slice($inline, 1));

        if ($attached === []) {
            return $related;
        }

        return new MixedPart($related, ...$attached);
    }
}
