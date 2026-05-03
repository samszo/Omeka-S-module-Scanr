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
        return $cas == $user->getEmail() ? true : false;
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
