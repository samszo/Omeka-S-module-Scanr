<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
use Omeka\Api\Representation\PropertyRepresentation;
use Elastic\Elasticsearch\ClientBuilder;

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

    public function __construct(Settings $settings, $api)
    {

        $this->settings = $settings;
        $this->apiOmk = $api;
        $this->apiUrl = $settings->get('scanr_url', 'https://scanr-api.enseignementsup-recherche.gouv.fr');
        $user = $settings->get('scanr_username');
        $pwd = $settings->get('scanr_pwd');
        if(!isset($user) || !isset($pwd)) throw new \Exception("Error querying scanR API: Veuillez saisir le nom de l'utilisateur et les mot de passe");

        $this->client = ClientBuilder::create()
            ->setHosts([$this->apiUrl])
            ->setBasicAuthentication($user, $pwd)
            ->build();
    }

    /**
     * Rechercher des personnes dans scanR
     * cf/ https://www.elastic.co/docs/reference/elasticsearch/clients/php/search_operations
     * @param string $query Requête de recherche
     * @param int $page Page de résultats (commence à 0)
     * @param int $size Nombre de résultats par page
     * @return array Résultats de la recherche
     */
    public function searchPersons($query, $page = 0, $size = 20)
    {
        $params = [
            'index' => 'scanr-persons',
            'body'  => [
                'from'=> 0,
                'size'=> 3,                
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
            'domains' => $source['top_domains'] ?? [],
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
            $this->httpClient->setUri($this->apiUrl);
            $this->httpClient->setMethod(Request::METHOD_GET);
            $response = $this->httpClient->send();
            return $response->isSuccess();
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


}
