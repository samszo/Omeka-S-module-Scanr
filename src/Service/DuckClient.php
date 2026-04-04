<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
use Omeka\Stdlib\Message;
use Saturio\DuckDB\DuckDB;
use Saturio\DuckDB\DB\Configuration;
use Saturio\DuckDB\Result\DataChunk;

/**
 * Client pour les requêtes sur les dumps de scanR via DuckDB.
 * documentation : https://duckdb-php.readthedocs.io/en/latest/
 */
class DuckClient extends MainClient
{
    /** @var string Chemin vers le fichier DuckDB / JSONL */
    protected $duckPath;

    /** @var DuckDB */
    protected $client;

    /** @var string Nom de la table/vue DuckDB */
    protected $tableName = 'persons_denormalized';

    public function __construct(Settings $settings, $api, $logger, $connection, $entityManager)
    {
        $this->initFromSettings($settings);

        $this->apiOmk        = $api;
        $this->logger        = $logger;
        $this->connection    = $connection;
        $this->entityManager = $entityManager;
        $this->duckPath      = $settings->get('scanr_json_path');

        if (!file_exists($this->duckPath)) {
            throw new \Exception("Error querying duck Client: Veuillez vérifier l'adresse du fichier .jsonl");
        }

        $this->testConnection();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface publique
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Rechercher des personnes dans le fichier DuckDB.
     */
    public function searchPersons(string $query, int $page = 0, int $size = 3): array
    {
        $columns = ['id', 'coContributors', 'publications', 'domains', 'topDomains',
                    'publicationsCount', 'affiliations', 'externalIds', 'firstName', 'lastName', 'fullName'];

        $sql = 'SELECT ' . implode(',', $columns) . "
            FROM {$this->tableName}
            WHERE fullName ILIKE '%$query%'
               OR firstName ILIKE '%$query%'
               OR lastName  ILIKE '%$query%'
            LIMIT $size";

        $this->logger->info('scanr search : ' . $sql);

        try {
            $result = $this->client->query($sql);
            $cols   = iterator_to_array($result->columnNames());
            $rs     = [];

            foreach ($result->rows() as $row) {
                if (isset($row)) {
                    $r = [];
                    foreach ($row as $c => $v) {
                        if (isset($cols[$c])) {
                            $r[$cols[$c]] = $v;
                        }
                    }
                    $rs[] = $r;
                }
            }

            $this->logger->info('scanr search find : {nb}', ['nb' => count($rs), 'referenceId' => 'Scanr DuckClient - searchPersons']);

            if (count($rs) > 0) {
                return $this->formatSearchResults($rs);
            }
            throw new \Exception('duck request failed: ' . $sql);
        } catch (\Exception $e) {
            throw new \Exception('Error querying duck Client: ' . $e->getMessage());
        }
    }

    /**
     * Obtenir les détails d'une personne par son ID.
     * Note: utilise l'API Elasticsearch comme fallback (à migrer vers DuckDB).
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
            throw new \Exception('Error querying duck Client: ' . $e->getMessage());
        }
    }

    /**
     * Tester la connexion au fichier DuckDB.
     */
    public function testConnection(): bool
    {
        set_time_limit(120);
        try {
            $this->client = DuckDB::create($this->duckPath);
            $this->client->query("SELECT * FROM {$this->tableName} LIMIT 1");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Formatage des résultats (format plat DuckDB : pas de _source)
    // ──────────────────────────────────────────────────────────────────────────

    protected function formatSearchResults(array $data): array
    {
        $results = ['total' => count($data), 'hits' => []];

        foreach ($data as $row) {
            // Les lignes DuckDB sont déjà à plat : on délègue directement au parent
            $results['hits'][] = $this->formatPerson($row);
        }

        return $results;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Utilitaire DuckDB
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Récupère la valeur d'une cellule dans un ResultSet DuckDB par position.
     */
    public function get_value(array $position, \Saturio\DuckDB\Result\ResultSet $resultSet): string
    {
        $rowsInPreviousChunks = 0;

        /** @var DataChunk $chunk */
        foreach ($resultSet->chunks() as $chunk) {
            $rowCount    = $chunk->rowCount();
            $columnCount = $chunk->columnCount();

            if ($columnCount < $position['column']) {
                throw new \Exception('Column required is out of range');
            }
            if ($rowCount + $rowsInPreviousChunks < $position['row']) {
                $rowsInPreviousChunks += $rowCount;
                continue;
            }

            $vector       = $chunk->getVector($position['column'], rows: $rowCount);
            $dataGenerator = $vector->getDataGenerator();

            for ($rowIndex = 0; $rowIndex < $rowCount; ++$rowIndex) {
                $realRowIndex = $rowsInPreviousChunks + $rowIndex;
                if ($realRowIndex === $position['row']) {
                    return $dataGenerator->current();
                }
                $dataGenerator->next();
            }
        }

        throw new \Exception('Row required is out of range');
    }
}
