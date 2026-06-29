<?php
namespace Scanr\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
use Laminas\View\Model\ViewModel;
use Laminas\Authentication\AuthenticationService;
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

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Settings\UserSettings
     */
    protected $userSettings;

    /**
     * @var AuthenticationService
     */
    protected $auth;


    /**
     * @var \Scanr\Service\Geocoding
     */
    protected $geocoding;

    /**
     * @var \Scanr\Service\StructuresUpdater
     */
    protected $structuresUpdater;

    public function __construct(AuthenticationService $auth, ApiClient $apiClient, JsonlClient $jsonlClient, DuckClient $duckClient, SqlClient $sqlClient, SearchForm $searchForm, $api, $dispatcher, $settings = null, $userSettings = null, $geocoding = null, $structuresUpdater = null)
    {
        $this->auth               = $auth;
        $this->apiClient          = $apiClient;
        $this->duckClient         = $duckClient;
        $this->jsonlClient        = $jsonlClient;
        $this->sqlClient          = $sqlClient;
        $this->searchForm         = $searchForm;
        $this->api                = $api;
        $this->dispatcher         = $dispatcher;
        $this->settings           = $settings;
        $this->userSettings       = $userSettings;
        $this->geocoding          = $geocoding;
        $this->structuresUpdater  = $structuresUpdater;

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

    public function updateStructuresAction()
    {
        if (!$this->getRequest()->isPost()) {
            return $this->redirect()->toRoute('admin/scanr');
        }

        $jsonPath = dirname(__DIR__, 3) . '/Scanr/data/fr-esr-structures-recherche-publiques-actives.json';

        if (!file_exists($jsonPath)) {
            $this->messenger()->addError(sprintf(
                'Fichier introuvable : %s', $jsonPath
            ));
            return $this->redirect()->toRoute('admin/scanr');
        }

        try {
            $job = $this->dispatcher->dispatch(
                \Scanr\Job\UpdateStructures::class,
                ['json_path' => $jsonPath]
            );
            $this->messenger()->addSuccess(sprintf(
                'Mise à jour des structures lancée en arrière-plan (job #%d). Consultez les logs pour suivre la progression.',
                $job->getId()
            ));
        } catch (\Exception $e) {
            $this->messenger()->addError('Erreur lors du lancement du job : ' . $e->getMessage());
        }

        return $this->redirect()->toRoute('admin/scanr');
    }

       // ── AJAX CRUD expertises ──────────────────────────────────────────────

    public function isUserAllowed($user,$action,$item){
        $creatorRole = $user->getRole();
        /*vérification des droits
        ATTENTION : les droits donnés permettent d'accéder à l'admin d'Omeka 
        Global Administrator: full installation privileges.
        Supervisor: robust site and content privileges.
        Editor (Content Expert): full privileges for content creation.
        Reviewer: robust content privileges but can only delete own content.
        Author: create own content.
        Researcher: search and read privileges only.
        cf. https://omeka.org/s/docs/user-manual/admin/users/
        */
        switch ($creatorRole) {
            case "global_admin":
            case "site_admin":
                //peut tout faire
                $creatorAllowed = true;//["create"=>true,"update"=>true,"delete"=>true,"addkeyword"=>true];
                break;            
            case "reviewer"://le cas des chargés de valorisation et des directeurs de labo
                //uniquement les membres des labos dont ils ont la responsabilité
                $creatorAllowed = $this->isPersonResponsable($user,$item);
                break;            
            case "author"://le cas des enseignants chercheurs
                //uniquement leurs propres expertises
                $creatorAllowed = $this->isPersonCas($user,$item);
                break;            
            case "researcher":
                //juste la visualisation des expertises
                $creatorAllowed = false;
                break;            
            default:
                $creatorAllowed = false;
                break;
        }
        return $creatorAllowed;
    }

    public function isPersonResponsable($user,$item){

        $this->userSettings->setTargetId($user->getId());
        $labos = $this->userSettings->get('scanr_labos_admin',[]);
        $prop = $this->settings->get('scanr_properties_isInLabos', ['dcterms:isPartOf'])[0];
        $isInLabos = $item->value($prop, ['all' => true, 'default' => []]);
        foreach ($isInLabos as $v) {
            $vr = $v->valueResource();
            if(in_array($vr->id(),$labos))return true;
        }    
        return false;
    }

    public function isPersonCas($user,$item){

        $prop = $this->settings->get('scanr_properties_CasAccount', ['foaf:account'])[0];
        $v = $item->value($prop);
        $cas = $v ? $v->value() : "";
        return $cas == $user->getName() ? true : false;
    }

    public function expertiseAjaxAction()
    {
        $user =  $this->auth->getIdentity();
        $creatorId = $user->getId();
        $request = $this->getRequest();
        $action  = $this->params()->fromQuery('action')
                ?: $this->params()->fromPost('action', '');

        // ── IS ALLOWED ──────────────────────────────────────────────────────────
        if ($action === 'isAllowed') {
            $itemId    = (int) $this->params()->fromQuery('item_id', 0);
            $item = $this->api->read('items', $itemId)->getContent();
            $creatorAllowed = $this->isUserAllowed($user,$action,$item);
            return new JsonModel(['allowed' => $creatorAllowed]);
        }
        // ── LOAD ──────────────────────────────────────────────────────────
        if ($action === 'load') {
            $itemId    = (int) $this->params()->fromQuery('item_id', 0);
            $rankProp  = 'curation:rank';
            $conceptProps = $this->settings
                ? (array) $this->settings->get('scanr_properties_hasConcept', ['dcterms:subject'])
                : ['dcterms:subject'];

            try {
                $item = $this->api->read('items', $itemId)->getContent();
                $creatorAllowed = $this->isUserAllowed($user,$action,$item);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => 'Item introuvable']);
            }

            // Concepts liés à cet item via les propriétés configurées
            $seenIds    = [];
            $conceptVals = [];
            foreach ($conceptProps as $prop) {
                $vals = $item->value($prop, ['all' => true, 'default' => []]);
                foreach ($vals as $v) {
                    $vr = $v->valueResource();
                    if ($vr) {
                        $rid = $vr->id();
                        if (!isset($seenIds[$rid])) {
                            $seenIds[$rid]  = true;
                            //récupère le rank
                            $anno = $v->valueAnnotation();
                            $rank = $anno ? $v->valueAnnotation()->value("curation:rank")->__toString() : 0;
                            $conceptVals[]  = [
                                'value_resource_id' => $rid,
                                'display_title'     => $vr->displayTitle(),
                                '_sourceProp'       => $prop,
                                'rank'         => $rank,
                                'creatorTitle' => "Scanr",
                                'creatorId'    => 0,
                                'created'      => $vr->modified()->format('d/m/Y'),
                            ];
                        }
                    }
                }
            }

            // Expertises existantes où cet item est la source
            $propSourceId = $this->apiClient->getProperty('dcterms:source')->id();
            $existingRaw  = [];
            if ($propSourceId) {
                try {
                    $existingRaw = $this->api->search('items', [
                        'property' => [[
                            'joiner'   => 'and',
                            'property' => $propSourceId,
                            'type'     => 'res',
                            'text'     => $itemId,
                        ]],
                        'per_page' => 1000,
                    ])->getContent();
                } catch (\Exception $e) {
                    $existingRaw = [];
                }
            }

            // Créateur courant
            $creatorTitle = '';
            if ($creatorId) {
                try {
                    $creatorTitle = $user->getName();
                } catch (\Exception $e) {}
            }

            // Formatage des expertises brutes
            $formatExpertise = function ($exp) use ($rankProp, $creatorId) {
                $rankVals  = $exp->value($rankProp, ['all' => true, 'default' => []]);
                $lastRank  = count($rankVals) ? (int) $rankVals[count($rankVals) - 1]->value() : 0;
                /*version avec une propriété créateur
                $creatorV  = $exp->value('dcterms:creator');
                $cId       = $creatorV && $creatorV->valueResource() ? $creatorV->valueResource()->id() : 0;
                $cTitle    = $creatorV && $creatorV->valueResource() ? $creatorV->valueResource()->displayTitle() : 'ScanR';
                */
                //version avec le propriétaire comme créateur
                $creatorV  = $exp->owner();
                $cId       = $creatorV->id();
                $cTitle    = $creatorV->name();

                $expV      = $exp->value('valo:expertise');
                $expRid    = $expV && $expV->valueResource() ? $expV->valueResource()->id() : 0;
                $created   = $exp->created()
                    ? $exp->created()->format('d/m/Y')
                    : '-';
                return [
                    'o:id'         => $exp->id(),
                    'rank'         => $lastRank,
                    'cls'          => $lastRank > 0 ? 'pos' : 'neg',
                    'sign'         => $lastRank > 0 ? '+' : '',
                    'creatorId'    => $cId,
                    'creatorTitle' => $cTitle,
                    'created'      => $created,
                    'kwId'         => $expRid,
                ];
            };

            // Indexation expertises par concept (valo:expertise)
            $expByKw = [];
            foreach ($existingRaw as $exp) {
                $fe = $formatExpertise($exp);
                if ($fe['kwId']) {
                    $expByKw[$fe['kwId']][] = $fe;
                }
            }

            // Construction du tableau keywords (inspiré de loadPerson)
            $keywords = [];
            foreach ($conceptVals as $c) {
                $rid      = $c['value_resource_id'];
                $expList  = $expByKw[$rid] ?? [];
                //on ajoute l'expertise scanr
                if($c["rank"]){
                    $expList[]=[
                        'rank'         => intval($c["rank"]),
                        'cls'          => $c["rank"] > 0 ? 'pos' : 'neg',
                        'sign'         => $c["rank"] > 0 ? '+' : '',
                        'creatorId'    => $c["creatorId"],
                        'creatorTitle' => $c["creatorTitle"],
                        'created'      => $c["created"],
                        'kwId'         => $c["value_resource_id"],
                    ];
                }                
                $rank     = array_sum(array_column($expList, 'rank'));
                $hasExpert = (bool) array_filter($expList, fn($e) => $e['creatorId'] == $creatorId);
                $myRank    = 0;
                if ($hasExpert) {
                    foreach ($expList as $e) {
                        if ($e['creatorId'] == $creatorId) { $myRank = $e['rank']; break; }
                    }
                }
                // Ajoute un slot placeholder si le créateur n'a pas encore d'expertise
                if (!$hasExpert && $creatorAllowed) {
                    $expList[] = [
                        'o:id'         => null,
                        'rank'         => 0,
                        'cls'          => 'pos',
                        'sign'         => '',
                        'creatorId'    => $creatorId,
                        'creatorTitle' => $creatorTitle,
                        'created'      => '-',
                        'kwId'         => $rid,
                    ];
                }
                $keywords[] = [
                    'value_resource_id' => $rid,
                    'display_title'     => $c['display_title'],
                    '_sourceProp'       => $c['_sourceProp'],
                    'rank'              => $rank,
                    'cls'               => $rank > 0 ? 'pos' : 'neg',
                    'sign'              => $rank > 0 ? '+' : '',
                    'hasExpert'         => $hasExpert,
                    'myRank'            => $myRank,
                    'expertises'        => $expList,
                ];
            }

            return new JsonModel([
                'ok'           => true,
                'itemId'       => $itemId,
                'creatorId'    => $creatorId,
                'creatorTitle' => $creatorTitle,
                'creatorAllowed' => $creatorAllowed,                
                'rankProp'     => $rankProp,
                'keywords'     => $keywords,
            ]);
        }

        // ── CREATE ────────────────────────────────────────────────────────
        if ($action === 'create') {

            $body      = json_decode($request->getContent(), true) ?? [];
            $sourceId  = (int) ($body['sourceId']  ?? 0);
            $expId     = (int) ($body['expertiseId'] ?? 0);
            //$creatorId = (int) ($body['creatorId']  ?? 0);
            $rank      = (int) ($body['rank']        ?? 0);

            $itemSource = $this->api->read('items', $sourceId)->getContent();
            $creatorAllowed = $this->isUserAllowed($user,$action,$itemSource);
            if(!$creatorAllowed) return new JsonModel(['ok' => false, 'message' => "Vous n'êtes pas autorisé à faire cette action"]);


            $rtId  = $this->apiClient->getRt('Expertise')->id();
            $rcId  = $this->apiClient->getRc('valo:Expertises_all')->id();
            $pIds  = [
                'dcterms:title'    => $this->apiClient->getProperty('dcterms:title')->id(),
                'curation:rank'    => $this->apiClient->getProperty('curation:rank')->id(),
                'dcterms:creator'  => $this->apiClient->getProperty('dcterms:creator')->id(),
                'valo:expertise'   => $this->apiClient->getProperty('valo:expertise')->id(),
                'dcterms:source'   => $this->apiClient->getProperty('dcterms:source')->id(),
            ];

            // Noms pour le titre
            $sourceName  = '';
            $creatorName = $user->getName();
            $expName     = '';
            try { $sourceName  = $itemSource->displayTitle(); } catch (\Exception $e) {}
            //try { $creatorName = $this->api->read('items', $creatorId)->getContent()->displayTitle(); } catch (\Exception $e) {}
            try { $expName     = $this->api->read('items', $expId)->getContent()->displayTitle(); } catch (\Exception $e) {}



            $title = sprintf(
                'Expertise - %s = %d - pour %s fait par %s le %s',
                $expName, $rank, $sourceName, $creatorName,
                (new \DateTime())->format('d/m/Y')
            );

            $data = [
                'o:resource_template' => $rtId  ? ['o:id' => $rtId]  : null,
                'o:resource_class'    => $rcId  ? ['o:id' => $rcId]  : null,
                'dcterms:title'  => [['@value' => $title,        'type' => 'literal',       'property_id' => $pIds['dcterms:title']]],
                'curation:rank'  => [['@value' => (string) $rank, 'type' => 'literal',       'property_id' => $pIds['curation:rank']]],
                //'dcterms:creator'=> [['value_resource_id' => $creatorId, 'type' => 'resource:item', 'property_id' => $pIds['dcterms:creator']]],
                'valo:expertise' => [['value_resource_id' => $expId,     'type' => 'resource:item', 'property_id' => $pIds['valo:expertise']]],
                'dcterms:source' => [['value_resource_id' => $sourceId,  'type' => 'resource:item', 'property_id' => $pIds['dcterms:source']]],
            ];

            try {
                $created = $this->api->create('items', $data)->getContent();
                return new JsonModel(['ok' => true, 'id' => $created->id(), 'title' => $title]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── UPDATE ────────────────────────────────────────────────────────
        if ($action === 'update') {

            $body    = json_decode($request->getContent(), true) ?? [];
            $id      = (int) ($body['id']   ?? 0);
            $rank    = (int) ($body['rank']  ?? 0);

            $rankPropId = $this->apiClient->getProperty('curation:rank')->id();
            $titlePropId = $this->apiClient->getProperty('dcterms:title')->id();

            try {
                $existing = $this->api->read('items', $id)->getContent();
                $creatorAllowed = $this->isUserAllowed($user,$action,$this->getExpertiseSource($existing));
                if(!$creatorAllowed) return new JsonModel(['ok' => false, 'message' => "Vous n'êtes pas autorisé à faire cette action"]);

                $dataItem = json_decode(json_encode($existing), true);
                $newTitle = sprintf(
                    'Expertise -> %s = %d - pour %s fait par %s le %s',
                    $dataItem["valo:expertise"][0]["display_title"], 
                    $rank, 
                    $dataItem["dcterms:source"][0]["display_title"], 
                    $user->getName(),
                    (new \DateTime())->format('d/m/Y')
                );
                $dataItem["dcterms:title"][0]["@value"]=$newTitle;
                $dataItem["curation:rank0"][0]["@value"]=$rank;
                $this->api->update('items', $id, $dataItem, [], ['isPartial' => true, 'collectionAction' => 'replace']);
                return new JsonModel(['ok' => true]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── ADD KEYWORD ───────────────────────────────────────────────────
        if ($action === 'addKeyword') {
            $body      = json_decode($request->getContent(), true) ?? [];
            $sourceId  = (int) ($body['sourceId']  ?? 0);
            $conceptId = (int) ($body['conceptId'] ?? 0);

            if (!$sourceId || !$conceptId) {
                return new JsonModel(['ok' => false, 'message' => 'sourceId et conceptId requis']);
            }

            try {
                $itemSource = $this->api->read('items', $sourceId)->getContent();
                $creatorAllowed = $this->isUserAllowed($user, $action, $itemSource);
                if (!$creatorAllowed) {
                    return new JsonModel(['ok' => false, 'message' => "Vous n'êtes pas autorisé à faire cette action"]);
                }

                $conceptProps = $this->settings
                    ? (array) $this->settings->get('scanr_properties_hasConcept', ['dcterms:subject'])
                    : ['dcterms:subject'];
                $prop   = $conceptProps[0] ?? 'dcterms:subject';
                $propId = $this->apiClient->getProperty($prop)->id();

                // Vérifie que le concept n'est pas déjà lié
                foreach ($conceptProps as $cp) {
                    foreach ($itemSource->value($cp, ['all' => true, 'default' => []]) as $v) {
                        $vr = $v->valueResource();
                        if ($vr && $vr->id() === $conceptId) {
                            return new JsonModel(['ok' => false, 'message' => 'Ce mot-clef est déjà lié à cette personne']);
                        }
                    }
                }

                $concept = $this->api->read('items', $conceptId)->getContent();

                // Ajout partiel : append la nouvelle valeur sans toucher aux existantes
                $this->api->update('items', $sourceId, [
                    $prop => [[
                        'value_resource_id' => $conceptId,
                        'type'              => 'resource:item',
                        'property_id'       => $propId,
                    ]],
                ], [], ['isPartial' => true, 'collectionAction' => 'append']);

                return new JsonModel([
                    'ok'      => true,
                    'keyword' => [
                        'value_resource_id' => $conceptId,
                        'display_title'     => $concept->displayTitle(),
                        '_sourceProp'       => $prop,
                        'rank'              => 0,
                        'cls'               => '',
                        'sign'              => '',
                        'hasExpert'         => false,
                        'myRank'            => 0,
                        'expertises'        => [],
                    ],
                ]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── DELETE ────────────────────────────────────────────────────────
        if ($action === 'delete') {
            $body = json_decode($request->getContent(), true) ?? [];
            $id   = (int) ($body['id'] ?? 0);
            try {
                $existing = $this->api->read('items', $id)->getContent();
                $creatorAllowed = $this->isUserAllowed($user,$action,$this->getExpertiseSource($existing));
                if(!$creatorAllowed) return new JsonModel(['ok' => false, 'message' => "Vous n'êtes pas autorisé à faire cette action"]);

                $this->api->delete('items', $id);
                return new JsonModel(['ok' => true]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── UPDATE STRUCTURE (JSON MESR → item + géocodage) ──────────────────
        if ($action === 'updateStructure') {
            $body   = json_decode($request->getContent(), true) ?? [];
            $itemId = (int) ($body['itemId'] ?? 0);

            if (!$itemId) {
                return new JsonModel(['ok' => false, 'message' => 'itemId requis']);
            }

            try {
                $this->structuresUpdater->run(['item_id' => $itemId]);
                return new JsonModel(['ok' => true]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── GEOCODE (Adresse → coordonnées) ──────────────────────────────────
        if ($action === 'geocode') {
            $body   = json_decode($request->getContent(), true) ?? [];
            $itemId = (int) ($body['itemId'] ?? 0);

            if (!$itemId) {
                return new JsonModel(['ok' => false, 'message' => 'itemId requis']);
            }

            try {
                $item    = $this->api->read('items', $itemId)->getContent();
                $address = $this->geocoding->addressFromItem($item);

                if (!$address) {
                    return new JsonModel(['ok' => false, 'message' => 'Aucune adresse trouvée sur cet item (schema:address, schema:postalCode, schema:addressLocality)']);
                }

                $coords = $this->geocoding->geocodeAddress($address);

                if (!$coords) {
                    return new JsonModel(['ok' => false, 'message' => "Aucun résultat de géocodage pour : {$address}"]);
                }

                // Récupère le marker existant s'il y en a un
                $existing = $this->api->search('mapping_features', ['item_id' => $itemId])->getContent();
                $currentLat = $currentLng = null;
                if (!empty($existing)) {
                    // Récupère lat/lng depuis le feature existant via JSON-LD
                    $geo = json_decode(json_encode($existing[0]), true);
                    $currentLat = $geo['o-module-mapping:lat'] ?? null;
                    $currentLng = $geo['o-module-mapping:lng'] ?? null;
                }

                return new JsonModel([
                    'ok'         => true,
                    'address'    => $address,
                    'lat'        => $coords['lat'],
                    'lng'        => $coords['lng'],
                    'label'      => $coords['label'],
                    'currentLat' => $currentLat,
                    'currentLng' => $currentLng,
                    'hasFeature' => !empty($existing),
                ]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── SAVE GEOCODE (module Mapping) ─────────────────────────────────────
        if ($action === 'saveGeocode') {
            $body   = json_decode($request->getContent(), true) ?? [];
            $itemId = (int)   ($body['itemId'] ?? 0);
            $lat    = (float) ($body['lat']    ?? 0);
            $lng    = (float) ($body['lng']    ?? 0);

            if (!$itemId || ($lat === 0.0 && $lng === 0.0)) {
                return new JsonModel(['ok' => false, 'message' => 'itemId, lat et lng requis']);
            }

            try {
                $this->structuresUpdater->saveFeature($itemId, $lat, $lng, $this->api);
                return new JsonModel(['ok' => true, 'lat' => $lat, 'lng' => $lng]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        return new JsonModel(['ok' => false, 'message' => 'Action inconnue : ' . $action]);
    }

    public function getExpertiseSource($exp){
        $source = $exp->value('dcterms:source')->valueResource();
        return $source;
    }

    // ── EUR Convergences (agent IA) ───────────────────────────────────────

    const IA_SERVICES = ['albert','claude', 'chatgpt', 'gemini', 'ollama'];

    public function eurConvergenceAjaxAction()
    {
        $user = $this->auth->getIdentity();
        if (!$user) {
            return new JsonModel(['ok' => false, 'message' => 'Non authentifié']);
        }

        $request = $this->getRequest();
        $action  = $this->params()->fromQuery('action')
                ?: $this->params()->fromPost('action', '');

        if ($action !== 'evaluate') {
            return new JsonModel(['ok' => false, 'message' => 'Action inconnue : ' . $action]);
        }

        $body      = json_decode($request->getContent(), true) ?? [];
        $itemIds   = array_map('intval', $body['item_ids'] ?? []);
        $iaService = $body['ia_service']
            ?? ($this->settings ? $this->settings->get('scanr_ia_service', 'claude') : 'claude');

        if (empty($itemIds)) {
            return new JsonModel(['ok' => false, 'message' => 'Aucun chercheur fourni']);
        }

        if (!in_array($iaService, self::IA_SERVICES, true)) {
            return new JsonModel(['ok' => false, 'message' => 'Service IA inconnu : ' . $iaService]);
        }

        // Limite à 50 chercheurs par appel pour rester dans les limites de tokens
        $itemIds = array_slice($itemIds, 0, 50);

        $conceptProps = $this->settings
            ? (array) $this->settings->get('scanr_properties_hasConcept', ['dcterms:subject'])
            : ['dcterms:subject'];

        $researchers = [];
        foreach ($itemIds as $itemId) {
            try {
                $item = $this->api->read('items', $itemId)->getContent();

                $keywords = [];
                foreach ($conceptProps as $prop) {
                    foreach ($item->value($prop, ['all' => true, 'default' => []]) as $v) {
                        $vr         = $v->valueResource();
                        $keywords[] = $vr ? $vr->displayTitle() : $v->value();
                    }
                }
                foreach ($item->value('skos:hasTopConcept', ['all' => true, 'default' => []]) as $v) {
                    $vr         = $v->valueResource();
                    $keywords[] = $vr ? $vr->displayTitle() : $v->value();
                }
                $keywords = array_values(array_unique(array_filter($keywords)));

                $publications = [];
                foreach ($item->value('foaf:publications', ['all' => true, 'default' => []]) as $v) {
                    $publications[] = $v->value();
                }

                $coAuthors = [];
                foreach ($item->value('bibo:contributorList', ['all' => true, 'default' => []]) as $v) {
                    $vr          = $v->valueResource();
                    $coAuthors[] = $vr ? $vr->displayTitle() : $v->value();
                }

                $axes = [];
                foreach ($item->value('dcterms:hasPart', ['all' => true, 'default' => []]) as $v) {
                    $vr     = $v->valueResource();
                    $axes[] = $vr ? $vr->displayTitle() : $v->value();
                }

                $researchers[] = [
                    'id'           => $itemId,
                    'name'         => $item->displayTitle(),
                    'adminUrl'         => $item->adminUrl(null, true),
                    'keywords'     => array_slice($keywords,     0, 20),
                    'publications' => array_slice($publications, 0, 10),
                    'co_authors'   => array_slice($coAuthors,    0, 10),
                    'axes'         => array_slice($axes,         0, 10),
                ];
            } catch (\Exception $e) {
                // item introuvable ou inaccessible → ignoré
            }
        }

        if (empty($researchers)) {
            return new JsonModel(['ok' => false, 'message' => 'Aucun chercheur trouvé']);
        }

        [$apiKey, $model, $apiUrl] = $this->getIaServiceConfig($iaService);

        if (empty($apiKey) && $iaService !== 'ollama') {
            return new JsonModel(['ok' => false, 'message' => "Clé API {$iaService} non configurée dans les paramètres du module."]);
        }

        try {
            $evaluations = $this->callIaApi($iaService, $apiKey, $model, $apiUrl, $researchers);
            return new JsonModel(['ok' => true, 'evaluations' => $evaluations]);
        } catch (\Exception $e) {
            return new JsonModel(['ok' => false, 'message' => "Erreur API {$iaService} : " . $e->getMessage()]);
        }
        return [$apiKey, $model, $apiUrl];
    }

    private function getIaServiceConfig(string $service): array
    {
        switch ($service) {
            case 'chatgpt':
                $apiKey = $this->settings ? $this->settings->get('scanr_chatgpt_api_key', '') : '';
                $model  = $this->settings ? $this->settings->get('scanr_chatgpt_model', 'gpt-4o-mini') : 'gpt-4o-mini';
                $model  = $model ?: 'gpt-4o-mini';
                $apiUrl = 'https://api.openai.com/v1/chat/completions';
                break;
            case 'gemini':
                $apiKey = $this->settings ? $this->settings->get('scanr_gemini_api_key', '') : '';
                $model  = $this->settings ? $this->settings->get('scanr_gemini_model', 'gemini-1.5-flash') : 'gemini-1.5-flash';
                $model  = $model ?: 'gemini-1.5-flash';
                $apiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent';
                break;
            case 'ollama':
                $apiKey  = '';
                $model   = $this->settings ? $this->settings->get('scanr_ollama_model', 'llama3') : 'llama3';
                $model   = $model ?: 'llama3';
                $baseUrl = $this->settings ? $this->settings->get('scanr_ollama_url', 'http://localhost:11434') : 'http://localhost:11434';
                $apiUrl  = rtrim($baseUrl, '/') . '/api/generate';
                break;
            case 'claude':
                $apiKey = $this->settings ? $this->settings->get('scanr_claude_api_key', '') : '';
                $model  = $this->settings ? $this->settings->get('scanr_claude_model', 'claude-haiku-4-5-20251001') : 'claude-haiku-4-5-20251001';
                $model  = $model ?: 'claude-haiku-4-5-20251001';
                $apiUrl = 'https://api.anthropic.com/v1/messages';
                break;
            case 'albert':
            default:
                $apiKey = $this->settings ? $this->settings->get('scanr_albert_api_key', '') : '';
                $model  = $this->settings ? $this->settings->get('scanr_albert_model', 'openai/gpt-oss-120b') : 'openai/gpt-oss-120b';
                $model  = $model ?: 'openai/gpt-oss-120b';
                $apiUrl = 'https://albert.api.etalab.gouv.fr/v1/chat/completions';
                break;
        }
        return [$apiKey, $model, $apiUrl];
    }

    private function callIaApi(string $service, string $apiKey, string $model, string $apiUrl, array $researchers): array
    {
        set_time_limit(320);

        $eurs = [
            'arts'        => 'Arts, créations, technologies & industries culturelles',
            'transitions' => 'Transitions numériques, écologiques & économiques',
            'care'        => 'Care – prendre soin : santé mentale, handicap, migrations',
            'democratie'  => 'Enjeux démocratiques contemporains, politiques publiques, risques géopolitiques',
        ];

        $eursText = implode("\n", array_map(
            fn($k, $v) => "- {$k} : {$v}",
            array_keys($eurs),
            array_values($eurs)
        ));

        $promptTemplate = "Tu es un expert en pilotage de la science et en évaluation de la recherche académique.\n\n"
            . "Voici les 4 Ecoles Universitaires de Recherche (EUR) :\n{$eursText}\n\n"
            . "Pour chaque chercheur fourni, évalue la convergence de ses recherches avec chacune des 4 EUR. "
            . "Attribue un score de 0 (aucune convergence) à 100 (convergence totale) pour chaque EUR. "
            . "Base-toi sur les mots-clefs (skos:hasTopConcept, dcterms:subject), "
            . "les titres de publications (foaf:publications) et les co-auteurs (bibo:contributorList).\n\n"
            . "Format de réponse (JSON strict, aucun texte autour) :\n"
            . '{"evaluations":[{"id":<id>,"name":"<nom>","scores":{"arts":<0-100>,"transitions":<0-100>,"care":<0-100>,"democratie":<0-100>},"justification":"<1-2 phrases>"}]}';

        $apiParams = ['model' => $model, 'max_tokens' => 4096, "temperature" => 0.3];

        $agent = $this->findOrCreateAgent("expert EUR Convergences [{$service}]", $model, $apiUrl, $promptTemplate, $apiParams);

        $evaluations = [];
        $lastApiCallAt = null;
        foreach ($researchers as $r) {
            $existe = $this->findExistEval($agent->id(), $r['id']);
            if (!empty($existe)) {
                $eval         = json_decode($existe[0]->value('curation:data')->value(), true);
                $eval['axes'] = $r['axes'];
                $eval['adminUrl'] = $r['adminUrl'];
                $evaluations[] = $eval;
                continue;
            }

            $researchersText  = "\n---\n";
            $researchersText .= "ID: {$r['id']}\n";
            $researchersText .= "Nom: {$r['name']}\n";
            if (!empty($r['keywords']))     $researchersText .= "Mots-clefs: "   . implode(', ', $r['keywords'])     . "\n";
            if (!empty($r['publications'])) $researchersText .= "Publications: " . implode(' | ', $r['publications']) . "\n";
            if (!empty($r['co_authors']))   $researchersText .= "Co-auteurs: "   . implode(', ', $r['co_authors'])   . "\n";

            $prompt  = $promptTemplate . "\n\nChercheurs à évaluer :" . $researchersText;

            // Limite à 10 appels/minute (soit 1 appel toutes les 6 secondes)
            if ($service == "albert" && $lastApiCallAt !== null) {
                $elapsed = microtime(true) - $lastApiCallAt;
                $minInterval = 6.0;
                if ($elapsed < $minInterval) {
                    usleep((int) (($minInterval - $elapsed) * 1000000));
                }
            }
            $lastApiCallAt = microtime(true);
            
            $content = $this->callServiceHttp($service, $apiKey, $model, $apiUrl, $prompt, $apiParams);

            if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
                $content = $m[1];
            }

            $result = json_decode($content, true);
            if (!isset($result['evaluations']) || !is_array($result['evaluations'])) {
                throw new \Exception("Réponse {$service} invalide : " . substr($content, 0, 300));
            }

            $eval         = $result['evaluations'][0];
            $eval['axes'] = $r['axes'];
            $eval['adminUrl'] = $r['adminUrl'];

            try {
                $this->createEurEvaluationItem($agent, $eval);
            } catch (\Exception $e) {
                error_log('[Scanr] EUR persistance Omeka : ' . $e->getMessage());
            }

            $evaluations[] = $eval;
        }

        return $evaluations;
    }

    private function callServiceHttp(string $service, string $apiKey, string $model, string $apiUrl, string $prompt, array $apiParams): string
    {
        switch ($service) {
            case 'chatgpt': return $this->callChatgptHttp($apiKey, $model, $apiUrl, $prompt);
            case 'gemini':  return $this->callGeminiHttp($apiKey, $apiUrl, $prompt);
            case 'ollama':  return $this->callOllamaHttp($model, $apiUrl, $prompt);
            case 'albert':  return $this->callAlbertHttp($apiKey, $model, $apiUrl, $prompt, $apiParams);
            default:        return $this->callAlbertHttp($apiKey, $model, $apiUrl, $prompt);
        }
    }

    private function callClaudeHttp(string $apiKey, string $apiUrl, string $prompt, array $apiParams): string
    {
        $payload = json_encode(array_merge($apiParams, [
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]), JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
            ],
            CURLOPT_TIMEOUT => 90,
        ]);

        [$response, $httpCode, $curlErr] = $this->curlExec($ch);

        if ($curlErr) throw new \Exception('cURL : ' . $curlErr);
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new \Exception("HTTP {$httpCode} : " . ($err['error']['message'] ?? $response));
        }

        $decoded = json_decode($response, true);
        return $decoded['content'][0]['text'] ?? '';
    }

    private function callChatgptHttp(string $apiKey, string $model, string $apiUrl, string $prompt): string
    {
        $payload = json_encode([
            'model'       => $model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => 4096,
            'temperature' => 0.3,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 90,
        ]);

        [$response, $httpCode, $curlErr] = $this->curlExec($ch);

        if ($curlErr) throw new \Exception('cURL : ' . $curlErr);
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new \Exception("HTTP {$httpCode} : " . ($err['error']['message'] ?? $response));
        }

        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }

    private function callAlbertHttp(string $apiKey, string $model, string $apiUrl, string $prompt, array $apiParams): string
    {
        $payload = json_encode([
            'model'       => $model,
            'messages'    => [['role' => 'user', 'content' => $prompt]],
            'max_tokens'  => $apiParams["max_tokens"],
            'temperature' => $apiParams["temperature"]
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_TIMEOUT => 90,
        ]);

        [$response, $httpCode, $curlErr] = $this->curlExec($ch);

        if ($curlErr) throw new \Exception('cURL : ' . $curlErr);
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new \Exception("HTTP {$httpCode} : " . ($err['error']['message'] ?? $response));
        }

        $decoded = json_decode($response, true);
        return $decoded['choices'][0]['message']['content'] ?? '';
    }


    private function callGeminiHttp(string $apiKey, string $apiUrl, string $prompt): string
    {
        $payload = json_encode([
            'contents'         => [['parts' => [['text' => $prompt]]]],
            'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 4096],
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl . '?key=' . $apiKey);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 90,
        ]);

        [$response, $httpCode, $curlErr] = $this->curlExec($ch);

        if ($curlErr) throw new \Exception('cURL : ' . $curlErr);
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new \Exception("HTTP {$httpCode} : " . ($err['error']['message'] ?? $response));
        }

        $decoded = json_decode($response, true);
        return $decoded['candidates'][0]['content']['parts'][0]['text'] ?? '';
    }

    private function callOllamaHttp(string $model, string $apiUrl, string $prompt): string
    {
        $payload = json_encode([
            'model'  => $model,
            'prompt' => $prompt,
            'stream' => false,
        ], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($apiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT        => 120,
        ]);

        [$response, $httpCode, $curlErr] = $this->curlExec($ch);

        if ($curlErr) throw new \Exception('cURL : ' . $curlErr);
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            throw new \Exception("HTTP {$httpCode} : " . ($err['error'] ?? $response));
        }

        $decoded = json_decode($response, true);
        return $decoded['response'] ?? '';
    }

    private function curlExec($ch): array
    {
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        return [$response, $httpCode, $curlErr];
    }

    /**
     * Recherche l'évaluation pour le chercheur 
     * L'unicité est garantie par la combinaison agent + source .
     */
    private function findExistEval(
        string $idAgent,
        string $idSource
    ) {
        // Recherche un agen existant identifié par le modèle utilisé
        $rcValo = $this->apiClient->getRc('valo:Expertises_all');
        $pSrc     = $this->apiClient->getProperty('dcterms:source')->id();
        $pCrea     = $this->apiClient->getProperty('dcterms:creator')->id();

        $existing = $this->api->search('items', [
            'resource_class_id' => [$rcValo->id()],
            'property'          => [[
                'joiner'   => 'and',
                'property' => $pSrc,
                'type'     => 'res',
                'text'     => $idSource,
            ],
            [
                'joiner'   => 'and',
                'property' => $pCrea,
                'type'     => 'res',
                'text'     => $idAgent,
            ]],
            'per_page' => 1,
        ])->getContent();

        return $existing;
    }

    /**
     * Trouve ou crée un item dctype:Service décrivant l'agent IA EUR.
     * L'unicité est garantie par la combinaison modèle + URL du service.
     */
    private function findOrCreateAgent(
        string $action,
        string $model,
        string $apiUrl,
        string $promptTemplate,
        array  $apiParams
    ) {
        // Recherche un agent existant identifié par le modèle utilisé
        $rcAgent = $this->apiClient->getRc('foaf:Agent');
        $pTitle = $this->apiClient->getProperty('dcterms:title')->id();
        $title = "Agent ".$action." [{$model}]";

        $existing = $this->api->search('items', [
            'resource_class_id' => [$rcAgent->id()],
            'property'          => [[
                'joiner'   => 'and',
                'property' => $pTitle,
                'type'     => 'eq',
                'text'     => $title,
            ]],
            'per_page' => 1,
        ])->getContent();

        if (!empty($existing)) {
            return $existing[0];
        }

        // Crée le service
        $pType  = $this->apiClient->getProperty('dcterms:type')->id();
        $pDesc  = $this->apiClient->getProperty('dcterms:description')->id();
        $pConf  = $this->apiClient->getProperty('dcterms:conformsTo')->id();
        $pRef   = $this->apiClient->getProperty('dcterms:isReferencedBy')->id();

        $data = [
            'o:resource_class'    => ['o:id' => $rcAgent->id()],
            'dcterms:title'       => [['@value' => $title, 'type' => 'literal',    'property_id' => $pTitle]],
            'dcterms:type'        => [['@value' => $model,                                      'type' => 'literal',    'property_id' => $pType]],
            'dcterms:description' => [['@value' => $promptTemplate,                             'type' => 'literal',    'property_id' => $pDesc]],
            'dcterms:conformsTo'  => [['@value' => json_encode($apiParams, JSON_UNESCAPED_UNICODE), 'type' => 'literal', 'property_id' => $pConf]],
            'dcterms:isReferencedBy' => [['@id' => $apiUrl, 'o:label' => 'Anthropic Messages API', 'type' => 'uri',    'property_id' => $pRef]],
        ];

        return $this->api->create('items', $data, [], ['continueOnError' => true])->getContent();
    }

    /**
     * Crée un item valo:Expertises_all enregistrant le résultat complet
     * de l'évaluation, avec une value annotation par chercheur (scores + justification).
     */
    private function createEurEvaluationItem($agent, array $eval): void
    {
        $rcId   = $this->apiClient->getRc('valo:Expertises_all')->id();
        $pTitle = $this->apiClient->getProperty('dcterms:title')->id();
        $pSrc   = $this->apiClient->getProperty('dcterms:source')->id();
        $pDesc  = $this->apiClient->getProperty('dcterms:description')->id();
        $pSubj  = $this->apiClient->getProperty('dcterms:subject')->id();
        $pCrea  = $this->apiClient->getProperty('dcterms:creator')->id();
        $pRank  = $this->apiClient->getProperty('curation:rank')->id();
        $pData = $this->apiClient->getProperty('curation:data')->id();

        $title = "Evaluation EUR convergences : ".$agent->displayTitle()." —> ".$eval["name"]." — " . (new \DateTime())->format('d/m/Y H:i');

        $data = [
            'o:resource_class'    => ['o:id' => $rcId],
            'dcterms:title'       => [['@value' => $title, 'type' => 'literal', 'property_id' => $pTitle]],
            'dcterms:source'      => [['value_resource_id' => $eval["id"], 'type' => 'resource:item', 'property_id' => $pSrc]],
            'dcterms:creator'      => [['value_resource_id' => $agent->id(), 'type' => 'resource:item', 'property_id' => $pCrea]],
            'dcterms:description' => [['@value' => $eval["justification"], 'type' => 'literal', 'property_id' => $pDesc]],
            'dcterms:subject'     => [],
            'curation:data' => [['@value' => json_encode($eval, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'type' => 'literal', 'property_id' => $pData]],
        ];

         $data['dcterms:subject']=[];

        foreach ($eval["scores"] as $k=>$v) {

            $data['dcterms:subject'][] = [
                '@value' => $k." = ".$v,
                'type'              => 'literal',
                'property_id'       => $pSubj,
                '@annotation'       => [
                    'curation:rank'       => [[
                        'property_id' => $pRank,
                        '@value'      => $v."",
                        'type'        => 'literal',
                    ]],
                    'dcterms:description' => [[
                        'property_id' => $pDesc,
                        '@value'      => $k,
                        'type'        => 'literal',
                    ]],
                ],
            ];
        }

        $this->api->create('items', $data, [], ['continueOnError' => true]);
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
