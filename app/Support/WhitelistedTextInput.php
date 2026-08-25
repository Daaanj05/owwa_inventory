<?php

namespace App\Support;

/**
 * Filament-native Alpine guards that whitelist characters before they paint.
 *
 * JS must use single quotes only — ComponentAttributeBag wraps attributes in
 * double quotes and \" does not keep quotes inside HTML attributes.
 */
class WhitelistedTextInput
{
    /**
     * Distinctive snippets asserted by feature tests (must stay in sync with handlers).
     */
    public const DIGITS_MARKER = 'whitelist-digits';

    public const DECIMAL_MARKER = 'whitelist-decimal';

    public const LETTERS_MARKER = 'whitelist-letters';

    /**
     * @return array<string, string>
     */
    public static function digitsOnlyAlpineAttributes(): array
    {
        return [
            'autocomplete' => 'off',
            'data-whitelist' => self::DIGITS_MARKER,
            '@beforeinput' => 'if([\'insertText\',\'insertFromPaste\',\'insertCompositionText\',\'insertFromDrop\'].includes($event.inputType)&&$event.data&&!/^[0-9]+$/.test($event.data)){$event.preventDefault();const t=($event.data||\'\').replace(/[^0-9]/g,\'\');if(!t)return;const el=$event.target,s=el.selectionStart??el.value.length,e=el.selectionEnd??el.value.length;el.value=el.value.slice(0,s)+t+el.value.slice(e);el.setSelectionRange(s+t.length,s+t.length);el.dispatchEvent(new Event(\'input\',{bubbles:true}))}',
            '@keydown' => 'if($event.ctrlKey||$event.metaKey||$event.altKey)return;if($event.key.length===1&&!/^[0-9]$/.test($event.key))$event.preventDefault()',
            '@paste.prevent' => 'const t=(($event.clipboardData||window.clipboardData).getData(\'text\')||\'\').replace(/[^0-9]/g,\'\');if(!t)return;const el=$event.target,s=el.selectionStart??el.value.length,e=el.selectionEnd??el.value.length;el.value=el.value.slice(0,s)+t+el.value.slice(e);el.setSelectionRange(s+t.length,s+t.length);el.dispatchEvent(new Event(\'input\',{bubbles:true}))',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function decimalMoneyAlpineAttributes(): array
    {
        return [
            'autocomplete' => 'off',
            'data-whitelist' => self::DECIMAL_MARKER,
            '@beforeinput' => 'if([\'insertText\',\'insertFromPaste\',\'insertCompositionText\',\'insertFromDrop\'].includes($event.inputType)&&$event.data){const d=$event.data;if(!/^[0-9.]+$/.test(d)||(d.includes(\'.\')&&$event.target.value.includes(\'.\'))||(d.match(/\\./g)||[]).length>1){$event.preventDefault();let t=(d||\'\').replace(/[^0-9.]/g,\'\');const i=t.indexOf(\'.\');if(i!==-1)t=t.slice(0,i+1)+t.slice(i+1).replace(/\\./g,\'\');if(!t)return;const el=$event.target,s=el.selectionStart??el.value.length,e=el.selectionEnd??el.value.length;let n=el.value.slice(0,s)+t+el.value.slice(e);const p=n.split(\'.\');n=p.length===1?p[0]:p[0]+\'.\'+p.slice(1).join(\'\').slice(0,2);el.value=n;const c=Math.min(s+t.length,el.value.length);el.setSelectionRange(c,c);el.dispatchEvent(new Event(\'input\',{bubbles:true}))}}',
            '@keydown' => 'if($event.ctrlKey||$event.metaKey||$event.altKey)return;const k=$event.key;if(k===\'.\'){if($event.target.value.includes(\'.\'))$event.preventDefault();return}if(k.length===1&&!/^[0-9]$/.test(k))$event.preventDefault()',
            '@paste.prevent' => 'let t=(($event.clipboardData||window.clipboardData).getData(\'text\')||\'\').replace(/[^0-9.]/g,\'\');const i=t.indexOf(\'.\');if(i!==-1)t=t.slice(0,i+1)+t.slice(i+1).replace(/\\./g,\'\');if(!t)return;const el=$event.target,s=el.selectionStart??el.value.length,e=el.selectionEnd??el.value.length;let n=el.value.slice(0,s)+t+el.value.slice(e);const p=n.split(\'.\');n=p.length===1?p[0]:p[0]+\'.\'+p.slice(1).join(\'\').slice(0,2);el.value=n;const c=Math.min(s+t.length,el.value.length);el.setSelectionRange(c,c);el.dispatchEvent(new Event(\'input\',{bubbles:true}))',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function lettersOnlyAlpineAttributes(): array
    {
        return [
            'autocomplete' => 'off',
            'data-whitelist' => self::LETTERS_MARKER,
            '@beforeinput' => 'if([\'insertText\',\'insertFromPaste\',\'insertCompositionText\',\'insertFromDrop\'].includes($event.inputType)&&$event.data&&!/^[A-Za-z \\-]+$/.test($event.data)){$event.preventDefault();const t=($event.data||\'\').replace(/[^A-Za-z \\-]/g,\'\');if(!t)return;const el=$event.target,s=el.selectionStart??el.value.length,e=el.selectionEnd??el.value.length;el.value=el.value.slice(0,s)+t+el.value.slice(e);el.setSelectionRange(s+t.length,s+t.length);el.dispatchEvent(new Event(\'input\',{bubbles:true}))}',
            '@keydown' => 'if($event.ctrlKey||$event.metaKey||$event.altKey)return;if($event.key.length===1&&!/^[A-Za-z \\-]$/.test($event.key))$event.preventDefault()',
            '@paste.prevent' => 'const t=(($event.clipboardData||window.clipboardData).getData(\'text\')||\'\').replace(/[^A-Za-z \\-]/g,\'\');if(!t)return;const el=$event.target,s=el.selectionStart??el.value.length,e=el.selectionEnd??el.value.length;el.value=el.value.slice(0,s)+t+el.value.slice(e);el.setSelectionRange(s+t.length,s+t.length);el.dispatchEvent(new Event(\'input\',{bubbles:true}))',
        ];
    }
}
