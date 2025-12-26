<?php


declare(strict_types=1);

namespace WapplerSystems\OauthService\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Http\RequestFactory;
use WapplerSystems\OauthService\Service\OAuthFlowService;

final class OauthCallbackMiddleware implements MiddlewareInterface
{
    private const TOKEN_URL = 'https://rest.cleverreach.com/oauth/token.php';

    public function __construct(
        private readonly OAuthFlowService $flowService
    )
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Nur unsere Callback-URL abfangen, alles andere normal weiterreichen
        if ($path !== '/typo3/cleverreach/oauth/callback') {
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
            $connectionUid = $this->flowService->handleCallback($code, $state);
            return new HtmlResponse(
                '<h1>OAuth connected</h1><p>Connection #' . (int)$connectionUid . ' updated.</p><p>You can close this window.</p>'
            );
        } catch (\Throwable $e) {
            return new HtmlResponse('<h1>Callback failed</h1><pre>' . htmlspecialchars($e->getMessage()) . '</pre>', 500);
        }


        return new HtmlResponse($html);
    }
}
