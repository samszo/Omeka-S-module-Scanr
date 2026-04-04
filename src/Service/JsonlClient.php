<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;

/**
 * Client pour les requêtes sur des fichiers JSONL (pur PHP, sans dépendance externe).
 * Supporte les fichiers .jsonl et .jsonl.gz.
 *
 * Note : ce client ne dispose pas d'apiOmk ; referenceSearchResults()
 * retourne donc [] (guard dans MainClient).
 */
class JsonlClient extends MainClient
{
    /** @var string Chemin vers le fichier JSONL/JSONL.gz */
    protected $filePath;

    /** @var array Données chargées en mémoire (via load()) */
    protected array $data = [];

    /** @var bool Indique si load() a été appelé */
    protected bool $loaded = false;

    public function __construct(Settings $settings, $api, $logger)
    {
        $this->initFromSettings($settings);

        $this->logger   = $logger;
        $this->apiOmk     = $api;
        $this->filePath = $settings->get('scanr_json_path');
        // apiOmk intentionnellement absent → referenceSearchResults() renvoie []
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface publique (MainClient)
    // ──────────────────────────────────────────────────────────────────────────

    public function testConnection(): bool
    {
        return $this->filePath !== null && file_exists($this->filePath);
    }

    /**
     * Recherche de personnes — délègue à searchStream() pour éviter
     * de charger l'intégralité du fichier en mémoire.
     */
    public function searchPersons(string $query, int $page = 0, int $size = 3): array
    {
        try {
            $rs = $this->searchStream($query, ['fullName', 'firstName', 'lastName'], $size);

            if (count($rs['hits']) > 0) {
                return $rs;
            }
            throw new \Exception('Aucun résultat pour : ' . $query);
        } catch (\Exception $e) {
            throw new \Exception('Error querying jsonl Client: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Chargement en mémoire (optionnel, pour recherches répétées)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Charge le fichier JSONL en mémoire pour des recherches répétées rapides.
     * Pour les très grands fichiers, préférer searchStream().
     *
     * @param string|null $filePath Chemin vers le fichier (null = chemin configuré)
     * @return int Nombre d'enregistrements chargés
     */
    public function load(?string $filePath = null): int
    {
        $path = $filePath ?? $this->filePath;

        if (!file_exists($path)) {
            throw new \Exception("Fichier JSONL introuvable : $path");
        }

        $this->data = [];
        [$readLine, $close] = $this->openFile($path);

        try {
            while (($line = $readLine()) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if ($record !== null) {
                    $this->data[] = $record;
                }
            }
        } finally {
            $close();
        }

        $this->loaded = true;
        $count = count($this->data);

        $this->logger->info('JsonlClient: {count} enregistrements chargés depuis {path}', [
            'count' => $count,
            'path'  => $path,
        ]);

        return $count;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Recherche
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Recherche dans les données chargées en mémoire (nécessite load() au préalable).
     *
     * @param string $query   Texte à rechercher (insensible à la casse)
     * @param array  $fields  Champs à chercher (vide = tous les champs scalaires)
     * @param int    $limit   Nombre maximum de résultats
     * @param int    $offset  Décalage pour la pagination
     * @return array ['total' => int, 'hits' => array]
     */
    public function search(string $query, array $fields = [], int $limit = 10, int $offset = 0): array
    {
        if (!$this->loaded) {
            $this->load();
        }

        $queryLower = mb_strtolower($query);
        $matches    = [];

        foreach ($this->data as $record) {
            if ($this->recordMatches($record, $queryLower, $fields)) {
                $matches[] = $record;
            }
        }

        return [
            'total' => count($matches),
            'hits'  => array_slice($matches, $offset, $limit),
        ];
    }

    /**
     * Recherche en streaming : parcourt le fichier ligne par ligne sans tout charger.
     * Recommandé pour les grands fichiers.
     *
     * @param string      $query    Texte à rechercher (insensible à la casse)
     * @param array       $fields   Champs à chercher (vide = tous les champs scalaires)
     * @param int         $limit    Nombre maximum de résultats retournés
     * @param string|null $filePath Chemin vers le fichier (null = chemin configuré)
     * @return array ['total' => int, 'hits' => array]
     */
    public function searchStream(string $query, array $fields = [], int $limit = 10, ?string $filePath = null): array
    {
        $path = $filePath ?? $this->filePath;

        if (!file_exists($path)) {
            throw new \Exception("Fichier JSONL introuvable : $path");
        }

        $queryLower = mb_strtolower($query);
        $hits       = [];
        $total      = 0;

        [$readLine, $close] = $this->openFile($path);

        try {
            while (($line = $readLine()) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $record = json_decode($line, true);
                if ($record !== null && $this->recordMatches($record, $queryLower, $fields)) {
                    $total++;
                    if (count($hits) < $limit) {
                        // Délègue à parent::formatPerson() pour normaliser la structure
                        $hits[] = $this->formatPerson($record);
                    }
                }
            }
        } finally {
            $close();
        }

        $this->logger->info('JsonlClient searchStream: {total} résultats pour "{query}"', [
            'total' => $total,
            'query' => $query,
        ]);

        return ['total' => $total, 'hits' => $hits];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Utilitaires
    // ──────────────────────────────────────────────────────────────────────────

    public function isLoaded(): bool { return $this->loaded; }
    public function count(): int     { return count($this->data); }

    public function clear(): void
    {
        $this->data   = [];
        $this->loaded = false;
    }

    public function setFilePath(string $filePath): void
    {
        $this->filePath = $filePath;
        $this->clear();
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers internes
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Ouvre un fichier (normal ou gzip) et retourne [readLine, close].
     * @return array{callable, callable}
     */
    protected function openFile(string $path): array
    {
        $isGzip = substr($path, -3) === '.gz';

        if ($isGzip) {
            $handle = gzopen($path, 'r');
            if (!$handle) {
                throw new \Exception("Impossible d'ouvrir le fichier gzip : $path");
            }
            return [
                static fn () => gzgets($handle),
                static fn () => gzclose($handle),
            ];
        }

        $handle = fopen($path, 'r');
        if (!$handle) {
            throw new \Exception("Impossible d'ouvrir le fichier : $path");
        }
        return [
            static fn () => fgets($handle),
            static fn () => fclose($handle),
        ];
    }

    protected function recordMatches(array $record, string $queryLower, array $fields): bool
    {
        if (empty($fields)) {
            return $this->searchInValue($record, $queryLower);
        }
        foreach ($fields as $field) {
            $value = $this->getNestedValue($record, $field);
            if ($value !== null && mb_strpos(mb_strtolower((string) $value), $queryLower) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function searchInValue($value, string $queryLower): bool
    {
        if (is_string($value)) {
            return mb_strpos(mb_strtolower($value), $queryLower) !== false;
        }
        if (is_array($value)) {
            foreach ($value as $v) {
                if ($this->searchInValue($v, $queryLower)) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Récupère une valeur imbriquée avec notation pointée (ex: "label.default"). */
    protected function getNestedValue(array $record, string $field)
    {
        $value = $record;
        foreach (explode('.', $field) as $key) {
            if (!is_array($value) || !array_key_exists($key, $value)) {
                return null;
            }
            $value = $value[$key];
        }
        return $value;
    }
}
