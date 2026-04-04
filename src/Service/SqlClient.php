<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;

/**
 * Client de recherche sur la table MySQL `scanr_person`
 * (alimentée par le job ImportJsonlToSql).
 *
 * Même interface publique que ApiClient / DuckClient / JsonlClient :
 *   - testConnection() : bool
 *   - searchPersons($query, $page, $size) : array
 *
 * Note : ce client ne dispose pas d'apiOmk ; referenceSearchResults()
 * retourne donc [] (guard dans MainClient). Pour enrichir les résultats
 * avec les items Omeka, utiliser ApiClient ou DuckClient.
 */
class SqlClient extends MainClient
{
    public function __construct(Settings $settings, $connection, $logger)
    {
        $this->initFromSettings($settings);

        $this->connection = $connection;
        $this->logger     = $logger;
        // apiOmk intentionnellement absent → referenceSearchResults() renvoie []

        $this->testConnection();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface publique
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Vérifie que la table scanr_person est accessible et contient des données.
     */
    public function testConnection(): bool
    {
        try {
            $n = $this->connection->fetchOne('SELECT COUNT(*) FROM scanr_person');
            return (int) $n > 0;
        } catch (\Exception $e) {
            return false;
        }
    }


    /**
     * Obtenir les détails d'une personne par son ID Elasticsearch.
     */
    public function getPersonById(string $personId): array
    {

        try {
            $rows = $this->connection->fetchAllAssociative(
                        "SELECT id, firstName, lastName, fullName, data
                        FROM   scanr_person
                        WHERE  id = ?",
                        [$personId]
                    );
            $total = count($rows);
            if ($total > 0) {
                return $this->buildResult($total, $rows);
            }
            throw new \Exception('SQL scanR person not found : ' . $personId);
        } catch (\Exception $e) {
            throw new \Exception('Error querying scanR SQL: ' . $e->getMessage());
        }
    }


    /**
     * Recherche des personnes dans la table SQL.
     *
     * Utilise FULLTEXT (BOOLEAN MODE) si la requête fait ≥ 3 caractères,
     * sinon repli sur LIKE (insensible à la casse grâce à utf8mb4_unicode_ci).
     */
    public function searchPersons(string $query, int $page = 0, int $size = 3): array
    {
        $query  = trim($query);
        $offset = $page * $size;

        return mb_strlen($query) >= 20
            ? $this->searchFulltext($query, $size, $offset)
            : $this->searchLike($query, $size, $offset);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Stratégies de recherche
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Recherche FULLTEXT BOOLEAN MODE — très rapide sur de grandes tables.
     */
    protected function searchFulltext(string $query, int $size, int $offset): array
    {
        $booleanQuery = $this->toBooleanQuery($query);
        $matchExpr    = 'MATCH(firstName, lastName, fullName) AGAINST (? IN BOOLEAN MODE)';

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM scanr_person WHERE $matchExpr",
            [$booleanQuery]
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, firstName, lastName, fullName, data,
                    $matchExpr AS score
             FROM   scanr_person
             WHERE  $matchExpr
             ORDER  BY score DESC
             LIMIT  ? OFFSET ?",
            [$booleanQuery, $booleanQuery, $booleanQuery, $size, $offset]
        );

        return $this->buildResult($total, $rows);
    }

    /**
     * Recherche LIKE — pour les requêtes très courtes (< 3 caractères).
     */
    protected function searchLike(string $query, int $size, int $offset): array
    {
        $pattern = '%' . $query . '%';
        $where   = 'fullName LIKE ? OR firstName LIKE ? OR lastName LIKE ?';

        $total = (int) $this->connection->fetchOne(
            "SELECT COUNT(*) FROM scanr_person WHERE $where",
            [$pattern, $pattern, $pattern]
        );

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, firstName, lastName, fullName, data
             FROM   scanr_person
             WHERE  $where
             LIMIT  ? OFFSET ?",
            [$pattern, $pattern, $pattern, $size, $offset]
        );

        return $this->buildResult($total, $rows);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Construit le tableau de résultats uniforme à partir des lignes SQL.
     * Décode le champ `data` (JSON brut) et délègue à parent::formatPerson().
     */
    protected function buildResult(int $total, array $rows): array
    {
        $hits = [];
        foreach ($rows as $row) {
            $source = json_decode($row['data'], true) ?? [];
            $hits[] = $this->formatPerson($source, (float) ($row['score'] ?? 0));
        }

        $this->logger->info('SqlClient: {total} résultat(s)', ['total' => $total]);

        return ['total' => $total, 'hits' => $hits];
    }

    /**
     * Transforme une requête libre en syntaxe FULLTEXT BOOLEAN MODE.
     * Ex: "jean dupont" → "+jean* +dupont*"
     */
    protected function toBooleanQuery(string $query): string
    {
        $words = preg_split('/\s+/', trim($query), -1, PREG_SPLIT_NO_EMPTY);
        $parts = [];
        foreach ($words as $word) {
            $word = preg_replace('/[+\-><\(\)~*"@]+/', '', $word);
            if ($word !== '') {
                $parts[] = '+' . $word . '*';
            }
        }
        return implode(' ', $parts);
    }
}
