<?php

namespace App\Support;

use Illuminate\Filesystem\Filesystem;

class RetryingFilesystem extends Filesystem
{
    public function replace($path, $content, $mode = null): void
    {
        $attempts = 12;
        $sleepUs = 60_000; // 60ms

        for ($i = 0; $i < $attempts; $i++) {
            try {
                parent::replace($path, $content, $mode);

                return;
            } catch (\Throwable $e) {
                // Windows file-lock race: another request may be including the compiled view
                // while we're trying to replace it. A short retry usually succeeds.
                if ($i === $attempts - 1) {
                    // Final fallback: avoid rename() entirely (not atomic, but prevents 500s).
                    $this->put($path, $content, true);

                    if (! is_null($mode)) {
                        @chmod($path, $mode);
                    }

                    return;
                }

                usleep($sleepUs);
                $sleepUs *= 2;
            }
        }
    }
}
