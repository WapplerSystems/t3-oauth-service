<?php


declare(strict_types=1);

namespace WapplerSystems\OauthService\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use WapplerSystems\OauthService\Service\OAuthFlowService;

final class OauthCallbackMiddleware implements MiddlewareInterface
{

    public function __construct(
        private readonly OAuthFlowService          $flowService,
        private readonly BackendUriBuilder         $backendUriBuilder,
        private readonly BackendEntryPointResolver $backendEntryPointResolver,
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Nur unsere Callback-URL abfangen, alles andere normal weiterreichen.
        // Der Backend-Entry-Point ist konfigurierbar (TYPO3_CONF_VARS/BE/entryPoint),
        // daher wird der Pfad relativ zum Entry Point gebildet.
        $callbackPath = $this->backendEntryPointResolver->getPathFromRequest($request) . 'oauthservice/callback';
        if ($path !== $callbackPath) {
            return $handler->handle($request);
        }


        $queryParams = $request->getQueryParams();
        $code = (string)($queryParams['code'] ?? '');
        $state = (string)($queryParams['state'] ?? '');
        $error = (string)($queryParams['error'] ?? '');

        if ($error !== '') {
            return new HtmlResponse('<h1>OAuth error</h1><p>' . htmlspecialchars($error) . '</p>', 400);
        }
        if ($code === '' || $state === '') {
            return new HtmlResponse('<h1>Missing parameters</h1><p>code/state required.</p>', 400);
        }

        try {
            $connection = $this->flowService->handleCallback($request, $code, $state);
        } catch (\Throwable $e) {
            return new HtmlResponse('<h1>Callback failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>', 500);
        }

        $backendModuleUrl = $this->backendUriBuilder->buildUriFromRoute('oauthservice.OAuthModule_index')->withHost($request->getUri()->getHost())->withScheme($request->getUri()->getScheme())->__toString();

        $html = '<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>OAuth Dienst verbunden</title>
    <style>
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            padding: 2rem;
            max-width: 600px;
            margin: 0 auto;
        }
        .box {
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 1.5rem;
        }
        a.button {
            display: inline-block;
            padding: 0.6rem 1.2rem;
            border-radius: 4px;
            text-decoration: none;
            border: 1px solid #005262;
            color: #fff;
            background: #005262;
            margin-top: 1rem;
        }
        a.button:hover {
            opacity: 0.9;
        }
    </style>
</head>
<body>
    <div class="box">
        <h1>OAuth Dienst erfolgreich verbunden</h1>
        <p>Klicke auf den folgenden Link, um zum TYPO3 Backend-Modul zurückzukehren:</p>
        <p>
            <a class="button" href="' . htmlspecialchars($backendModuleUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_top">
                Zurück zur Übersicht
            </a>
        </p>
        <p style="margin-top:1rem;font-size:0.9rem;color:#666;">
            Hinweis: Der Link öffnet das TYPO3 Backend im Hauptfenster.
            Falls du noch nicht angemeldet bist, erscheint zunächst der Login.
        </p>
    </div>
</body>
</html>';

        return new HtmlResponse($html);


    }
}
