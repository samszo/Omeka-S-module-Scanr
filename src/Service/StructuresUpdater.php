<?php declare(strict_types=1);

namespace Scanr\Service;

use Omeka\Stdlib\Message;

/**
 * Logique de mise à jour des structures à partir du fichier JSON MESR.
 *
 * Peut être appelé directement (depuis un événement) ou via le job UpdateStructures.
 */
class StructuresUpdater
{
    const BATCH_SIZE = 50;

    const FIELD_MAP = [
        'sigle'             => ['foaf:surname',           'literal'],
        'annee_de_creation' => ['dcterms:created',        'literal'],
        'site_web'          => ['foaf:workplaceHomepage', 'uri'],
        'adresse'           => ['schema:address',         'literal'],
        'code_postal'       => ['schema:postalCode',      'literal'],
        'commune'           => ['schema:addressLocality', 'literal'],
    ];

    private $services;
    private $geocoding;

    public function __construct($services)
    {
        $this->services  = $services;
        $this->geocoding = $services->get('Scanr\Geocoding');
    }

    /**
     * @param array         $args        Paramètres (item_id, json_path)
     * @param callable|null $shouldStop  Callback retournant true pour arrêter (utilisé par le job)
     */
    public function run(array $args = [], ?callable $shouldStop = null): void
    {
        $logger        = $this->services->get('Omeka\Logger');
        $api           = $this->services->get('Omeka\ApiManager');
        $settings      = $this->services->get('Omeka\Settings');
        $entityManager = $this->services->get('Omeka\EntityManager');

        // ── Fichier JSON ──────────────────────────────────────────────────────
        $jsonPath = $args['json_path']
            ?? dirname(__DIR__, 2) . '/data/fr-esr-structures-recherche-publiques-actives.json';

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

        $structureIndex = [];
        foreach ($jsonData as $record) {
            $num = $record['numero_national_de_structure'] ?? null;
            if ($num !== null && $num !== '') {
                $structureIndex[(string) $num] = $record;
            }
        }
        unset($jsonData);

        $logger->info(new Message('UpdateStructures: %d structures indexées.', count($structureIndex)));

        // ── IDs des propriétés ────────────────────────────────────────────────
        $propTerms = array_unique(array_merge(
            ['dcterms:isReferencedBy'],
            array_column(self::FIELD_MAP, 0)
        ));

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
        $classOrg  = ($settings->get('scanr_class_structure') ?? ['foaf:Organization'])[0];
        $rcResults = $api->search('resource_classes', ['term' => $classOrg])->getContent();
        $rcId      = $rcResults ? $rcResults[0]->id() : null;

        if (!$rcId) {
            $logger->err(new Message('UpdateStructures: classe "%s" introuvable.', $classOrg));
            return;
        }

        // ── Parcours des items ────────────────────────────────────────────────
        $singleItemId = (int) ($args['item_id'] ?? 0);
        $page         = 1;
        $processed    = 0;
        $updated      = 0;
        $skipped      = 0;

        $logger->info(new Message(
            'UpdateStructures: recherche des items de classe %s avec dcterms:isReferencedBy…', $classOrg
        ));

        while (true) {
            if ($shouldStop && $shouldStop()) {
                $logger->warn(new Message('UpdateStructures: arrêté à la page %d.', $page));
                break;
            }

            $query = [
                'resource_class_id' => $rcId,
                'property'          => [[
                    'joiner'   => 'and',
                    'property' => $propIds['dcterms:isReferencedBy'],
                    'type'     => 'ex',
                ]],
                'per_page' => self::BATCH_SIZE,
                'page'     => $page,
            ];

            if ($singleItemId) {
                $query['id'] = $singleItemId;
            }

            $items = $api->search('items', $query)->getContent();

            if (empty($items)) {
                break;
            }

            foreach ($items as $item) {
                $processed++;

                if ($shouldStop && $shouldStop()) {
                    $logger->warn(new Message('UpdateStructures: arrêté (item #%d).', $item->id()));
                    break 2;
                }

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

                $record    = $structureIndex[$numStr];
                $newValues = $this->buildUpdateData($record, $propIds);

                if (empty($newValues)) {
                    $skipped++;
                    continue;
                }

                $updateData = $this->mergeWithExisting($item, $newValues);

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

                $this->geocodeItem($item->id(), $record, $api, $logger);

                unset($item);
            }

            unset($items);
            $entityManager->clear();

            if ($singleItemId) {
                break;
            }

            $page++;
        }

        $logger->info(new Message(
            'UpdateStructures: terminé. %d traité(s), %d mis à jour, %d ignoré(s).',
            $processed, $updated, $skipped
        ));
    }

    private function mergeWithExisting($item, array $newValues): array
    {
        $merged = [];
        foreach ($item->values() as $vterm => $vdata) {
            $merged[$vterm] = [];
            foreach ($vdata['values'] as $v) {
                $entry = [
                    'type'        => $v->type(),
                    'property_id' => $v->property()->id(),
                ];
                if ($v->type() === 'uri') {
                    $entry['@id']     = $v->uri();
                    $entry['o:label'] = $v->value();
                } else {
                    $entry['@value'] = $v->value();
                    $entry['@lang']  = $v->lang() ?: null;
                }
                $merged[$vterm][] = $entry;
            }
        }

        foreach ($newValues as $term => $newEntries) {
            if (!isset($merged[$term])) {
                $merged[$term] = [];
            }
            foreach ($newEntries as $new) {
                $alreadyPresent = false;
                foreach ($merged[$term] as $ex) {
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
                    $merged[$term][] = $new;
                }
            }
        }

        return $merged;
    }

    private function geocodeItem(int $itemId, array $record, $api, $logger): void
    {
        $parts = array_filter([
            $record['adresse']     ?? null,
            $record['code_postal'] ?? null,
            $record['commune']     ?? null,
        ]);

        if (empty($parts)) {
            return;
        }

        $address = implode(', ', $parts);
        $coords  = $this->geocoding->geocodeAddress($address);

        if ($coords === null) {
            $logger->info(new Message(
                'UpdateStructures: item #%d — géocodage sans résultat pour "%s".',
                $itemId, $address
            ));
            return;
        }

        try {
            $this->saveFeature($itemId, $coords['lat'], $coords['lng'], $api);
            $logger->info(new Message(
                'UpdateStructures: item #%d géolocalisé (lat=%s, lng=%s).',
                $itemId, $coords['lat'], $coords['lng']
            ));
        } catch (\Exception $e) {
            $logger->err(new Message(
                'UpdateStructures: erreur géolocalisation item #%d — %s', $itemId, $e->getMessage()
            ));
        }
    }

    public function saveFeature(int $itemId, float $lat, float $lng, $api): void
    {
        $existing    = $api->search('mapping_features', ['item_id' => $itemId])->getContent();
        $featureData = [
            'o:item'                                 => ['o:id' => $itemId],
            'o-module-mapping:geography-type'        => 'Point',
            'o-module-mapping:geography-coordinates' => [$lng, $lat],
        ];

        if (!empty($existing)) {
            $api->update('mapping_features', $existing[0]->id(), $featureData);
        } else {
            $api->create('mapping_features', $featureData);
        }
    }

    private function buildUpdateData(array $record, array $propIds): array
    {
        $data = [];

        foreach (self::FIELD_MAP as $jsonKey => [$term, $type]) {
            if (!isset($propIds[$term])) {
                continue;
            }

            $val = $record[$jsonKey] ?? null;

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
