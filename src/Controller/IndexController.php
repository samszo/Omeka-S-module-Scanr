<?php
namespace Scanr\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\ViewModel;
use Scanr\Service\ApiClient;
use Scanr\Form\SearchForm;
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

    public function associerAction()
    {
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
            $personData = $this->apiClient->getPersonById($personId);
            
            if (!$personData) {
                $this->messenger()->addError('Personne non trouvée');
                return $this->redirect()->toRoute('admin/scanr/search');
            }

            // Créer un item Omeka S avec les données de la personne
            $itemData = $this->mapPersonToItem($personData,$itemId);
            
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


    /**
     * Mapper les données d'une personne scanR vers un item Omeka S
     *
     * @param array $personData Données de la personne depuis scanR
     * @return array Données formatées pour Omeka S
     */
    protected function mapPersonToItem($personData)
    {
        if($personData["items"] && count($personData["items"])){
            $itemData = json_decode(json_encode($personData["items"][0]), true);
        }else{
            $itemData = [
                'o:resource_class' => ['o:id' => 107], //TODO : Devrait être configuré dans les paramètres du module
                'o:resource_template' => ['o:id' => '2'],
                'o:item_set' => [],
            ];
        }

        // Titre: nom complet
        if (!empty($personData['fullName'])) {
            if(!isset($itemData['dcterms:title'])){
                $itemData['dcterms:title'][] = [
                    'type' => 'literal',
                    'property_id' => $this->apiClient->getProperty('dcterms:title')->id()."", 
                    '@value' => $personData['fullName'],
                ];
            }
        }

        // Prénom
        if (!empty($personData['firstName'])) {
            if(!isset($itemData['foaf:firstName'])){
                $itemData['foaf:firstName'][] = [
                    'type' => 'literal',
                    'property_id' => $this->apiClient->getProperty('foaf:firstName')->id()."", 
                    '@value' => $personData['firstName'],
                ];
            }
        }

        // Nom
        if (!empty($personData['lastName'])) {
            if(!isset($itemData['foaf:lastName'])){
                $itemData['foaf:lastName'][] = [
                    'type' => 'literal',
                    'property_id' => $this->apiClient->getProperty('foaf:lastName')->id()."", 
                    '@value' => $personData['lastName'],
                ];
            }
        }

        // ID scanR comme identifiant
        if (!empty($personData['id'])) {
            if(!isset($itemData['dcterms:identifier'])){
                $itemData['dcterms:identifier'][] = [
                    'type' => 'literal',
                    'property_id' => $this->apiClient->getProperty('dcterms:identifier')->id()."", 
                    '@value' => 'scanr:' . $personData['id'],
                ];
            }
        }

        // Description avec les domaines
        if (!empty($personData['domains'])) {
            $itemData['dcterms:subject']=[];
            foreach ($personData['domains'] as $domain) {
                if (isset($domain['label'])) {
                    $concept = $this->getTag($domain);

                    $annotation = [];
                    $annotation['curation:rank'][] = [
                        'property_id' => $this->apiClient->getProperty('curation:rank')->id()."",
                        '@value' => $domain["count"]."",
                        'type' => 'literal',
                    ];

                    $itemData['dcterms:subject'][] = [
                        'property_id' => $this->apiClient->getProperty('dcterms:subject')->id()."",
                        'value_resource_id' => $concept->id(),
                        'type' => 'resource',
                        '@annotation' => $annotation,
                    ];
                }
            }
        }

        // Affiliations
        if (!empty($personData['affiliations'])) {
            $itemData['labo:hasOrga']=[];
            foreach ($personData['affiliations'] as $affiliation) {
                if (isset($affiliation['structure']['label'])) {
                    $orga = $this->getOrga($affiliation);
                    $annotation = [];
                    $annotation['curation:rank'][] = [
                        'property_id' => $this->apiClient->getProperty('curation:rank')->id()."",
                        '@value' => $affiliation["publicationsCount"],
                        'type' => 'literal',
                    ];
                    $annotation['curation:start'][] = [
                        'property_id' => $this->apiClient->getProperty('curation:start')->id()."",
                        '@value' => $affiliation["startDate"],
                        'type' => 'literal',
                    ];
                    $annotation['curation:end'][] = [
                        'property_id' => $this->apiClient->getProperty('curation:end')->id()."",
                        '@value' => $affiliation["endDate"],
                        'type' => 'literal',
                    ];
                    $itemData['labo:hasOrga'][] = [
                        'property_id' => $this->apiClient->getProperty('labo:hasOrga')->id()."",
                        'value_resource_id' => $orga->id(),
                        'type' => 'resource',
                        '@annotation' => $annotation,
                    ];
                }
            }
        }


        // Publications
        if (!empty($personData['publications'])) {
            $itemData['foaf:publications']=[];
            foreach ($personData['publications'] as $publi) {
                if (isset($publi['title'])) {
                    $annotation = [];
                    $annotation['dcterms:date'][] = [
                        'property_id' => $this->apiClient->getProperty('dcterms:date')->id()."",
                        '@value' => $publi["year"]."",
                        'type' => 'literal',//mettre un type date
                    ];
                    $annotation['dcterms:isReferencedBy'][] = [
                        'property_id' => $this->apiClient->getProperty('dcterms:isReferencedBy')->id()."",
                        '@value' => $publi["publication"],
                        'type' => 'literal',//mettre un type date
                    ];
                    $annotation['foaf:status'][] = [
                        'property_id' => $this->apiClient->getProperty('foaf:status')->id()."",
                        '@value' => $publi["role"],
                        'type' => 'literal',//mettre un type date
                    ];
                    $itemData['foaf:publications'][] = [
                        'type' => 'literal',
                        'property_id' => $this->apiClient->getProperty('foaf:publications')->id()."",
                        '@value' => $publi['title']["default"],
                        '@annotation' => $annotation,
                    ];
                }
            }
        }

        return $itemData;
    }


    /**
     * Récupère le tag au format skos
     *
     * @param array $tag
     * @return o:Item
     */
    protected function getTag($tag)
    {
        // Vérifie la présence de l'item pour gérer la création
        $param = [];
        $param['property'][0]['property'] = $this->apiClient->getProperty("skos:prefLabel")->id() . "";
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $tag["label"]["default"];
        $result = $this->api->search('items', $param)->getContent();
        if (count($result)) {
            return $result[0];
        } else {
            $oItem = [];
            $class = $this->apiClient->getRc('skos:Concept');
            $oItem['o:resource_class'] = ['o:id' => $class->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->apiClient->getProperty("dcterms:title")->id();
            $valueObject['@value'] = $tag["label"]["default"];
            $valueObject['type'] = 'literal';
            $oItem["dcterms:title"][] = $valueObject;
            $valueObject = [];
            $valueObject['property_id'] = $this->apiClient->getProperty("skos:prefLabel")->id();
            $valueObject['@value'] = $tag["label"]["default"];
            $valueObject['type'] = 'literal';
            $oItem["skos:prefLabel"][] = $valueObject;
            if($tag["type"]=="wikidata"){
                $valueObject = [];
                $valueObject['property_id'] = $this->apiClient->getProperty("dcterms:isReferencedBy")->id();
                $valueObject['@id'] = "https://www.wikidata.org/wiki/".$tag["code"];
                $valueObject['o:label'] = 'wikidata';
                $valueObject['type'] = 'uri';
                $oItem["dcterms:isReferencedBy"][] = $valueObject;
            }            
            // Création du tag
            $cpt = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            return $cpt;
        }
    }

    /**
     * Récupère l'organisation
     *
     * @param array $orga
     * @return o:Item
     */
    protected function getOrga($orga)
    {
        // Vérifie la présence de l'item pour gérer la création
        $param = [];
        $param['property'][0]['property'] = $this->apiClient->getProperty("dcterms:isReferencedBy")->id() . "";
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $orga["structure"]["id_name"];
        $result = $this->api->search('items', $param)->getContent();
        if (count($result)) {
            return $result[0];
        } else {
            $oItem = [];
            $class = $this->apiClient->getRc('labo:Organisme');
            $oItem['o:resource_class'] = ['o:id' => $class->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->apiClient->getProperty("dcterms:title")->id();
            $valueObject['@value'] = $orga["structure"]["label"]["default"];
            $valueObject['type'] = 'literal';
            $oItem["dcterms:title"][] = $valueObject;
            $valueObject = [];
            $valueObject['property_id'] = $this->apiClient->getProperty("dcterms:type")->id();
            $valueObject['@value'] = $this->getTypeFromOrga($orga);
            $valueObject['type'] = 'literal';
            $oItem["dcterms:type"][] = $valueObject;            
            $valueObject = [];
            $valueObject['property_id'] = $this->apiClient->getProperty("dcterms:isReferencedBy")->id();
            $valueObject['@value'] = $orga["structure"]["id_name"];
            $valueObject['type'] = 'literal';
            $oItem["dcterms:isReferencedBy"][] = $valueObject;
            // Création du tag
            $cpt = $this->api->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            return $cpt;
        }
    }

    function getTypeFromOrga($orga){
        if(substr($orga["structure"]["id"],0,2)=="ED")return "Ecole doctorale";
        else return $orga["structure"]["king"][0];
    }

}
