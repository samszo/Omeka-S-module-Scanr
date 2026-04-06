<?php
namespace Scanr\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Scanr\Service\ApiClient;
use Scanr\Service\DuckClient;
use Scanr\Service\JsonlClient;
use Scanr\Service\SqlClient;
use Scanr\Form\SearchForm;
use Omeka\Mvc\Exception\NotFoundException;

class IndexController extends AbstractActionController
{
    /**
     * @var ApiClient
     */
    protected $apiClient;

    /**
     * @var DuckClient
     */
    protected $duckClient;

    /**
     * @var JsonlClient
     */
    protected $jsonlClient;

    /**
     * @var SqlClient
     */
    protected $sqlClient;

    /**
     * @var requester
     */
    protected $requester;

    /**
     * @var SearchForm
     */
    protected $searchForm;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    /**
     * @var \Omeka\Job\Dispatcher
     */
    protected $dispatcher;

    public function __construct(ApiClient $apiClient, JsonlClient $jsonlClient, DuckClient $duckClient, SqlClient $sqlClient, SearchForm $searchForm, $api, $dispatcher)
    {
        $this->apiClient  = $apiClient;
        $this->duckClient = $duckClient;
        $this->jsonlClient= $jsonlClient;
        $this->sqlClient  = $sqlClient;
        $this->searchForm = $searchForm;
        $this->api        = $api;
        $this->dispatcher = $dispatcher;

        $this->setRequester();

    }


    public function indexAction()
    {
        return new ViewModel([
            'form' => $this->searchForm,
        ]);
    }


    private function setRequester(){

        if ($this->sqlClient->testConnection()) {
            // Table SQL disponible → recherche rapide
            $this->requester = $this->sqlClient;
        } elseif ($this->apiClient->testConnection()) {
            // requête sur l'API scanr = la plus à jour mais pas toujours disponible
            $this->requester = $this->apiClient;
        } else {
            // Fallback pur PHP (lent sur de grands fichiers)
            $this->requester = $this->jsonlClient;
        }

    }

    public function searchAction()
    {
        $view = new ViewModel();
        $view->setTemplate('scanr/index/search');

        if (!$this->getRequest()->isPost()) {
            return $view->setVariable('form', $this->searchForm);
        }

        $data = $this->params()->fromPost();
        $this->searchForm->setData($data);

        if (!$this->searchForm->isValid()) {
            $this->messenger()->addError('Formulaire invalide');
            return $view->setVariable('form', $this->searchForm);
        }

        $formData = $this->searchForm->getData();
        $query = $formData['query'] ?? '';
        $page = (int) ($this->params()->fromQuery('page', 1)) - 1;
        $size = 3;

        try {
            $results = $this->requester->searchPersons($query, $page, $size);
            
            $view->setVariables([
                'form' => $this->searchForm,
                'results' => $results,
                'query' => $query,
                'currentPage' => $page + 1,
                'totalPages' => ceil($results['total'] / $size),
            ]);
        } catch (\Exception $e) {
            $this->messenger()->addError('Erreur lors de la recherche: ' . $e->getMessage());
            $view->setVariable('form', $this->searchForm);
        }

        return $view;
    }

    public function importAction()
    {
        set_time_limit(60);

        if (!$this->getRequest()->isPost()) {
            throw new NotFoundException();
        }

        $personId = $this->params()->fromPost('person_id');
        if (!$personId) {
            $this->messenger()->addError('ID de personne manquant');
            return $this->redirect()->toRoute('admin/scanr/search');
        }

        try {
            $personData = $this->requester->getPersonById($personId);
            
            if (!$personData) {
                $this->messenger()->addError('Personne non trouvée');
                return $this->redirect()->toRoute('admin/scanr/search');
            }

            // Créer un item Omeka S avec les données de la personne
            $itemData = $this->requester->mapPersonToItem($personData);
            
            $response = $this->api->create('items', $itemData);
            
            if ($response) {
                $this->messenger()->addSuccess('Personne importée avec succès');
            } else {
                $this->messenger()->addError('Erreur lors de l\'importation');
            }
        } catch (\Exception $e) {
            $this->messenger()->addError('Erreur lors de l\'importation: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('admin/scanr/search');
    }

    /**
     * Lance le job d'import du fichier JSONL vers la table MySQL `scanr_person`.
     * Accessible via POST /scanr/import-jsonl
     */
    public function importJsonlAction()
    {
        $truncate = (bool) $this->params()->fromPost('truncate', true);

        try {
            $job = $this->dispatcher->dispatch(
                \Scanr\Job\ImportJsonlToSql::class,
                ['truncate' => $truncate]
            );
            $this->messenger()->addSuccess(
                sprintf('Import JSONL lancé en arrière-plan (job #%d). Consultez les logs pour suivre la progression.', $job->getId())
            );
        } catch (\Exception $e) {
            $this->messenger()->addError('Erreur lors du lancement du job : ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('admin/scanr');
    }

    public function associerAction()
    {
        set_time_limit(60);

        if (!$this->getRequest()->isPost()) {
            throw new NotFoundException();
        }

        $personId = $this->params()->fromPost('person_id');
        if (!$personId) {
            $this->messenger()->addError('ID de personne manquant');
            return $this->redirect()->toRoute('admin/scanr/search');
        }

        $itemId = $this->params()->fromPost('item_id');
        if (!$itemId) {
            $this->messenger()->addError('Item de personne manquant');
            return $this->redirect()->toRoute('admin/scanr/search');
        }

        try {
            $personData = $this->requester->getPersonById($personId);
            
            if (!$personData) {
                $this->messenger()->addError('Personne non trouvée');
                return $this->redirect()->toRoute('admin/scanr/search');
            }

            // Créer un item Omeka S avec les données de la personne
            $itemData = $this->requester->mapPersonToItem($personData,$itemId);
            
            $response = $this->api->update('items',$itemId ,$itemData,[], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);

            
            if ($response) {
                $this->messenger()->addSuccess('Personne importée avec succès');
            } else {
                $this->messenger()->addError('Erreur lors de l\'importation');
            }
        } catch (\Exception $e) {
            $this->messenger()->addError('Erreur lors de l\'importation: ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('admin/scanr/search');
    }



}
