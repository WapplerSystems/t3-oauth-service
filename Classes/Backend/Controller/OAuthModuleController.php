<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Backend\Controller;

use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Persistence\Generic\PersistenceManager;
use WapplerSystems\OauthService\Command\ConnectionMonitorCommand;
use WapplerSystems\OauthService\Domain\Model\Client;
use WapplerSystems\OauthService\Domain\Repository\ClientRepository;
use WapplerSystems\OauthService\Domain\Repository\ConnectionRepository;
use WapplerSystems\OauthService\Provider\ProviderRegistry;
use WapplerSystems\OauthService\Provider\Type\ProviderTypeResolver;
use WapplerSystems\OauthService\Service\MonitorTaskStatusService;
use WapplerSystems\OauthService\Service\OAuthFlowService;
use WapplerSystems\OauthService\Service\TokenAcquisitionService;

#[AsController]
class OAuthModuleController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory    $moduleTemplateFactory,
        private readonly ConnectionRepository     $connectionRepository,
        private readonly ClientRepository         $clientRepository,
        private readonly OAuthFlowService         $oAuthFlowService,
        private readonly ProviderRegistry         $clientRegistry,
        protected IconFactory                     $iconFactory,
        protected EventDispatcherInterface        $eventDispatcher,
        protected readonly BackendUriBuilder      $backendUriBuilder,
        protected PersistenceManager              $persistenceManager,
        private readonly MonitorTaskStatusService $monitorTaskStatusService,
        private readonly ProviderTypeResolver     $providerTypeResolver,
        private readonly TokenAcquisitionService  $tokenAcquisitionService,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($this->request);

        $normalizedParams = $this->request->getAttribute('normalizedParams');
        $this->registerDocHeaderButtons($view, $normalizedParams->getRequestUri());


        $clientDefinitions = $this->clientRegistry->all();

        $configuredClients = $this->clientRepository->findAll();

        // Absolute callback URL — must be configured at the OAuth provider as
        // the allowed redirect URI. Derived from the backend entry point so it
        // adapts to a customized $GLOBALS['TYPO3_CONF_VARS']['BE']['entryPoint'].
        $callbackUrl = (string)$this->backendUriBuilder->buildUriFromRoute(
            'oauthsvc_callback',
            [],
            BackendUriBuilder::ABSOLUTE_URL
        );

        $monitorStatus = $this->monitorTaskStatusService->getStatus(ConnectionMonitorCommand::COMMAND_IDENTIFIER);

        // Pre-compute which OAuth flows each configured client's provider type supports
        // so the template can show the appropriate action button(s).
        $clientCapabilities = [];
        foreach ($configuredClients as $client) {
            $clientCapabilities[(int)$client->getUid()] = $this->resolveClientCapabilities($client);
        }

        $view->assignMultiple([
            'clientDefinitions' => $clientDefinitions,
            'configuredClients' => $configuredClients,
            'clientCapabilities' => $clientCapabilities,
            'callbackUrl' => $callbackUrl,
            'now' => time(),
            'monitorState' => $monitorStatus['state'],
            'monitorLastRun' => $monitorStatus['lastRun'],
            'monitorLastFailure' => $monitorStatus['lastFailure'],
        ]);

        return $this->htmlResponse($view->render('Backend/Index'));
    }


    public function wizardAction(): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($this->request);

        $formURI = $this->backendUriBuilder->buildUriFromRoute(
            'oauthservice.OAuthModule_wizard'
        );

        // Step 3 (finalize): user submitted the Metadata step. Update the client's
        // metadata JSON and redirect to fetchTokenAction so the credentials are
        // verified end-to-end immediately after registration.
        if ($this->request->hasArgument('finalize') && $this->request->hasArgument('client')) {
            $clientUid = (int)$this->request->getArgument('client');
            $client = $clientUid > 0 ? $this->clientRepository->findByUid($clientUid) : null;
            if ($client === null) {
                throw new \RuntimeException('OAuth client not found: ' . $clientUid, 1717372001);
            }

            $metadataRaw = trim((string)($this->request->hasArgument('metadata') ? $this->request->getArgument('metadata') : ''));
            $metadataError = null;
            if ($metadataRaw !== '') {
                $decoded = json_decode($metadataRaw, true);
                if (!is_array($decoded)) {
                    $metadataError = (string)(json_last_error_msg() ?: 'Invalid JSON');
                }
            }

            if ($metadataError !== null) {
                $definition = $this->clientRegistry->get((string)$client->getProvider());
                $view->assignMultiple([
                    'formURI' => $formURI,
                    'definition' => $definition,
                    'client' => $client,
                    'metadata' => $metadataRaw,
                    'metadataError' => $metadataError,
                ]);
                return $this->htmlResponse($view->render('Backend/Wizard/Step2'));
            }

            $client->setMetadata($metadataRaw === '' ? null : $metadataRaw);
            $this->clientRepository->update($client);
            $this->persistenceManager->persistAll();

            return $this->redirectToUri(
                $this->backendUriBuilder->buildUriFromRoute(
                    'oauthservice.OAuthModule_fetchToken',
                    ['client' => $client->getUid()]
                )
            );
        }

        if ($this->request->hasArgument('oauthservice') && $this->request->hasArgument('clientId') && $this->request->hasArgument('clientSecret')) {

            $selectedService = $this->request->getArgument('oauthservice');
            $clientId = $this->request->getArgument('clientId');
            $clientSecret = $this->request->getArgument('clientSecret');

            $oauthClientDefinition = $this->clientRegistry->get($selectedService);
            if ($oauthClientDefinition === null) {
                throw new \RuntimeException('OAuth Client Definition not found: ' . $selectedService, 1666288235);
            }

            // search for existing client with same type and client id
            $existingClients = $this->clientRepository->findByProviderClientIdAndClientSecret(
                $oauthClientDefinition->identifier,
                $clientId,
                $clientSecret
            );

            if ($existingClients->count() > 0) {

                /** @var Client $client */
                $client = $existingClients->getFirst();

            } else {

                $client = new Client();
                $client->setIsActive(true);
                $client->setPid(0);
                $client->setProvider($oauthClientDefinition->identifier);
                $client->setClientId($clientId);
                $client->setClientSecret($clientSecret);
                $this->clientRepository->add($client);
            }

            $this->persistenceManager->persistAll();

            // Branch on flow capability: client_credentials providers need a metadata
            // step (e.g. Microsoft Graph tenant_id / sender_upn); authorization_code
            // providers redirect straight to the connect-to-provider screen.
            try {
                $providerType = $this->providerTypeResolver->resolve($oauthClientDefinition->type);
            } catch (\Throwable) {
                $providerType = null;
            }

            if ($providerType !== null && $providerType->supportsClientCredentials()) {
                $view->assignMultiple([
                    'formURI' => $formURI,
                    'definition' => $oauthClientDefinition,
                    'client' => $client,
                    'metadata' => (string)($client->getMetadata() ?? ''),
                    'metadataError' => null,
                ]);
                return $this->htmlResponse($view->render('Backend/Wizard/Step2'));
            }

            $backendUri = $this->backendUriBuilder->buildUriFromRoute(
                'oauthservice.OAuthModule_connect',
                [
                    'client' => $client->getUid(),
                ]
            );

            return $this->redirectToUri($backendUri);
        }

        if ($this->request->hasArgument('oauthservice')) {
            $selectedService = $this->request->getArgument('oauthservice');
            $definition = $this->clientRegistry->get($selectedService);

            $view->assignMultiple([
                'oauthservice' => $selectedService,
                'formURI' => $formURI,
                'definition' => $definition,
                'setupInstructionsHtml' => $this->loadSetupInstructions($definition?->setupInstructionsPath ?? ''),
            ]);

            return $this->htmlResponse($view->render('Backend/Wizard/Step1'));
        }

        $clientDefinitions = $this->clientRegistry->all();
        $view->assignMultiple([
            'clientDefinitions' => $clientDefinitions,
            'formURI' => $formURI,
        ]);

        return $this->htmlResponse($view->render('Backend/Wizard'));

    }


    protected function registerDocHeaderButtons(ModuleTemplate $view, string $requestUri): void
    {
        $buttonBar = $view->getDocHeaderComponent()->getButtonBar();


        $newRecordButton = $buttonBar->makeLinkButton()
            ->setHref((string)$this->backendUriBuilder->buildUriFromRoute(
                'oauthservice.OAuthModule_wizard'
            ))
            ->setTitle('Wizard starten')
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-plus', IconSize::SMALL));
        $buttonBar->addButton($newRecordButton, ButtonBar::BUTTON_POSITION_LEFT, 10);

    }


    /**
     * Dummy callback endpoint for OAuth providers
     * @return ResponseInterface
     */
    public function callbackAction(): ResponseInterface
    {
        return $this->htmlResponse('');
    }

    public function connectAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $clientUid = (int)($this->request->getArgument('client') ?? 0);
        $authUrl = $this->oAuthFlowService->startAuthorization($clientUid, $this->request, null, null);

        $moduleTemplate->assignMultiple([
            'authorizeUrl' => $authUrl,
        ]);
        return $moduleTemplate->renderResponse('Backend/Connect');

    }

    public function reconnectAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $connectionUid = (int)($this->request->getArgument('connection') ?? 0);
        $connection = $this->connectionRepository->findByUid($connectionUid);
        $clientUid = $connection->getClient()->getUid();
        $authUrl = $this->oAuthFlowService->startAuthorization($clientUid, $this->request, $connectionUid, null);

        $moduleTemplate->assignMultiple([
            'authorizeUrl' => $authUrl,
        ]);
        return $moduleTemplate->renderResponse('Backend/Connect');
    }

    /**
     * Fetches an OAuth 2.0 client_credentials access token for the given client
     * (no user redirect, service-to-service). Used by providers that issue
     * tokens directly to a registered app rather than via the
     * Authorization-Code Flow with PKCE.
     */
    public function fetchTokenAction(): ResponseInterface
    {
        $clientUid = (int)($this->request->getArgument('client') ?? 0);
        if ($clientUid <= 0) {
            $this->addFlashMessage('Invalid client', 'OAuth Service', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        $client = $this->clientRepository->findByUid($clientUid);
        if ($client === null) {
            $this->addFlashMessage(sprintf('Client #%d not found', $clientUid), 'OAuth Service', \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR);
            return $this->redirect('index');
        }

        $providerIdentifier = (string)$client->getProvider();
        try {
            $this->tokenAcquisitionService->invalidate($providerIdentifier);
            $token = $this->tokenAcquisitionService->getClientCredentialsToken($providerIdentifier);
        } catch (\Throwable $e) {
            $this->addFlashMessage(
                sprintf('Token acquisition failed: %s', $e->getMessage()),
                'OAuth Service',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        if ($token === null || $token === '') {
            $this->addFlashMessage(
                'No access token returned. Check client_id, client_secret and admin-consent.',
                'OAuth Service',
                \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
            );
            return $this->redirect('index');
        }

        $this->addFlashMessage(
            sprintf('Access token acquired for provider "%s" (%d chars).', $providerIdentifier, strlen($token)),
            'OAuth Service',
            \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::OK
        );
        return $this->redirect('index');
    }

    public function disconnectAction(): ResponseInterface
    {
        $connectionUid = (int)($this->request->getArgument('connection') ?? 0);
        if ($connectionUid > 0) {
            $connection = $this->connectionRepository->findByUid($connectionUid);
            if ($connection !== null) {
                $this->connectionRepository->remove($connection);
            }
        }
        return $this->redirect('index');
    }


    public function deleteClientAction(): ResponseInterface
    {
        $clientUid = (int)($this->request->getArgument('client') ?? 0);
        if ($clientUid > 0) {
            $client = $this->clientRepository->findByUid($clientUid);
            if ($client !== null) {
                $this->clientRepository->remove($client);
            }
        }
        return $this->redirect('index');
    }

    /**
     * Resolves which OAuth flows the given client's provider supports.
     * Returns capability flags consumed by the template to render the
     * appropriate action buttons.
     *
     * @return array{clientCredentials: bool, authorizationCode: bool}
     */
    private function resolveClientCapabilities(Client $client): array
    {
        $default = ['clientCredentials' => false, 'authorizationCode' => true];

        $providerIdentifier = (string)$client->getProvider();
        if ($providerIdentifier === '') {
            return $default;
        }

        $definition = $this->clientRegistry->get($providerIdentifier);
        if ($definition === null) {
            return $default;
        }

        try {
            $type = $this->providerTypeResolver->resolve($definition->type);
        } catch (\Throwable) {
            return $default;
        }

        return [
            'clientCredentials' => $type->supportsClientCredentials(),
            'authorizationCode' => $type->supportsRefresh(),
        ];
    }

    /**
     * Loads a provider's setup instructions snippet from the file system.
     * Accepts EXT:syntax paths. Returns '' when the path is empty or the file
     * cannot be read — callers must handle that gracefully.
     */
    private function loadSetupInstructions(string $path): string
    {
        if ($path === '') {
            return '';
        }
        $absolute = GeneralUtility::getFileAbsFileName($path);
        if ($absolute === '' || !is_file($absolute) || !is_readable($absolute)) {
            return '';
        }
        // Cap at 256 KB to avoid accidental large includes from a misconfigured path.
        if (filesize($absolute) > 262144) {
            return '';
        }
        $contents = @file_get_contents($absolute);
        return $contents === false ? '' : $contents;
    }


}
