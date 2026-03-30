<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;
use WapplerSystems\OauthService\Service\OAuthFlowService;

final class OAuthCallbackController
{
    public function __construct(private readonly OAuthFlowService $flowService) {}

    public function callback(ServerRequestInterface $request): ResponseInterface
    {
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
            $connectionUid = $this->flowService->handleCallback($request, $code, $state);

        } catch (\Throwable $e) {
            return new HtmlResponse('<h1>Callback failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>', 500);
        }



        $html = '<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>CleverReach verbunden</title>
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
        <h1>CleverReach erfolgreich verbunden</h1>
        <p>Der Zugriffstoken wurde gespeichert.</p>
        <p>Klicke auf den folgenden Link, um zum TYPO3 Backend-Modul zurückzukehren:</p>
        <p>
            <a class="button" href="' . htmlspecialchars($backendModuleUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" target="_top">
                Zum CleverReach Backend-Modul
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
