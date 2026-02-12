<?php
namespace ScanR\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use ScanR\Service\ApiClient;
use ScanR\Form\SearchForm;
use Omeka\Mvc\Exception\NotFoundException;

class IndexController extends AbstractActionController
{
    /**
     * @var ApiClient
     */
    protected $apiClient;

    /**
     * @var SearchForm
     */
    protected $searchForm;

    /**
     * @var \Omeka\Api\Manager
     */
    protected $api;

    public function __construct(ApiClient $apiClient, SearchForm $searchForm, $api)
    {
        $this->apiClient = $apiClient;
        $this->searchForm = $searchForm;
        $this->api = $api;
    }

    public function indexAction()
    {
        return new ViewModel([
            'form' => $this->searchForm,
        ]);
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
        $size = 20;

        try {
            $results = $this->apiClient->searchPersons($query, $page, $size);
            
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
        if (!$this->getRequest()->isPost()) {
            throw new NotFoundException();
        }

        $personId = $this->params()->fromPost('person_id');
        if (!$personId) {
            $this->messenger()->addError('ID de personne manquant');
            return $this->redirect()->toRoute('admin/scanr/search');
        }

        try {
            $personData = $this->apiClient->getPersonById($personId);
            
            if (!$personData) {
                $this->messenger()->addError('Personne non trouvée');
                return $this->redirect()->toRoute('admin/scanr/search');
            }

            // Créer un item Omeka S avec les données de la personne
            $itemData = $this->mapPersonToItem($personData);
            
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
     * Mapper les données d'une personne scanR vers un item Omeka S
     *
     * @param array $personData Données de la personne depuis scanR
     * @return array Données formatées pour Omeka S
     */
    protected function mapPersonToItem($personData)
    {
        $itemData = [
            'o:resource_class' => ['o:id' => null], // Devrait être configuré pour "Person" ou équivalent
            'o:resource_template' => ['o:id' => null],
            'o:item_set' => [],
        ];

        // Titre: nom complet
        if (!empty($personData['fullName'])) {
            $itemData['dcterms:title'][] = [
                'type' => 'literal',
                'property_id' => 1, // dcterms:title
                '@value' => $personData['fullName'],
            ];
        }

        // Prénom
        if (!empty($personData['firstName'])) {
            $itemData['foaf:firstName'][] = [
                'type' => 'literal',
                '@value' => $personData['firstName'],
            ];
        }

        // Nom
        if (!empty($personData['lastName'])) {
            $itemData['foaf:lastName'][] = [
                'type' => 'literal',
                '@value' => $personData['lastName'],
            ];
        }

        // ID scanR comme identifiant
        if (!empty($personData['id'])) {
            $itemData['dcterms:identifier'][] = [
                'type' => 'literal',
                'property_id' => 10, // dcterms:identifier
                '@value' => 'scanr:' . $personData['id'],
            ];
        }

        // Description avec les domaines
        if (!empty($personData['domains'])) {
            $domains = [];
            foreach ($personData['domains'] as $domain) {
                if (isset($domain['label'])) {
                    $domains[] = $domain['label'];
                }
            }
            if (!empty($domains)) {
                $itemData['dcterms:subject'][] = [
                    'type' => 'literal',
                    'property_id' => 3, // dcterms:subject
                    '@value' => implode(', ', $domains),
                ];
            }
        }

        // Affiliations
        if (!empty($personData['affiliations'])) {
            $affiliations = [];
            foreach ($personData['affiliations'] as $affiliation) {
                if (isset($affiliation['structure']['label'])) {
                    $affiliations[] = $affiliation['structure']['label'];
                }
            }
            if (!empty($affiliations)) {
                $itemData['dcterms:description'][] = [
                    'type' => 'literal',
                    'property_id' => 4, // dcterms:description
                    '@value' => 'Affiliations: ' . implode('; ', $affiliations),
                ];
            }
        }

        return $itemData;
    }
}
