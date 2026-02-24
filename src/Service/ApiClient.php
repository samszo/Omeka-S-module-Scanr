<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
use Omeka\Api\Representation\PropertyRepresentation;
use Elastic\Elasticsearch\ClientBuilder;
use Omeka\Stdlib\Message;

/**
 * Client pour l'API Elasticsearch de scanR
 * documentation : https://scanr.enseignementsup-recherche.gouv.fr/docs/
 */
class ApiClient
{
    /**
     * @var string
     */
    protected $apiUrl;

    /**
     * @var client
     */
    protected $client;

    /**
     * @var Settings
     */
    protected $settings;

    /**
     * @var $apiOmk
     */
    protected $apiOmk;

    /**
     * @var array
     */
    protected $properties = [];

    /**
     * @var array
     */
    protected $rcs = [];

    /**
     * @var $user
     */
    protected $user;
    /**
     * @var $pwd
     */
    protected $pwd;
    /**
     * @var $logger
     */
    protected $logger;


    public function __construct(Settings $settings, $api, $logger)
    {

        $this->settings = $settings;
        $this->apiOmk = $api;
        $this->logger = $logger;
        $this->apiUrl = $settings->get('scanr_url', 'https://scanr-api.enseignementsup-recherche.gouv.fr');
        $this->user = $settings->get('scanr_username');
        $this->pwd = $settings->get('scanr_pwd');
        if(!isset($this->user) || !isset($this->pwd)) throw new \Exception("Error querying scanR API: Veuillez saisir le nom de l'utilisateur et les mot de passe dans les paramètres du module");
        $this->testConnection();
    }

    /**
     * Rechercher des personnes dans scanR
     * cf/ https://www.elastic.co/docs/reference/elasticsearch/clients/php/search_operations
     * @param string $query Requête de recherche
     * @param int $page Page de résultats (commence à 0)
     * @param int $size Nombre de résultats par page
     * @return array Résultats de la recherche
     */
    public function searchPersons($query, $page = 0, $size = 3)
    {
        $params = [
            'index' => 'scanr-persons',
            'body'  => [
                'from'=> $page,
                'size'=> $size,                
                'query' => [
                    'match' => [
                        'fullName' => $query
                    ]
                ]
            ]
        ];
        try {
            $response = $this->client->search($params);

            if (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
                $data = $response->asArray();
                return $this->formatSearchResults($data);
            } else {
                throw new \Exception('API request failed: ' . $response->getStatusCode() . ' - ' . $response->getReasonPhrase());
            }
        } catch (\Exception $e) {
            throw new \Exception('Error querying scanR API: ' . $e->getMessage());
        }
    }

