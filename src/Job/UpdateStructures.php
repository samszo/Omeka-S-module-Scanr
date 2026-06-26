<?php declare(strict_types=1);

namespace Scanr\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

/**
 * Met à jour les items de classe foaf:Organization à partir du fichier
 * fr-esr-structures-recherche-publiques-actives.json du MESR.
 *
 * La correspondance se fait via dcterms:isReferencedBy = numero_national_de_structure.
 *
 * Paramètre optionnel :
 *   - json_path (string) : chemin absolu vers le fichier JSON
 *                          (défaut : module/data/fr-esr-structures-recherche-publiques-actives.json)
 */
class UpdateStructures extends AbstractJob
{
    const BATCH_SIZE = 50;

    /** Mapping json_key → [propriété Omeka, type de valeur] */
    const FIELD_MAP = [
        'sigle'             => ['foaf:surname',           'literal'],
        'annee_de_creation' => ['dcterms:created',        'literal'],
        'site_web'          => ['foaf:workplaceHomepage', 'uri'],
        'adresse'           => ['schema:address',         'literal'],
        'code_postal'       => ['schema:postalCode',      'literal'],
        'commune'           => ['schema:addressLocality', 'literal'],
    ];

    public function perform(): void
    {
        $services      = $this->getServiceLocator();
        $logger        = $services->get('Omeka\Logger');
        $api           = $services->get('Omeka\ApiManager');
        $settings      = $services->get('Omeka\Settings');
        $entityManager = $services->get('Omeka\EntityManager');

        // ── Fichier JSON ──────────────────────────────────────────────────────
        $jsonPath = $this->getArg('json_path')
            ?: dirname(__DIR__, 2) . '/data/fr-esr-structures-recherche-publiques-actives.json';

        if (!file_exists($jsonPath)) {
            $logger->err(new Message('UpdateStructures: fichier introuvable "%s"', $jsonPath));
            return;
        }

        $logger->info(new Message('UpdateStructures: chargement de "%s"…', $jsonPath));

        $raw = file_get_contents($jsonPath);
        if ($raw === false) {
            $logger->err(new Message('UpdateStructures: impossible de lire le fichier JSON'));
            return;
        }

        $jsonData = json_decode($raw, true);
        unset($raw);

        if (!is_array($jsonData)) {
            $logger->err(new Message('UpdateStructures: JSON invalide'));
            return;
        }

        // Index par numero_national_de_structure pour lookup O(1)
        $structureIndex = [];
        foreach ($jsonData as $record) {
            $num = $record['numero_national_de_structure'] ?? null;
            if ($num !== null && $num !== '') {
                $structureIndex[(string) $num] = $record;
            }
        }
        unset($jsonData);

        $logger->info(new Message(
            'UpdateStructures: %d structures indexées.', count($structureIndex)
        ));

        // ── IDs des propriétés ────────────────────────────────────────────────
        $propTerms = array_merge(
            ['dcterms:isReferencedBy'],
            array_column(self::FIELD_MAP, 0)
        );
        $propTerms = array_unique($propTerms);

        $propIds = [];
        foreach ($propTerms as $term) {
            $results = $api->search('properties', ['term' => $term])->getContent();
            if (!empty($results)) {
                $propIds[$term] = $results[0]->id();
            } else {
                $logger->warn(new Message('UpdateStructures: propriété "%s" introuvable — ignorée.', $term));
            }
        }

        if (empty($propIds['dcterms:isReferencedBy'])) {
            $logger->err(new Message('UpdateStructures: la propriété dcterms:isReferencedBy est absente, abandon.'));
            return;
        }

        // ── Classe foaf:Organization ──────────────────────────────────────────
        $classOrg = ($settings->get('scanr_class_structure') ?? ['foaf:Organization'])[0];
        $rcResults = $api->search('resource_classes', ['term' => $classOrg])->getContent();
        $rcId = $rcResults ? $rcResults[0]->id() : null;

        if (!$rcId) {
            $logger->err(new Message('UpdateStructures: classe "%s" introuvable.', $classOrg));
            return;
        }

        // ── Parcours des items par pages ──────────────────────────────────────
        $page      = 1;
        $processed = 0;
        $updated   = 0;
        $skipped   = 0;

        $logger->info(new Message(
            'UpdateStructures: recherche des items de classe %s avec dcterms:isReferencedBy…', $classOrg
        ));

        while (true) {
            if ($this->shouldStop()) {
                $logger->warn(new Message('UpdateStructures: job arrêté à la page %d.', $page));
                break;
            }

            $items = $api->search('items', [
                'resource_class_id' => $rcId,
                'property'          => [[
                    'joiner'   => 'and',
                    'property' => $propIds['dcterms:isReferencedBy'],
                    'type'     => 'ex',   // has any value
                ]],
                'per_page' => self::BATCH_SIZE,
                'page'     => $page,
            ])->getContent();

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $processed++;

                if ($this->shouldStop()) {
                    $logger->warn(new Message('UpdateStructures: job arrêté (item #%d).', $item->id()));
                    break 2;
                }

                // Récupère le numéro national de structure de l'item
                $refValue = $item->value('dcterms:isReferencedBy');
                if (!$refValue) {
                    $skipped++;
                    continue;
                }
                $numStr = trim((string) $refValue);

                if (!isset($structureIndex[$numStr])) {
                    $skipped++;
                    $logger->info(new Message(
                        'UpdateStructures: item #%d — numéro "%s" absent du JSON.',
                        $item->id(), $numStr
                    ));
                    continue;
                }

                $record     = $structureIndex[$numStr];
                $newValues  = $this->buildUpdateData($record, $propIds);

                if (empty($newValues)) {
                    $skipped++;
                    continue;
                }

                // Fusionne avec les valeurs existantes pour ne rien supprimer
                $updateData = $this->mergeWithExisting($item, $newValues, $propIds);

                try {
                    $api->update('items', $item->id(), $updateData, [], [
                        'isPartial'        => true,
                        'collectionAction' => 'replace',
                    ]);
                    $updated++;
                    $logger->info(new Message(
                        'UpdateStructures: item #%d (%s "%s") mis à jour.',
                        $item->id(), $numStr, $record['libelle'] ?? ''
                    ));
                } catch (\Exception $e) {
                    $skipped++;
                    $logger->err(new Message(
                        'UpdateStructures: erreur item #%d — %s', $item->id(), $e->getMessage()
                    ));
                }

                unset($item);
            }

            unset($items);
            $entityManager->clear();

            $page++;
        }

