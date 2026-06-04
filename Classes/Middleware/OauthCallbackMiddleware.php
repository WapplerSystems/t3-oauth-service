<?php

declare(strict_types=1);

namespace WapplerSystems\OauthService\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\HtmlResponse;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;
use WapplerSystems\OauthService\Service\OAuthFlowService;

final class OauthCallbackMiddleware implements MiddlewareInterface
{
    private const TEMPLATE_ROOT_PATHS = ['EXT:oauth_service/Resources/Private/Templates/'];

    public function __construct(
        private readonly OAuthFlowService           $flowService,
        private readonly BackendUriBuilder          $backendUriBuilder,
        private readonly BackendEntryPointResolver  $backendEntryPointResolver,
        private readonly ViewFactoryInterface       $viewFactory,
        private readonly LanguageServiceFactory     $languageServiceFactory,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        // Only intercept the configured callback path; the backend entry point is
        // configurable via $TYPO3_CONF_VARS/BE/entryPoint, so build the path
        // relative to it.
        $callbackPath = $this->backendEntryPointResolver->getPathFromRequest($request) . 'oauthservice/callback';
        if ($path !== $callbackPath) {
            return $handler->handle($request);
        }

        // Make sure f:translate can resolve labels even though we run before
        // backend bootstrap completes.
        $this->ensureLanguageService($request);

        $queryParams = $request->getQueryParams();
        $code = (string)($queryParams['code'] ?? '');
        $state = (string)($queryParams['state'] ?? '');
        $error = (string)($queryParams['error'] ?? '');

        if ($error !== '') {
            return $this->renderError($request, 'providerError', $error, 400);
        }
        if ($code === '' || $state === '') {
            return $this->renderError($request, 'missingParams', '', 400);
        }

        try {
            $this->flowService->handleCallback($request, $code, $state);
        } catch (\Throwable $e) {
            return $this->renderError($request, 'failed', $e->getMessage(), 500);
        }

        $backendModuleUrl = $this->backendUriBuilder->buildUriFromRoute('oauthservice.OAuthModule_index')
            ->withHost($request->getUri()->getHost())
            ->withScheme($request->getUri()->getScheme())
            ->__toString();

        return new HtmlResponse($this->renderTemplate(
            $request,
            'Callback/Success',
            ['backendModuleUrl' => $backendModuleUrl]
        ));
    }

    private function renderError(
        ServerRequestInterface $request,
        string $kind,
        string $detail,
        int $statusCode,
    ): HtmlResponse {
        return new HtmlResponse(
            $this->renderTemplate($request, 'Callback/Error', [
                'kind' => $kind,
                'detail' => $detail,
            ]),
            $statusCode
        );
    }

    /**
     * @param array<string, mixed> $variables
     */
    private function renderTemplate(ServerRequestInterface $request, string $templateName, array $variables): string
    {
        $view = $this->viewFactory->create(new ViewFactoryData(
            templateRootPaths: self::TEMPLATE_ROOT_PATHS,
            request: $request,
            format: 'html',
        ));
        $view->assignMultiple($variables + ['language' => $this->resolveLanguageCode($request)]);
        return $view->render($templateName);
    }

    private function resolveLanguageCode(ServerRequestInterface $request): string
    {
        $beUser = $this->getBackendUser($request);
        $lang = (string)($beUser?->user['lang'] ?? '');
        return $lang !== '' ? $lang : 'en';
    }

    /**
     * Without an active LanguageService, f:translate falls back to the XLF source
     * (English). The locked-backend middleware normally sets it up, but we run
     * before that, so prime $GLOBALS['LANG'] ourselves from the BE user.
     */
    private function ensureLanguageService(ServerRequestInterface $request): void
    {
        if (isset($GLOBALS['LANG'])) {
            return;
        }
        $beUser = $this->getBackendUser($request);
        $GLOBALS['LANG'] = $beUser !== null
            ? $this->languageServiceFactory->createFromUserPreferences($beUser)
            : $this->languageServiceFactory->create('default');
    }

    private function getBackendUser(ServerRequestInterface $request): ?BackendUserAuthentication
    {
        $beUser = $request->getAttribute('backend.user');
        if ($beUser instanceof BackendUserAuthentication) {
            return $beUser;
        }
        $globalBeUser = $GLOBALS['BE_USER'] ?? null;
        return $globalBeUser instanceof BackendUserAuthentication ? $globalBeUser : null;
    }
}