    /**
     * Obtenir les détails d'une personne par son ID
     *
     * @param string $personId ID de la personne dans scanR
     * @return array|null Données de la personne ou null si non trouvée
     */
    public function getPersonById($personId)
    {
        $params = [
            'index' => 'scanr-persons',
            'body'  => [
                'query' => [
                    'bool' => [
                        'must' => [
                            [ 'match' => [ 'id' => $personId ] ]
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = $this->client->search($params);        
        
            if (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
                $data = $response->asArray();
                return $this->formatPerson($data['hits']['hits'][0]);
            } else {
                throw new \Exception('API scanR person not found : ' .$personId);
            }
        } catch (\Exception $e) {
            throw new \Exception('Error querying scanR API: ' . $e->getMessage());
        }
    }

    /**
     * Formater les résultats de recherche
     *
     * @param array $data Données brutes de l'API
     * @return array Résultats formatés
     */
    protected function formatSearchResults($data)
    {
        $results = [
            'total' => $data['hits']['total']['value'] ?? 0,
            'hits' => [],
        ];

        if (isset($data['hits']['hits'])) {
            foreach ($data['hits']['hits'] as $hit) {
                $results['hits'][] = $this->formatPerson($hit);
            }
        }

        return $results;
    }

    /**
     * Formater le résultat pour une personne de recherche
     *
     * @param array $hit Données brutes de l'API
     * @return array Résultats formatés
     */
    protected function formatPerson($hit)
    {
        $source = $hit['_source'] ?? [];
        $results = [
            'id' => $source['id'] ?? '',
            'score' => $hit['_score'] ?? 0,
            'items' => $this->referenceSearchResults($source,'labo:EnseigantChercheur'),
            'firstName' => $source['firstName'] ?? '',
            'lastName' => $source['lastName'] ?? '',
            'fullName' => $source['fullName'] ?? '',
            'domains' => $source['domains'] ?? [],
            'coContributors' => $source['coContributors'] ?? [],
            'externalIds' => $source['externalIds'] ?? [],
            'top_domains' => $source['top_domains'] ?? [],
            'affiliations' => $source['affiliations'] ?? [],
            'awards' => $source['awards'] ?? [],
            'publications' => $source['publications'] ?? [],
        ];

        return $results;
    }


    /**
     * Vérifier la présence de la référence
     *
     * @param array $data Données brutes de l'API
     * @param string $class class de la ressource
     * @return array Résultats formatés
     */
    protected function referenceSearchResults($data, $class)
    {
        $param = [];
        switch ($class) {
            case 'labo:EnseigantChercheur':
                $param['property'][0]['property'] = (string) $this->getProperty('foaf:familyName')->id();
                $param['property'][0]['type'] = 'eq';
                $param['property'][0]['text'] = $data['lastName'] ?? '';
                $param['property'][1]['property'] = (string) $this->getProperty('foaf:firstName')->id();
                $param['property'][1]['type'] = 'eq';
                $param['property'][1]['text'] = $data['firstName'] ?? '';
                break;            
        }
        $items = $this->apiOmk->search('items', $param)->getContent();
        
        return $items;
    }

    /**
     * Tester la connexion à l'API
     *
     * @return bool True si la connexion fonctionne
     */
    public function testConnection()
    {
        try {
            $this->client = ClientBuilder::create()
                ->setHosts([$this->apiUrl])
                ->setBasicAuthentication($this->user, $this->pwd)
                ->build();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getProperty($term): PropertyRepresentation
    {
        if (!isset($this->properties[$term])) {
            $this->properties[$term] = $this->apiOmk->search('properties', ['term' => $term])->getContent()[0];
        }
        return $this->properties[$term];
    }

    public function getRc($t)
    {
        if (!isset($this->rcs[$t])) {
            $this->rcs[$t] = $this->apiOmk->search('resource_classes', ['term' => $t])->getContent()[0];
        }
        return $this->rcs[$t];
    }


    /**
     * Mapper les données d'une personne scanR vers un item Omeka S
     *
     * @param array $personData Données de la personne depuis scanR
     * @param bool $addCoContrib ajoute au nom les coCon,tributeurs
     * @return array Données formatées pour Omeka S
     */
    public function mapPersonToItem($personData, $addCoContrib=true)
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
                    'property_id' => $this->getProperty('dcterms:title')->id()."", 
                    '@value' => $personData['fullName'],
                ];
            }
        }

        // Prénom
        if (!empty($personData['firstName'])) {
            if(!isset($itemData['foaf:firstName'])){
                $itemData['foaf:firstName'][] = [
                    'type' => 'literal',
                    'property_id' => $this->getProperty('foaf:firstName')->id()."", 
                    '@value' => $personData['firstName'],
                ];
            }
        }

        // Nom
        if (!empty($personData['lastName'])) {
            if(!isset($itemData['foaf:lastName'])){
                $itemData['foaf:lastName'][] = [
                    'type' => 'literal',
                    'property_id' => $this->getProperty('foaf:lastName')->id()."", 
                    '@value' => $personData['lastName'],
                ];
            }
        }

        // ID scanR comme identifiant
        if (!empty($personData['id'])) {
            if(!isset($itemData['dcterms:identifier'])){
                $itemData['dcterms:identifier'][] = [
                    'type' => 'literal',
                    'property_id' => $this->getProperty('dcterms:identifier')->id()."", 
                    '@value' => 'scanr:' . $personData['id'],
                ];
            }
        }

        if (!empty($personData['externalIds'])) {
            $itemData['dcterms:isReferencedBy']=[];
            foreach ($personData['externalIds'] as $id) {
                $itemData['dcterms:isReferencedBy'][] = [
                    'property_id' => $this->getProperty('dcterms:isReferencedBy')->id()."",
                    '@id' => $id['url'],
                    "o:label"=>$id['type'].':'.$id['id'],
                    "type"=>'uri'
                ];
            }
        }

        // ajoute les coContributeurs
        if ($addCoContrib && !empty($personData['coContributors'])) {
            $itemData['bibo:contributorList']=[];
            foreach ($personData['coContributors'] as $co) {
                try {
                    $scanrCo = $this->getPersonById($co['person']);
                    if(count($scanrCo['items'])==0){
                        $itemDataCo = $this->mapPersonToItem($scanrCo, false);            
                        $itemCo = $this->apiOmk->create('items', $itemDataCo)->getContent();
                    }else{
                        $itemCo = $scanrCo['items'][0];
                    }
                    $itemData['bibo:contributorList'][] = [
                        'property_id' => $this->getProperty('bibo:contributorList')->id()."",
                        'value_resource_id' => $itemCo->id(),
                        'type' => 'resource'
                    ];
                } catch (\Exception $e) {
                    $this->logger->warn(new Message(
                        $e->getMessage()." : ".$co['fullname']." ".$co['person'])
                    );

                    //throw new \Exception('Error querying scanR API: ' . $e->getMessage());
                }
            }
        }

        
        // Description avec les domaines
        if ($addCoContrib && !empty($personData['domains'])) {
            $itemData['dcterms:subject']=[];
            foreach ($personData['domains'] as $domain) {
                if (isset($domain['label'])) {
                    $concept = $this->getTag($domain);

                    $annotation = [];
                    $annotation['curation:rank'][] = [
                        'property_id' => $this->getProperty('curation:rank')->id()."",
                        '@value' => $domain["count"]."",
                        'type' => 'literal',
                    ];

                    $itemData['dcterms:subject'][] = [
                        'property_id' => $this->getProperty('dcterms:subject')->id()."",
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
                        'property_id' => $this->getProperty('curation:rank')->id()."",
                        '@value' => $affiliation["publicationsCount"],
                        'type' => 'literal',
                    ];
                    $annotation['curation:start'][] = [
                        'property_id' => $this->getProperty('curation:start')->id()."",
                        '@value' => $affiliation["startDate"],
                        'type' => 'literal',
                    ];
                    $annotation['curation:end'][] = [
                        'property_id' => $this->getProperty('curation:end')->id()."",
                        '@value' => $affiliation["endDate"],
                        'type' => 'literal',
                    ];
                    $itemData['labo:hasOrga'][] = [
                        'property_id' => $this->getProperty('labo:hasOrga')->id()."",
                        'value_resource_id' => $orga->id(),
                        'type' => 'resource',
                        '@annotation' => $annotation,
                    ];
                }
            }
        }


        // Publications
        if ($addCoContrib && !empty($personData['publications'])) {
            $itemData['foaf:publications']=[];
            foreach ($personData['publications'] as $publi) {
                if (isset($publi['title'])) {
                    $annotation = [];
                    $annotation['dcterms:date'][] = [
                        'property_id' => $this->getProperty('dcterms:date')->id()."",
                        '@value' => $publi["year"] ?? "",
                        'type' => 'literal',//mettre un type date
                    ];
                    $annotation['dcterms:isReferencedBy'][] = [
                        'property_id' => $this->getProperty('dcterms:isReferencedBy')->id()."",
                        '@value' => $publi["publication"],
                        'type' => 'literal',//mettre un type date
                    ];
                    $annotation['foaf:status'][] = [
                        'property_id' => $this->getProperty('foaf:status')->id()."",
                        '@value' => $publi["role"],
                        'type' => 'literal',//mettre un type date
                    ];
                    $itemData['foaf:publications'][] = [
                        'type' => 'literal',
                        'property_id' => $this->getProperty('foaf:publications')->id()."",
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
        $param['property'][0]['property'] = $this->getProperty("skos:prefLabel")->id() . "";
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $tag["label"]["default"];
        $result = $this->apiOmk->search('items', $param)->getContent();
        if (count($result)) {
            return $result[0];
        } else {
            $oItem = [];
            $class = $this->getRc('skos:Concept');
            $oItem['o:resource_class'] = ['o:id' => $class->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty("dcterms:title")->id();
            $valueObject['@value'] = $tag["label"]["default"];
            $valueObject['type'] = 'literal';
            $oItem["dcterms:title"][] = $valueObject;
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty("skos:prefLabel")->id();
            $valueObject['@value'] = $tag["label"]["default"];
            $valueObject['type'] = 'literal';
            $oItem["skos:prefLabel"][] = $valueObject;
            if($tag["type"]=="wikidata"){
                $valueObject = [];
                $valueObject['property_id'] = $this->getProperty("dcterms:isReferencedBy")->id();
                $valueObject['@id'] = "https://www.wikidata.org/wiki/".$tag["code"];
                $valueObject['o:label'] = 'wikidata';
                $valueObject['type'] = 'uri';
                $oItem["dcterms:isReferencedBy"][] = $valueObject;
            }            
            // Création du tag
            $cpt = $this->apiOmk->create('items', $oItem, [], ['continueOnError' => true])->getContent();
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
        $param['property'][0]['property'] = $this->getProperty("dcterms:isReferencedBy")->id() . "";
        $param['property'][0]['type'] = 'eq';
        $param['property'][0]['text'] = $orga["structure"]["id_name"];
        $result = $this->apiOmk->search('items', $param)->getContent();
        if (count($result)) {
            return $result[0];
        } else {
            $oItem = [];
            $class = $this->getRc('labo:Organisme');
            $oItem['o:resource_class'] = ['o:id' => $class->id()];
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty("dcterms:title")->id();
            $valueObject['@value'] = $orga["structure"]["label"]["default"];
            $valueObject['type'] = 'literal';
            $oItem["dcterms:title"][] = $valueObject;
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty("dcterms:type")->id();
            $valueObject['@value'] = $this->getTypeFromOrga($orga);
            $valueObject['type'] = 'literal';
            $oItem["dcterms:type"][] = $valueObject;            
            $valueObject = [];
            $valueObject['property_id'] = $this->getProperty("dcterms:isReferencedBy")->id();
            $valueObject['@value'] = $orga["structure"]["id_name"];
            $valueObject['type'] = 'literal';
            $oItem["dcterms:isReferencedBy"][] = $valueObject;
            // Création du tag
            $cpt = $this->apiOmk->create('items', $oItem, [], ['continueOnError' => true])->getContent();
            return $cpt;
        }
    }

    function getTypeFromOrga($orga){
        if(substr($orga["structure"]["id"],0,2)=="ED")return "Ecole doctorale";
        else return $orga["structure"]["king"][0];
    }


}
