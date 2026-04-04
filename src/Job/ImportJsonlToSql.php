<?php declare(strict_types=1);

namespace Scanr\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Job d'import du fichier JSONL scanR dans la table MySQL `scanr_person`.
 *
 * Paramètres (setArg / getArg) :
 *   - truncate (bool, défaut true)  : vide la table avant l'import
 *   - file_path (string, optionnel) : surcharge le chemin configuré (scanr_json_path)
 *
 * Déclenchement depuis le contrôleur :
 *   $dispatcher->dispatch(\Scanr\Job\ImportJsonlToSql::class, ['truncate' => true]);
 */
class ImportJsonlToSql extends AbstractJob
{
    /**
     * Nombre de lignes insérées par requête INSERT … VALUES.
     * À ajuster selon la RAM disponible (taille moyenne d'une ligne JSON * BATCH_SIZE).
     */
    const BATCH_SIZE = 500;

    public function perform(): void
    {
        $services   = $this->getServiceLocator();
        $logger     = $services->get('Omeka\Logger');
        $settings   = $services->get('Omeka\Settings');
        /** @var \Doctrine\DBAL\Connection $connection */
        $connection = $services->get('Omeka\Connection');

        // ── Chemin du fichier ──────────────────────────────────────────────
        $filePath = $this->getArg('file_path') ?: $settings->get('scanr_json_path');

        if (!file_exists($filePath)) {
            $logger->err(new Message('ImportJsonlToSql : fichier introuvable "%s"', $filePath));
            return;
        }

        // ── Truncate optionnel ─────────────────────────────────────────────
        if ($this->getArg('truncate', true)) {
            $connection->executeStatement('TRUNCATE TABLE scanr_person');
            $logger->info('ImportJsonlToSql : table scanr_person vidée.');
        }

        // ── Ouverture du fichier (gz ou brut) ──────────────────────────────
        $isGzip = substr($filePath, -3) === '.gz';

        if ($isGzip) {
            $handle = gzopen($filePath, 'r');
        } else {
            $handle = fopen($filePath, 'r');
        }

        if (!$handle) {
            $logger->err(new Message('ImportJsonlToSql : impossible d\'ouvrir "%s"', $filePath));
            return;
        }

        $readLine = $isGzip
            ? static function () use ($handle) { return gzgets($handle); }
            : static function () use ($handle) { return fgets($handle); };
        $close    = $isGzip
            ? static function () use ($handle) { gzclose($handle); }
            : static function () use ($handle) { fclose($handle); };

        // ── Parcours ligne par ligne ───────────────────────────────────────
        $batch  = [];
        $total  = 0;
        $errors = 0;

        $logger->info(new Message('ImportJsonlToSql : début de l\'import depuis "%s".', $filePath));

        try {
            while (($line = $readLine()) !== false) {

                if ($this->shouldStop()) {
                    $logger->warn('ImportJsonlToSql : job arrêté manuellement.');
                    break;
                }

                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $record = json_decode($line, true);
                if ($record === null || empty($record['id'])) {
                    $errors++;
                    continue;
                }

                $batch[] = [
                    'id'        => (string) $record['id'],
                    'fullName'  => isset($record['fullName'])  ? mb_substr((string) $record['fullName'],  0, 512) : null,
                    'data'      => $line,   // JSON brut original (compact)
                ];

                if (count($batch) >= self::BATCH_SIZE) {
                    $this->insertBatch($connection, $batch);
                    $total += count($batch);
                    $batch  = [];

                    if ($total % 10000 === 0) {
                        $logger->info(new Message('ImportJsonlToSql : %d enregistrements importés…', $total));
                    }
                }
            }

            // Flush du dernier lot partiel
            if (!empty($batch)) {
                $this->insertBatch($connection, $batch);
                $total += count($batch);
            }

        } finally {
            $close();
        }

        $logger->info(new Message(
            'ImportJsonlToSql : terminé – %1$d enregistrements importés, %2$d lignes ignorées.',
            $total,
            $errors
        ));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * INSERT … ON DUPLICATE KEY UPDATE pour un lot de lignes.
     */
    private function insertBatch(\Doctrine\DBAL\Connection $connection, array $batch): void
    {
        if (empty($batch)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($batch), '(?,?,?,?,?)'));

        $sql = "INSERT INTO scanr_person (id, fullName, data)
                VALUES $placeholders
                ON DUPLICATE KEY UPDATE
                    fullName    = VALUES(fullName),
                    data        = VALUES(data),
                    imported_at = CURRENT_TIMESTAMP";

        $params = [];
        foreach ($batch as $row) {
            $params[] = $row['id'];
            $params[] = $row['fullName'];
            $params[] = $row['data'];
        }

        $connection->executeStatement($sql, $params);
    }
}
