<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\HtmlResponse;

/**
 * Backend route handler for /oauthservice/callback.
 *
 * The actual callback work is performed by
 * {@see \WapplerSystems\OauthService\Middleware\OauthCallbackMiddleware},
 * which is registered to run before typo3/cms-backend/locked-backend so
 * the OAuth redirect succeeds even when the backend is locked. This
 * controller exists only so the route stays resolvable by
 * BackendUriBuilder::buildUriFromRoute('oauthsvc_callback'); in normal
 * operation execution never reaches this class.
 */
final class OAuthCallbackController
{
    public function callback(ServerRequestInterface $request): ResponseInterface
    {
        return new HtmlResponse('', 404);
    }
}