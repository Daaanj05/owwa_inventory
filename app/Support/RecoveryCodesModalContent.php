<?php

namespace App\Support;

use Filament\Actions\Action;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Js;

final class RecoveryCodesModalContent
{
    public const string FILENAME = 'recovery-codes.txt';

    /**
     * @param  list<string>  $recoveryCodes
     */
    public static function copyAndDownloadPrompt(array $recoveryCodes): Htmlable
    {
        $body = implode(PHP_EOL, $recoveryCodes);

        return new HtmlString(
            __('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.0')
            .' '
            .Action::make('copy')
                ->label(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.copy.label'))
                ->link()
                ->alpineClickHandler('
                    window.navigator.clipboard.writeText('.Js::from($body).')
                    $tooltip('.Js::from(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.messages.copied')).', {
                        theme: $store.theme,
                    })
                ')
                ->toHtml()
            .' '
            .__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.1')
            .' '
            .Action::make('download')
                ->label(__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.download.label'))
                ->link()
                ->url('data:text/plain;charset=utf-8,'.rawurlencode($body))
                ->extraAttributes(['download' => self::FILENAME])
                ->toHtml()
            .' '
            .__('filament-panels::auth/multi-factor/recovery-codes-modal-content.actions.2')
        );
    }
}
