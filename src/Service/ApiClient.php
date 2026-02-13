<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
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

    public function __construct(Settings $settings)
    {

        $this->settings = $settings;
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
        $endpoint = $this->apiUrl . '/persons/' . urlencode($personId);

        try {
            $this->httpClient->setUri($endpoint);
            $this->httpClient->setMethod(Request::METHOD_GET);
            $this->httpClient->setHeaders([
                'Content-Type' => 'application/json',
            ]);

            $response = $this->httpClient->send();

            if ($response->isSuccess()) {
                $data = json_decode($response->getBody(), true);
                return $data;
            } elseif ($response->getStatusCode() === 404) {
                return null;
            } else {
                throw new \Exception('API request failed: ' . $response->getStatusCode());
            }
        } catch (\Exception $e) {
            throw new \Exception('Error fetching person from scanR API: ' . $e->getMessage());
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
                $source = $hit['_source'] ?? [];
                $results['hits'][] = [
                    'id' => $hit['_id'] ?? $source['id'] ?? '',
                    'score' => $hit['_score'] ?? 0,
                    'firstName' => $source['firstName'] ?? '',
                    'lastName' => $source['lastName'] ?? '',
                    'fullName' => $source['fullName'] ?? '',
                    'domains' => $source['domains'] ?? [],
                    'affiliations' => $source['affiliations'] ?? [],
                    'awards' => $source['awards'] ?? [],
                    'publications' => $source['publications'] ?? [],
                ];
            }
        }

        return $results;
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
}
