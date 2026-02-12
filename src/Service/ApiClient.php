<?php
namespace ScanR\Service;

use Laminas\Http\Client as HttpClient;
use Laminas\Http\Request;
use Omeka\Settings\Settings;

/**
 * Client pour l'API Elasticsearch de scanR
 */
class ApiClient
{
    /**
     * @var string
     */
    protected $apiUrl;

    /**
     * @var HttpClient
     */
    protected $httpClient;

    /**
     * @var Settings
     */
    protected $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
        $this->apiUrl = $settings->get('scanr_api_url', 'https://scanr-api.enseignementsup-recherche.gouv.fr');
        $this->httpClient = new HttpClient();
        $this->httpClient->setOptions([
            'timeout' => 30,
            'adapter' => 'Laminas\Http\Client\Adapter\Curl',
        ]);
    }

    /**
     * Rechercher des personnes dans scanR
     *
     * @param string $query Requête de recherche
     * @param int $page Page de résultats (commence à 0)
     * @param int $size Nombre de résultats par page
     * @return array Résultats de la recherche
     */
    public function searchPersons($query, $page = 0, $size = 20)
    {
        $endpoint = $this->apiUrl . '/persons/_search';
        
        // Construction de la requête Elasticsearch
        $searchQuery = [
            'query' => [
                'bool' => [
                    'should' => [
                        [
                            'multi_match' => [
                                'query' => $query,
                                'fields' => [
                                    'firstName^2',
                                    'lastName^3',
                                    'fullName^4',
                                    'affiliations.structure.label',
                                ],
                                'type' => 'best_fields',
                                'fuzziness' => 'AUTO',
                            ],
                        ],
                    ],
                ],
            ],
            'from' => $page * $size,
            'size' => $size,
            '_source' => [
                'id',
                'firstName',
                'lastName',
                'fullName',
                'domains',
                'affiliations',
                'awards',
                'publications',
            ],
        ];

        try {
            $this->httpClient->setUri($endpoint);
            $this->httpClient->setMethod(Request::METHOD_POST);
            $this->httpClient->setHeaders([
                'Content-Type' => 'application/json',
            ]);
            $this->httpClient->setRawBody(json_encode($searchQuery));

            $response = $this->httpClient->send();

            if ($response->isSuccess()) {
                $data = json_decode($response->getBody(), true);
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