        $logger->info(new Message(
            'UpdateStructures: terminé. %d traité(s), %d mis à jour, %d ignoré(s).',
            $processed, $updated, $skipped
        ));
    }

    /**
     * Fusionne les nouvelles valeurs avec celles déjà présentes sur l'item.
     *
     * Pour chaque propriété : conserve toutes les valeurs existantes et ajoute
     * les nouvelles uniquement si elles ne sont pas déjà présentes (comparaison
     * sur @value pour les literals, sur @id pour les URIs).
     *
     * @param \Omeka\Api\Representation\ItemRepresentation $item
     * @param array $newValues  Résultat de buildUpdateData()
     * @param array $propIds    Map term → property_id
     */
    private function mergeWithExisting($item, array $newValues, array $propIds): array
    {
        $merged = [];

        foreach ($newValues as $term => $newEntries) {
            // Sérialise les valeurs existantes de ce terme
            $existing = [];
            foreach ($item->values() as $vterm => $vdata) {
                if ($vterm !== $term) continue;
                foreach ($vdata['values'] as $v) {
                    $entry = [
                        'type'        => $v->type(),
                        'property_id' => $v->property()->id(),
                    ];
                    if ($v->type() === 'uri') {
                        $entry['@id']      = $v->uri();
                        $entry['o:label']  = $v->value(); // libellé URI optionnel
                    } else {
                        $entry['@value']   = $v->value();
                        $entry['@lang']    = $v->lang() ?: null;
                    }
                    $existing[] = $entry;
                }
            }

            // Ajoute chaque nouvelle valeur seulement si absente
            foreach ($newEntries as $new) {
                $alreadyPresent = false;
                foreach ($existing as $ex) {
                    if ($new['type'] === 'uri') {
                        if (($ex['@id'] ?? '') === ($new['@id'] ?? '')) {
                            $alreadyPresent = true;
                            break;
                        }
                    } else {
                        if (($ex['@value'] ?? '') === ($new['@value'] ?? '')) {
                            $alreadyPresent = true;
                            break;
                        }
                    }
                }
                if (!$alreadyPresent) {
                    $existing[] = $new;
                }
            }

            if (!empty($existing)) {
                $merged[$term] = $existing;
            }
        }

        return $merged;
    }

    /**
     * Construit le tableau de mise à jour partielle à partir d'un enregistrement JSON.
     */
    private function buildUpdateData(array $record, array $propIds): array
    {
        $data = [];

        foreach (self::FIELD_MAP as $jsonKey => [$term, $type]) {
            if (!isset($propIds[$term])) {
                continue;
            }

            $val = $record[$jsonKey] ?? null;

            // Ignore les valeurs vides ou les tableaux (certains champs JSON sont multi-valués)
            if ($val === null || $val === '' || is_array($val)) {
                continue;
            }

            $val = trim((string) $val);
            if ($val === '') {
                continue;
            }

            if ($type === 'uri') {
                $data[$term] = [[
                    '@id'         => $val,
                    'type'        => 'uri',
                    'property_id' => $propIds[$term],
                ]];
            } else {
                $data[$term] = [[
                    '@value'      => $val,
                    'type'        => 'literal',
                    'property_id' => $propIds[$term],
                ]];
            }
        }

        return $data;
    }
}
