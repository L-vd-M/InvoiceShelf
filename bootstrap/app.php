<?php

use App\Http\Middleware\Authenticate;
use App\Http\Middleware\CompanyMiddleware;
use App\Http\Middleware\ConfigMiddleware;
use App\Http\Middleware\CronJobMiddleware;
use App\Http\Middleware\CustomerGuest;
use App\Http\Middleware\CustomerPortalMiddleware;
use App\Http\Middleware\CustomerRedirectIfAuthenticated;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\ConditionalRequests;
use App\Http\Middleware\ContractRepresentation;
use App\Http\Middleware\EnforceTokenScope;
use App\Http\Middleware\Idempotency;
use App\Http\Middleware\EnsureNotContainerized;
use App\Http\Middleware\InstallationMiddleware;
use App\Http\Middleware\PdfMiddleware;
use App\Http\Middleware\PreventRequestForgery;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RedirectIfInstalled;
use App\Http\Middleware\RedirectIfUnauthorized;
use App\Http\Middleware\ScopeBouncer;
use App\Http\Middleware\SuperAdminMiddleware;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\UseInstallWizardTokenAuth;
use App\Providers\AppServiceProvider;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\CheckForMaintenanceMode;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Lavary\Menu\ServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        ServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(AppServiceProvider::HOME);

        $middleware->preventRequestForgery(except: [
            'login',
            'installation/session-login',
        ]);

        $middleware->append([
            CheckForMaintenanceMode::class,
            TrimStrings::class,
            TrustProxies::class,
            UseInstallWizardTokenAuth::class,
            ConfigMiddleware::class,
        ]);

        $middleware->web([
            EncryptCookies::class,
            PreventRequestForgery::class,
        ]);

        $middleware->statefulApi();
        $middleware->throttleApi('180,1');

        $middleware->replace(Illuminate\Http\Middleware\TrustProxies::class, TrustProxies::class);

        $middleware->replaceInGroup('web', Illuminate\Cookie\Middleware\EncryptCookies::class, EncryptCookies::class);

        $middleware->alias([
            'auth' => Authenticate::class,
            'bindings' => SubstituteBindings::class,
            'bouncer' => ScopeBouncer::class,
            'company' => CompanyMiddleware::class,
            'cron-job' => CronJobMiddleware::class,
            'customer' => CustomerRedirectIfAuthenticated::class,
            'customer-guest' => CustomerGuest::class,
            'customer-portal' => CustomerPortalMiddleware::class,
            'guest' => RedirectIfAuthenticated::class,
            'install' => InstallationMiddleware::class,
            'conditional' => ConditionalRequests::class,
            'contract' => ContractRepresentation::class,
            'idempotent' => Idempotency::class,
            'token-scope' => EnforceTokenScope::class,
            'not-containerized' => EnsureNotContainerized::class,
            'pdf-auth' => PdfMiddleware::class,
            'redirect-if-installed' => RedirectIfInstalled::class,
            'redirect-if-unauthenticated' => RedirectIfUnauthorized::class,
            'super-admin' => SuperAdminMiddleware::class,
        ]);

        $middleware->priority([
            StartSession::class,
            ShareErrorsFromSession::class,
            Authenticate::class,
            EnforceTokenScope::class,
            AuthenticateSession::class,
            SubstituteBindings::class,
            Authorize::class,
        ]);

    })
    ->withExceptions(function (Exceptions $exceptions) {
        // API clients that authenticate with a bearer token get RFC 9457 problem+json with a stable `code`
        // (spec 08 section 1); `message` and `errors` stay for clients that read the older shape.
        $bearerApi = fn (Illuminate\Http\Request $request) => $request->is('api/*') && $request->bearerToken() !== null;

        $exceptions->render(function (Illuminate\Validation\ValidationException $e, Illuminate\Http\Request $request) use ($bearerApi) {
            if ($bearerApi($request)) {
                return App\Support\Problem::response(422, 'validation_failed', 'Validation failed', implode(' ', $e->validator->errors()->all()), ['errors' => $e->errors(), 'message' => $e->getMessage()]);
            }
        });

        $exceptions->render(function (Symfony\Component\HttpKernel\Exception\NotFoundHttpException $e, Illuminate\Http\Request $request) use ($bearerApi) {
            if ($bearerApi($request)) {
                return App\Support\Problem::response(404, 'not_found', 'Not found', null, ['message' => 'Not found']);
            }
        });

        $exceptions->render(function (Illuminate\Auth\AuthenticationException $e, Illuminate\Http\Request $request) use ($bearerApi) {
            if ($bearerApi($request)) {
                return App\Support\Problem::response(401, 'unauthorized', 'Unauthenticated', null, ['message' => 'Unauthenticated.']);
            }
        });
    })->create();
