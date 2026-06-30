<?php

namespace App\Http\Middleware;

use App\Services\UserSessionAuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TouchUserSessionActivity
{
    public function __construct(
        protected UserSessionAuditService $sessionAudit,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userLogId = $request->session()->get('audit_user_log_id');

        if ($request->user() !== null && is_numeric($userLogId)) {
            $this->sessionAudit->touchActivity((int) $userLogId);
        }

        return $next($request);
    }
}
