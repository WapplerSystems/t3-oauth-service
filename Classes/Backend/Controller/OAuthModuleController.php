<?php
declare(strict_types=1);

namespace WapplerSystems\OauthService\Backend\Controller;

use Psr\Http\Message\ResponseInterface;
use WapplerSystems\OauthService\Repository\ClientRepository;
use WapplerSystems\OauthService\Repository\ConnectionRepository;
use WapplerSystems\OauthService\Service\OAuthFlowService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

final class OAuthModuleController extends ActionController
{
    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly ClientRepository $clientRepository,
        private readonly ConnectionRepository $connectionRepository,
        private readonly OAuthFlowService $oAuthFlowService,
    ) {}

    public function indexAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);

        $clients = $this->clientRepository->findAll();
        foreach ($clients as &$client) {
            $client['connections'] = $this->connectionRepository->findByClientUid((int)$client['uid']);
        }

        $this->view->assignMultiple([
            'clients' => $clients,
            'now' => time(),
        ]);

        $moduleTemplate->setContent($this->view->render());
        return $this->htmlResponse($moduleTemplate->renderContent());
    }

    public function connectAction(): ResponseInterface
    {
        $clientUid = (int)($this->request->getArgument('client') ?? 0);
        $authUrl = $this->oAuthFlowService->startAuthorization($clientUid, null, null);
        return $this->redirectToUri($authUrl);
    }

    public function reconnectAction(): ResponseInterface
    {
        $clientUid = (int)($this->request->getArgument('client') ?? 0);
        $connectionUid = (int)($this->request->getArgument('connection') ?? 0);
        $authUrl = $this->oAuthFlowService->startAuthorization($clientUid, $connectionUid, null);
        return $this->redirectToUri($authUrl);
    }

    public function disconnectAction(): ResponseInterface
    {
        $connectionUid = (int)($this->request->getArgument('connection') ?? 0);
        if ($connectionUid > 0) {
            $this->connectionRepository->delete($connectionUid);
        }
        return $this->redirect('index');
    }
}
