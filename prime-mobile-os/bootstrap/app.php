<?php

use App\Domain\Tenancy\Models\Organization;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Storefront\Site;
use App\Support\Tenancy\CurrentOrganization;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'organization' => SetCurrentOrganization::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // the public site has its own «Страница не найдена» (TZ 05 §9.1 «404»), the ERP keeps the default one
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->getHost() !== config('prime.shop_domain') || $request->expectsJson()) {
                return null;
            }
            try {
                if (! Site::$org) {
                    $org = Organization::query()->where('is_active', true)->orderBy('id')->first();
                    if (! $org) {
                        return null;
                    }
                    CurrentOrganization::instance()->set($org);
                    Site::boot($org, $request->segment(1) === 'kk' ? 'kk' : 'ru', false);
                }

                return response()->view('storefront.errors.404', [], 404);
            } catch (Throwable) {
                return null;
            }
        });
    })->create();
