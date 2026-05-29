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
use WapplerSystems\OauthService\Service\MonitorTaskStatusService;
use WapplerSystems\OauthService\Service\OAuthFlowService;

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
    ) {}

    public function indexAction(): ResponseInterface
    {
        $view = $this->moduleTemplateFactory->create($this->request);

        $normalizedParams = $this->request->getAttribute('normalizedParams');
        $this->registerDocHeaderButtons($view, $normalizedParams->getRequestUri());


        $clientDefinitions = $this->clientRegistry->all();

        $configuredClients = $this->clientRepository->findAll();

        // Absolute callback URL — must be configured at the OAuth provider as
        // the allowed redirect URI. Path is fixed by OauthCallbackMiddleware.
        $callbackUrl = $normalizedParams->getRequestHost() . '/typo3/oauthservice/callback';

        $monitorStatus = $this->monitorTaskStatusService->getStatus(ConnectionMonitorCommand::COMMAND_IDENTIFIER);

        $view->assignMultiple([
            'clientDefinitions' => $clientDefinitions,
            'configuredClients' => $configuredClients,
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
