<?php
namespace Scanr\Controller;

use Laminas\Mvc\Controller\AbstractActionController;
use Laminas\View\Model\JsonModel;
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

    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    /**
     * @var \Omeka\Settings\UserSettings
     */
    protected $userSettings;

    public function __construct(ApiClient $apiClient, JsonlClient $jsonlClient, DuckClient $duckClient, SqlClient $sqlClient, SearchForm $searchForm, $api, $dispatcher, $settings = null, $userSettings = null)
    {
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

    // ── Helpers propriétés / template / classe ────────────────────────────

    private function getPropId(string $term): int
    {
        try {
            $p = $this->api->searchOne('properties', ['term' => $term])->getContent();
            return $p ? $p->id() : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getRtId(string $label): int
    {
        try {
            $rt = $this->api->searchOne('resource_templates', ['label' => $label])->getContent();
            return $rt ? $rt->id() : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    private function getRcId(string $term): int
    {
        try {
            $rc = $this->api->searchOne('resource_classes', ['term' => $term])->getContent();
            return $rc ? $rc->id() : 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    // ── AJAX CRUD expertises ──────────────────────────────────────────────

    public function expertiseAjaxAction()
    {
        $request = $this->getRequest();
        $action  = $this->params()->fromQuery('action')
                ?: $this->params()->fromPost('action', '');

        // ── LOAD ──────────────────────────────────────────────────────────
        if ($action === 'load') {
            $itemId    = (int) $this->params()->fromQuery('item_id', 0);
            $creatorId = (int) ($this->userSettings ? $this->userSettings->get('scanr_creator_id', 0) : 0);
            $rankProp  = 'curation:rank';
            $conceptProps = $this->settings
                ? (array) $this->settings->get('scanr_properties_hasConcept', ['dcterms:subject'])
                : ['dcterms:subject'];

            try {
                $item = $this->api->read('items', $itemId)->getContent();
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
                            $conceptVals[]  = [
                                'value_resource_id' => $rid,
                                'display_title'     => $vr->displayTitle(),
                                '_sourceProp'       => $prop,
                            ];
                        }
                    }
                }
            }

            // Expertises existantes où cet item est la source
            $propSourceId = $this->getPropId('dcterms:source');
            $existingRaw  = [];
            if ($propSourceId) {
                try {
                    $existingRaw = $this->api->search('scanr_expertises', [
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
                    $creatorItem  = $this->api->read('items', $creatorId)->getContent();
                    $creatorTitle = $creatorItem->displayTitle();
                } catch (\Exception $e) {}
            }

            // Formatage des expertises brutes
            $formatExpertise = function ($exp) use ($rankProp, $creatorId) {
                $rankVals  = $exp->value($rankProp, ['all' => true, 'default' => []]);
                $lastRank  = count($rankVals) ? (int) $rankVals[count($rankVals) - 1]->value() : 0;
                $creatorV  = $exp->value('dcterms:creator');
                $cId       = $creatorV && $creatorV->valueResource() ? $creatorV->valueResource()->id() : 0;
                $cTitle    = $creatorV && $creatorV->valueResource() ? $creatorV->valueResource()->displayTitle() : 'ScanR';
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
                $rank     = array_sum(array_column($expList, 'rank'));
                $hasExpert = (bool) array_filter($expList, fn($e) => $e['creatorId'] == $creatorId);
                $myRank    = 0;
                if ($hasExpert) {
                    foreach ($expList as $e) {
                        if ($e['creatorId'] == $creatorId) { $myRank = $e['rank']; break; }
                    }
                }
                // Ajoute un slot placeholder si le créateur n'a pas encore d'expertise
                if (!$hasExpert) {
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
                'rankProp'     => $rankProp,
                'keywords'     => $keywords,
            ]);
        }

        // ── CREATE ────────────────────────────────────────────────────────
        if ($action === 'create') {
            $body      = json_decode($request->getContent(), true) ?? [];
            $sourceId  = (int) ($body['sourceId']  ?? 0);
            $expId     = (int) ($body['expertiseId'] ?? 0);
            $creatorId = (int) ($body['creatorId']  ?? 0);
            $rank      = (int) ($body['rank']        ?? 0);

            $rtId  = $this->getRtId('Expertise');
            $rcId  = $this->getRcId('valo:Expertises_all');
            $pIds  = [
                'dcterms:title'    => $this->getPropId('dcterms:title'),
                'curation:rank'    => $this->getPropId('curation:rank'),
                'dcterms:creator'  => $this->getPropId('dcterms:creator'),
                'valo:expertise'   => $this->getPropId('valo:expertise'),
                'dcterms:source'   => $this->getPropId('dcterms:source'),
            ];

            // Noms pour le titre
            $sourceName  = '';
            $creatorName = '';
            $expName     = '';
            try { $sourceName  = $this->api->read('items', $sourceId)->getContent()->displayTitle(); } catch (\Exception $e) {}
            try { $creatorName = $this->api->read('items', $creatorId)->getContent()->displayTitle(); } catch (\Exception $e) {}
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
                'dcterms:creator'=> [['value_resource_id' => $creatorId, 'type' => 'resource:item', 'property_id' => $pIds['dcterms:creator']]],
                'valo:expertise' => [['value_resource_id' => $expId,     'type' => 'resource:item', 'property_id' => $pIds['valo:expertise']]],
                'dcterms:source' => [['value_resource_id' => $sourceId,  'type' => 'resource:item', 'property_id' => $pIds['dcterms:source']]],
            ];
            $data = array_filter($data);

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

            $rankPropId = $this->getPropId('curation:rank');
            $titlePropId = $this->getPropId('dcterms:title');

            try {
                $existing = $this->api->read('items', $id)->getContent();
                $oldTitle = (string) $existing->value('dcterms:title');
                // Met à jour le rang et le titre (suffix remplacé)
                $newTitle = preg_replace('/= -?\d+/', '= ' . $rank, $oldTitle) ?: $oldTitle;

                $data = [
                    'dcterms:title' => [['@value' => $newTitle, 'type' => 'literal', 'property_id' => $titlePropId]],
                    'curation:rank' => [['@value' => (string) $rank, 'type' => 'literal', 'property_id' => $rankPropId]],
                ];
                $this->api->update('items', $id, $data, [], ['isPartial' => true, 'collectionAction' => 'replace']);
                return new JsonModel(['ok' => true]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        // ── DELETE ────────────────────────────────────────────────────────
        if ($action === 'delete') {
            $body = json_decode($request->getContent(), true) ?? [];
            $id   = (int) ($body['id'] ?? 0);
            try {
                $this->api->delete('items', $id);
                return new JsonModel(['ok' => true]);
            } catch (\Exception $e) {
                return new JsonModel(['ok' => false, 'message' => $e->getMessage()]);
            }
        }

        return new JsonModel(['ok' => false, 'message' => 'Action inconnue : ' . $action]);
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
