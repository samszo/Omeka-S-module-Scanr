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


    public function __construct(AuthenticationService $auth, ApiClient $apiClient, JsonlClient $jsonlClient, DuckClient $duckClient, SqlClient $sqlClient, SearchForm $searchForm, $api, $dispatcher, $settings = null, $userSettings = null)
    {
        $this->auth         = $auth;
        $this->apiClient    = $apiClient;
        $this->duckClient   = $duckClient;
        $this->jsonlClient  = $jsonlClient;
        $this->sqlClient    = $sqlClient;
        $this->searchForm   = $searchForm;
        $this->api          = $api;
        $this->dispatcher   = $dispatcher;
        $this->settings     = $settings;
        $this->userSettings = $userSettings;

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

        return new JsonModel(['ok' => false, 'message' => 'Action inconnue : ' . $action]);
    }

    public function getExpertiseSource($exp){
        $source = $exp->value('dcterms:source')->valueResource();
        return $source;
    }

    // ── EUR Convergences (agent IA) ───────────────────────────────────────

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

        $body    = json_decode($request->getContent(), true) ?? [];
        $itemIds = array_map('intval', $body['item_ids'] ?? []);

        if (empty($itemIds)) {
            return new JsonModel(['ok' => false, 'message' => 'Aucun chercheur fourni']);
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

                // Mots-clefs (dcterms:subject, skos:hasTopConcept)
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

                // Publications (foaf:publications)
                $publications = [];
                foreach ($item->value('foaf:publications', ['all' => true, 'default' => []]) as $v) {
                    $publications[] = $v->value();
                }

                // Co-auteurs (bibo:contributorList)
                $coAuthors = [];
                foreach ($item->value('bibo:contributorList', ['all' => true, 'default' => []]) as $v) {
                    $vr          = $v->valueResource();
                    $coAuthors[] = $vr ? $vr->displayTitle() : $v->value();
                }

                $researchers[] = [
                    'id'           => $itemId,
                    'name'         => $item->displayTitle(),
                    'keywords'     => array_slice($keywords,     0, 20),
                    'publications' => array_slice($publications, 0, 10),
                    'co_authors'   => array_slice($coAuthors,    0, 10),
                ];
            } catch (\Exception $e) {
                // item introuvable ou inaccessible → ignoré
            }
        }

        if (empty($researchers)) {
            return new JsonModel(['ok' => false, 'message' => 'Aucun chercheur trouvé']);
        }

        $apiKey = $this->settings ? $this->settings->get('scanr_claude_api_key', '') : '';
        if (empty($apiKey)) {
            return new JsonModel(['ok' => false, 'message' => 'Clé API Claude non configurée. Veuillez renseigner scanr_claude_api_key dans les paramètres du module.']);
        }

        $model = $this->settings ? $this->settings->get('scanr_claude_model', 'claude-haiku-4-5-20251001') : 'claude-haiku-4-5-20251001';
        if (empty($model)) {
            $model = 'claude-haiku-4-5-20251001';
        }

        try {
            $evaluations = $this->callClaudeApi($apiKey, $model, $researchers);
            return new JsonModel(['ok' => true, 'evaluations' => $evaluations]);
        } catch (\Exception $e) {
            return new JsonModel(['ok' => false, 'message' => 'Erreur API Claude : ' . $e->getMessage()]);
        }
    }

    private function callClaudeApi(string $apiKey, string $model, array $researchers): array
    {
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

        // ── Template du prompt (schéma sans données chercheurs, stocké dans dctype:Service) ──
        $promptTemplate = "Tu es un expert en pilotage de la science et en évaluation de la recherche académique.\n\n"
            . "Voici les 4 Ecoles Universitaires de Recherche (EUR) :\n{$eursText}\n\n"
            . "Pour chaque chercheur fourni, évalue la convergence de ses recherches avec chacune des 4 EUR. "
            . "Attribue un score de 0 (aucune convergence) à 100 (convergence totale) pour chaque EUR. "
            . "Base-toi sur les mots-clefs (skos:hasTopConcept, dcterms:subject), "
            . "les titres de publications (foaf:publications) et les co-auteurs (bibo:contributorList).\n\n"
            . "Format de réponse (JSON strict, aucun texte autour) :\n"
            . '{"evaluations":[{"id":<id>,"name":"<nom>","scores":{"arts":<0-100>,"transitions":<0-100>,"care":<0-100>,"democratie":<0-100>},"justification":"<1-2 phrases>"}]}';

        // ── Données chercheurs (partie variable du prompt) ────────────────
        $researchersText = '';
        foreach ($researchers as $r) {
            $researchersText .= "\n---\n";
            $researchersText .= "ID: {$r['id']}\n";
            $researchersText .= "Nom: {$r['name']}\n";
            if (!empty($r['keywords']))     $researchersText .= "Mots-clefs: "   . implode(', ', $r['keywords'])     . "\n";
            if (!empty($r['publications'])) $researchersText .= "Publications: " . implode(' | ', $r['publications']) . "\n";
            if (!empty($r['co_authors']))   $researchersText .= "Co-auteurs: "   . implode(', ', $r['co_authors'])   . "\n";
        }

        $prompt = $promptTemplate . "\n\nChercheurs à évaluer :" . $researchersText;

        // ── Paramètres API (stockés dans dctype:Service) ──────────────────
        $apiParams = ['model' => $model, 'max_tokens' => 4096];
        $apiUrl    = 'https://api.anthropic.com/v1/messages';

        $payload = json_encode(array_merge($apiParams, [
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ]), JSON_UNESCAPED_UNICODE);

        // ── Appel API Claude ──────────────────────────────────────────────
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

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            throw new \Exception('cURL : ' . $curlErr);
        }
        if ($httpCode !== 200) {
            $err = json_decode($response, true);
            $msg = $err['error']['message'] ?? $response;
            throw new \Exception("HTTP {$httpCode} : {$msg}");
        }

        $decoded = json_decode($response, true);
        $content = $decoded['content'][0]['text'] ?? '';

        // Extrait le JSON même si Claude entoure d'un bloc Markdown
        if (preg_match('/```(?:json)?\s*([\s\S]+?)\s*```/', $content, $m)) {
            $content = $m[1];
        }

        $result = json_decode($content, true);
        if (!isset($result['evaluations']) || !is_array($result['evaluations'])) {
            throw new \Exception('Réponse Claude invalide : ' . substr($content, 0, 300));
        }

        $evaluations = $result['evaluations'];

        // ── Persistance Omeka (non bloquante) ─────────────────────────────
        try {
            $serviceId = $this->findOrCreateEurService($model, $apiUrl, $promptTemplate, $apiParams);
            $this->createEurEvaluationItem($serviceId, $evaluations, $researchers);
        } catch (\Exception $e) {
            // Ne bloque pas la réponse si la persistance échoue
            error_log('[Scanr] EUR persistance Omeka : ' . $e->getMessage());
        }

        return $evaluations;
    }

    /**
     * Trouve ou crée un item dctype:Service décrivant l'agent IA EUR.
     * L'unicité est garantie par la combinaison modèle + URL du service.
     */
    private function findOrCreateEurService(
        string $model,
        string $apiUrl,
        string $promptTemplate,
        array  $apiParams
    ): int {
        // Recherche un service existant identifié par le modèle utilisé
        $rcService = $this->apiClient->getRc('dctype:Service');
        $pType     = $this->apiClient->getProperty('dcterms:type')->id();

        $existing = $this->api->search('items', [
            'resource_class_id' => [$rcService->id()],
            'property'          => [[
                'joiner'   => 'and',
                'property' => $pType,
                'type'     => 'eq',
                'text'     => $model,
            ]],
            'per_page' => 1,
        ])->getContent();

        if (!empty($existing)) {
            return $existing[0]->id();
        }

        // Crée le service
        $pTitle = $this->apiClient->getProperty('dcterms:title')->id();
        $pDesc  = $this->apiClient->getProperty('dcterms:description')->id();
        $pConf  = $this->apiClient->getProperty('dcterms:conformsTo')->id();
        $pRef   = $this->apiClient->getProperty('dcterms:isReferencedBy')->id();

        $data = [
            'o:resource_class'    => ['o:id' => $rcService->id()],
            'dcterms:title'       => [['@value' => "Agent expert EUR Convergences [{$model}]", 'type' => 'literal',    'property_id' => $pTitle]],
            'dcterms:type'        => [['@value' => $model,                                      'type' => 'literal',    'property_id' => $pType]],
            'dcterms:description' => [['@value' => $promptTemplate,                             'type' => 'literal',    'property_id' => $pDesc]],
            'dcterms:conformsTo'  => [['@value' => json_encode($apiParams, JSON_UNESCAPED_UNICODE), 'type' => 'literal', 'property_id' => $pConf]],
            'dcterms:isReferencedBy' => [['@id' => $apiUrl, 'o:label' => 'Anthropic Messages API', 'type' => 'uri',    'property_id' => $pRef]],
        ];

        $item = $this->api->create('items', $data, [], ['continueOnError' => true])->getContent();
        return $item->id();
    }

    /**
     * Crée un item valo:Expertises_all enregistrant le résultat complet
     * de l'évaluation, avec une value annotation par chercheur (scores + justification).
     */
    private function createEurEvaluationItem(int $serviceId, array $evaluations, array $researchers): void
    {
        $rcId   = $this->apiClient->getRc('valo:Expertises_all')->id();
        $pTitle = $this->apiClient->getProperty('dcterms:title')->id();
        $pSrc   = $this->apiClient->getProperty('dcterms:source')->id();
        $pDesc  = $this->apiClient->getProperty('dcterms:description')->id();
        $pSubj  = $this->apiClient->getProperty('dcterms:subject')->id();
        $pRank  = $this->apiClient->getProperty('curation:rank')->id();

        $title = 'Evaluation EUR convergences — ' . (new \DateTime())->format('d/m/Y H:i');

        $data = [
            'o:resource_class'    => ['o:id' => $rcId],
            'dcterms:title'       => [['@value' => $title, 'type' => 'literal', 'property_id' => $pTitle]],
            'dcterms:source'      => [['value_resource_id' => $serviceId, 'type' => 'resource:item', 'property_id' => $pSrc]],
            'dcterms:description' => [['@value' => json_encode($evaluations, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), 'type' => 'literal', 'property_id' => $pDesc]],
            'dcterms:subject'     => [],
        ];

        foreach ($evaluations as $ev) {
            $researcherId = (int) ($ev['id'] ?? 0);
            if (!$researcherId) {
                continue;
            }

            // Les scores sont stockés en JSON dans curation:rank pour conserver les 4 valeurs
            $scoresJson    = json_encode($ev['scores'] ?? [], JSON_UNESCAPED_UNICODE);
            $justification = $ev['justification'] ?? '';

            $data['dcterms:subject'][] = [
                'value_resource_id' => $researcherId,
                'type'              => 'resource:item',
                'property_id'       => $pSubj,
                '@annotation'       => [
                    'curation:rank'       => [[
                        'property_id' => $pRank,
                        '@value'      => $scoresJson,
                        'type'        => 'literal',
                    ]],
                    'dcterms:description' => [[
                        'property_id' => $pDesc,
                        '@value'      => $justification,
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
