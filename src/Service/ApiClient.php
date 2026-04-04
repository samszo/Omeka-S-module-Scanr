<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
use Elastic\Elasticsearch\ClientBuilder;
use Omeka\Stdlib\Message;

/**
 * Client pour l'API Elasticsearch de scanR.
 * documentation : https://scanr.enseignementsup-recherche.gouv.fr/docs/
 */
class ApiClient extends MainClient
{
    /** @var string  URL de l'API Elasticsearch */
    protected $apiUrl;

    /** @var \Elastic\Elasticsearch\Client */
    protected $client;

    /** @var string */
    protected $user;

    /** @var string */
    protected $pwd;

    public function __construct(Settings $settings, $api, $logger, $connection, $entityManager)
    {
        $this->initFromSettings($settings);

        $this->apiOmk        = $api;
        $this->logger        = $logger;
        $this->connection    = $connection;
        $this->entityManager = $entityManager;

        $this->apiUrl = $settings->get('scanr_url', 'https://scanr-api.enseignementsup-recherche.gouv.fr');
        $this->user   = $settings->get('scanr_username');
        $this->pwd    = $settings->get('scanr_pwd');

        if (!isset($this->user) || !isset($this->pwd)) {
            throw new \Exception("Error querying scanR API: Veuillez saisir le nom de l'utilisateur et le mot de passe dans les paramètres du module");
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface publique
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Rechercher des personnes dans scanR via Elasticsearch.
     * @see https://www.elastic.co/docs/reference/elasticsearch/clients/php/search_operations
     */
    public function searchPersons(string $query, int $page = 0, int $size = 3): array
    {
        $params = [
            'index' => 'scanr-persons',
            'body'  => [
                'from'  => $page,
                'size'  => $size,
                'query' => ['match' => ['fullName' => $query]],
            ],
        ];

        try {
            $response = $this->client->search($params);

            if (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
                return $this->formatSearchResults($response->asArray());
            }
            throw new \Exception('API request failed: ' . $response->getStatusCode() . ' - ' . $response->getReasonPhrase());
        } catch (\Exception $e) {
            throw new \Exception('Error querying scanR API: ' . $e->getMessage());
        }
    }

    /**
     * Obtenir les détails d'une personne par son ID Elasticsearch.
     */
    public function getPersonById(string $personId): array
    {
        $params = [
            'index' => 'scanr-persons',
            'body'  => [
                'query' => [
                    'bool' => ['must' => [['match' => ['id' => $personId]]]],
                ],
            ],
        ];

        $this->logger->info('{person} scanr match.', [
            'person'      => $personId,
            'referenceId' => 'Scanr - getPersonById',
        ]);

        try {
            $response = $this->client->search($params);

            if (isset($response['hits']['hits']) && count($response['hits']['hits']) > 0) {
                return $this->formatPerson($response->asArray()['hits']['hits'][0]);
            }
            throw new \Exception('API scanR person not found : ' . $personId);
        } catch (\Exception $e) {
            throw new \Exception('Error querying scanR API: ' . $e->getMessage());
        }
    }

    /**
     * Tester la connexion à l'API Elasticsearch.
     */
    public function testConnection(): bool
    {
        try {
            $this->client = ClientBuilder::create()
                ->setHosts([$this->apiUrl])
                ->setBasicAuthentication($this->user, $this->pwd)
                ->build();

            $this->client->search(['index' => 'persons']);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Surcharge de formatPerson (format Elasticsearch : _source / _score)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Unwrap le format Elasticsearch avant de déléguer au parent.
     *
     * @param array $hit Un hit Elasticsearch avec les clés _source et _score
     */
    protected function formatPerson(array $hit, float $score = 0): array
    {
        return parent::formatPerson(
            $hit['_source'] ?? [],
            (float) ($hit['_score'] ?? 0)
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Formatage des résultats (format Elasticsearch : hits.total.value)
    // ──────────────────────────────────────────────────────────────────────────

    protected function formatSearchResults(array $data): array
    {
        $results = [
            'total' => $data['hits']['total']['value'] ?? 0,
            'hits'  => [],
        ];

        foreach ($data['hits']['hits'] ?? [] as $hit) {
            $results['hits'][] = $this->formatPerson($hit);
        }

        return $results;
    }
}
