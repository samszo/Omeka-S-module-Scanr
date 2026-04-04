<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
use Omeka\Api\Representation\PropertyRepresentation;
use Omeka\Stdlib\Message;

/**
 * Classe de base abstraite pour tous les clients scanR.
 *
 * Fournit :
 *  - la structure de données commune (propriétés partagées)
 *  - formatPerson()         et ses dépendances directes
 *  - mapPersonToItem()      et ses dépendances (getConcept, getOrga, …)
 *  - getProperty() / getRc() (cache des métadonnées Omeka)
 *
 * Chaque sous-classe doit implémenter :
 *  - testConnection() : bool
 *  - searchPersons(string $query, int $page, int $size) : array
 *
 * Et peut surcharger :
 *  - formatPerson()      si le format brut diffère (ex. ApiClient avec _source/_score)
 *  - getPersonById()     si le client sait récupérer une personne par ID
 */
abstract class MainClient
{
    // ── Dépendances Omeka ─────────────────────────────────────────────────────

    /** @var Settings */
    protected $settings;

    /** @var \Omeka\Api\Manager|null */
    protected $apiOmk;

    /** @var \Doctrine\DBAL\Connection|null */
    protected $connection;

    /** @var \Doctrine\ORM\EntityManager|null */
    protected $entityManager;

    /** @var mixed */
    protected $logger;

    // ── Caches internes ───────────────────────────────────────────────────────

    /** @var PropertyRepresentation[] */
    protected array $properties = [];

    /** @var \Omeka\Api\Representation\ResourceClassRepresentation[] */
    protected array $rcs = [];

    // ── Configuration scanR (chargée depuis les settings) ─────────────────────

    /** @var string|null  Terme de propriété pour retrouver un item Omeka (ex: foaf:accountName) */
    protected $propFind;

    /** @var string|null  Terme de classe pour les personnes (ex: foaf:Person) */
    protected $classPerson;

    /** @var array|null   IDs du template Omeka à appliquer aux personnes */
    protected $templatePerson;

    /** @var array|null   IDs de la collection Omeka pour les personnes */
    protected $itemsetPerson;

    /** @var string|null  Terme de propriété liant une personne à une structure */
    protected $propHasStructure;

    /** @var string|null  Terme de propriété liant une personne à un concept */
    protected $propHasConcept;

    /** @var string|null  Terme de classe pour les structures (ex: foaf:Organization) */
    protected $classStructure;

    // ──────────────────────────────────────────────────────────────────────────
    // Initialisation partagée
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Charge toutes les propriétés issues des settings Omeka.
     * À appeler depuis le constructeur de chaque sous-classe.
     */
    protected function initFromSettings(Settings $settings): void
    {
        $this->settings         = $settings;
        $this->classPerson      = $settings->get('scanr_class_person')[0]            ?? null;
        $this->classStructure   = $settings->get('scanr_class_structure')[0]         ?? null;
        $this->propFind         = $settings->get('scanr_properties_fullName')[0]     ?? null;
        $this->propHasStructure = $settings->get('scanr_properties_hasStructure')[0] ?? null;
        $this->propHasConcept   = $settings->get('scanr_properties_hasConcept')[0]   ?? null;
        $this->templatePerson   = $settings->get('scanr_template_person');
        $this->itemsetPerson    = $settings->get('scanr_itemset_person');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface abstraite (chaque client l'implémente différemment)
    // ──────────────────────────────────────────────────────────────────────────

    abstract public function testConnection(): bool;

    abstract public function searchPersons(string $query, int $page = 0, int $size = 3): array;

    /**
     * Obtenir les détails d'une personne par son ID.
     * Surchargée par les clients qui savent le faire (ApiClient, DuckClient).
     *
     * @throws \BadMethodCallException si le client n'implémente pas cette méthode
     */
    public function getPersonById(string $personId): array
    {
        throw new \BadMethodCallException(
            static::class . ' n\'implémente pas getPersonById(). Utilisez ApiClient.'
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // formatPerson et dépendances directes
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Formate un enregistrement brut (champs plats) en structure canonique.
     *
     * Signature normalisée : la source est déjà extraite (pas de wrapper _source).
     * Les sous-classes qui reçoivent un format différent (ex. Elasticsearch)
     * surchargent cette méthode pour extraire source/score avant d'appeler parent::formatPerson().
     *
     * @param array $source Données brutes de la personne (champs à plat)
     * @param float $score  Score de pertinence (0 si non disponible)
     * @return array Structure canonique attendue par les vues et mapPersonToItem()
     */
    protected function formatPerson(array $source, float $score = 0): array
    {
        return [
            'id'             => $source['id']             ?? '',
            'score'          => $score,
            'items'          => $this->referenceSearchResults($source, $this->classPerson),
            'firstName'      => $source['firstName']      ?? '',
            'lastName'       => $source['lastName']       ?? '',
            'fullName'       => $source['fullName']       ?? '',
            'domains'        => $source['domains']        ?? [],
            'coContributors' => $source['coContributors'] ?? [],
            'externalIds'    => $source['externalIds']    ?? [],
            // Gère les deux nommages présents dans le dump (topDomains vs top_domains)
            'top_domains'    => $source['topDomains']     ?? $source['top_domains'] ?? [],
            'affiliations'   => $source['affiliations']   ?? [],
            'awards'         => $source['awards']         ?? [],
            'publications'   => $source['publications']   ?? [],
        ];
    }

    /**
     * Cherche si un item Omeka correspond déjà à cette personne.
     * Retourne [] si apiOmk ou connection ne sont pas disponibles.
     *
     * @param array  $data  Données de la personne (doit contenir fullName)
     * @param string $class Terme de classe Omeka (ex: foaf:Person)
     * @return array Liste d'items Omeka trouvés
     */
    protected function referenceSearchResults(array $data, $class): array
    {
        // Guard : ces dépendances ne sont pas disponibles dans tous les clients
        if (!$this->connection || !$this->apiOmk || !$this->propFind) {
            return [];
        }

        switch ($class) {
            case $this->classPerson:
                $prop = $this->getProperty($this->propFind)->id();
                $val  = $data['fullName'] ?? '';
                $sql  = 'SELECT resource_id FROM value WHERE property_id = ? AND value = ?';
                break;

            default:
                return [];
        }

        $result = $this->connection->fetchAll($sql, [$prop, $val]);

        if (!empty($result)) {
            $id    = $result[0]['resource_id'];
            $items = [$this->apiOmk->read('items', $id)->getContent()];
            $this->logger->info(
                'Person find "{val}" = #{resource_id}.', // @translate
                [
                    'val'         => ($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''),
                    'resource_id' => $id,
                    'referenceId' => 'Scanr - referenceSearchResults',
                ]
            );
            return $items;
        }

        return [];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Cache propriétés / classes Omeka
    // ──────────────────────────────────────────────────────────────────────────

    public function getProperty(string $term): PropertyRepresentation
    {
        if (!isset($this->properties[$term])) {
            $this->properties[$term] = $this->apiOmk
                ->search('properties', ['term' => $term])
                ->getContent()[0];
        }
        return $this->properties[$term];
    }

    public function getRc(string $term)
    {
        if (!isset($this->rcs[$term])) {
            $this->rcs[$term] = $this->apiOmk
                ->search('resource_classes', ['term' => $term])
                ->getContent()[0];
        }
        return $this->rcs[$term];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // mapPersonToItem et dépendances
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mapper les données d'une personne scanR vers un item Omeka S.
     *
     * @param array $personData Données de la personne (format canonique de formatPerson)
     * @param bool  $addCoContrib Ajoute coContributeurs, domaines et publications
     * @return array Données formatées pour l'API Omeka S
     */
    public function mapPersonToItem(array $personData, bool $addCoContrib = true): array
    {
        // ── Base de l'item ────────────────────────────────────────────────────
        if (!empty($personData['items'])) {
            $itemData = json_decode(json_encode($personData['items'][0]), true);
        } else {
            $itemData = ['o:resource_class' => ['o:id' => $this->classPerson]];
            if (!empty($this->templatePerson)) {
                $itemData['o:resource_template'] = ['o:id' => $this->templatePerson[0]];
            }
            if (!empty($this->itemsetPerson)) {
                $itemData['o:item_set'] = ['o:id' => $this->itemsetPerson[0]];
            }
        }

        // ── Champs scalaires ─────────────────────────────────────────────────
        if (!empty($personData['fullName']) && !isset($itemData['dcterms:title'])) {
            $itemData['dcterms:title'][] = [
                'type'        => 'literal',
                'property_id' => $this->getProperty('dcterms:title')->id() . '',
                '@value'      => $personData['fullName'],
            ];
        }

        if (!empty($personData['firstName']) && !isset($itemData['foaf:firstName'])) {
            $itemData['foaf:firstName'][] = [
                'type'        => 'literal',
                'property_id' => $this->getProperty('foaf:firstName')->id() . '',
                '@value'      => $personData['firstName'],
            ];
        }

        if (!empty($personData['lastName']) && !isset($itemData['foaf:familyName'])) {
            $itemData['foaf:lastName'][] = [
                'type'        => 'literal',
                'property_id' => $this->getProperty('foaf:familyName')->id() . '',
                '@value'      => $personData['lastName'],
            ];
        }

        if (!empty($personData['id']) && !isset($itemData['dcterms:identifier'])) {
            $itemData['dcterms:identifier'][] = [
                'type'        => 'literal',
                'property_id' => $this->getProperty('dcterms:identifier')->id() . '',
                '@value'      => 'scanr:' . $personData['id'],
            ];
        }

        // ── Identifiants externes ─────────────────────────────────────────────
        if (!empty($personData['externalIds'])) {
            $itemData['dcterms:isReferencedBy'] = [];
            foreach ($personData['externalIds'] as $extId) {
                $itemData['dcterms:isReferencedBy'][] = [
                    'property_id' => $this->getProperty('dcterms:isReferencedBy')->id() . '',
                    '@id'         => $extId['url'],
                    'o:label'     => $extId['type'] . ':' . $extId['id'],
                    'type'        => 'uri',
                ];
            }
        }

        // ── Domaines / concepts ───────────────────────────────────────────────
        if ($addCoContrib && !empty($personData['top_domains'])) {
            $itemData[$this->propHasConcept] = [];
            $concepts = [];

            $this->logger->info(new Message('Get rank domains = ' . count($personData['top_domains'])));
            foreach ($personData['top_domains'] as $domain) {
                if (!isset($domain['label'])) {
                    continue;
                }
                $key = $domain['type'] !== 'keyword'
                    ? $domain['type'] . $domain['code']
                    : $domain['label']['default'];

                if (isset($concepts[$key])) {
                    $concepts[$key]['count'] += $domain['count'];
                } else {
                    $concepts[$key] = $domain;
                }
            }

            usort($concepts, static fn ($a, $b) => $b['count'] - $a['count']);

            foreach ($concepts as $concept) {
                $idConcept  = $this->getConcept($concept);
                $annotation = [
                    'curation:rank' => [[
                        'property_id' => $this->getProperty('curation:rank')->id() . '',
                        '@value'      => (string) $concept['count'],
                        'type'        => 'literal',
                    ]],
                ];
                $itemData[$this->propHasConcept][] = [
                    'property_id'      => $this->getProperty($this->propHasConcept)->id() . '',
                    'value_resource_id'=> $idConcept,
                    'type'             => 'resource',
                    '@annotation'      => $annotation,
                ];
            }
            unset($concepts);
        }

        // ── CoContributeurs ───────────────────────────────────────────────────
        if ($addCoContrib && !empty($personData['coContributors'])) {
            $itemData['bibo:contributorList'] = [];
            foreach ($personData['coContributors'] as $co) {
                try {
                    $scanrCo = $this->getPersonById($co['person']);
                    if (empty($scanrCo['items'])) {
                        $itemDataCo = $this->mapPersonToItem($scanrCo, false);
                        $itemCo     = $this->apiOmk->create('items', $itemDataCo)->getContent();
                        $this->logger->info('Person create "{val}" = #{resource_id}.', [
                            'val'         => $itemCo->displayTitle(),
                            'resource_id' => $itemCo->id(),
                            'referenceId' => 'Scanr - mapPersonToItem',
                        ]);
                    } else {
                        $itemCo = $scanrCo['items'][0];
                    }
                    $itemData['bibo:contributorList'][] = [
                        'property_id'      => $this->getProperty('bibo:contributorList')->id() . '',
                        'value_resource_id'=> $itemCo->id(),
                        'type'             => 'resource',
                    ];
                    unset($itemCo);
                } catch (\Exception $e) {
                    $person  = $co['fullname'] ?? '';
                    $person .= $co['person']   ?? '';
                    $this->logger->warn(new Message($e->getMessage() . ' : ' . $person));
                }
            }
        }

        // ── Affiliations ──────────────────────────────────────────────────────
        if (!empty($personData['affiliations'])) {
            $itemData[$this->propHasStructure] = [];
            foreach ($personData['affiliations'] as $affiliation) {
                if (!isset($affiliation['structure']['label'])) {
                    continue;
                }
                $idOrga     = $this->getOrga($affiliation);
                $annotation = [
                    'curation:rank'  => [[
                        'property_id' => $this->getProperty('curation:rank')->id() . '',
                        '@value'      => $affiliation['publicationsCount'],
                        'type'        => 'literal',
                    ]],
                    'curation:start' => [[
                        'property_id' => $this->getProperty('curation:start')->id() . '',
                        '@value'      => $affiliation['startDate'],
                        'type'        => 'literal',
                    ]],
                    'curation:end'   => [[
                        'property_id' => $this->getProperty('curation:end')->id() . '',
                        '@value'      => $affiliation['endDate'],
                        'type'        => 'literal',
                    ]],
                ];
                $itemData[$this->propHasStructure][] = [
                    'property_id'      => $this->getProperty($this->propHasStructure)->id() . '',
                    'value_resource_id'=> $idOrga,
                    'type'             => 'resource',
                    '@annotation'      => $annotation,
                ];
                unset($idOrga);
            }
        }

        // ── Publications ──────────────────────────────────────────────────────
        if ($addCoContrib && !empty($personData['publications'])) {
            $itemData['foaf:publications'] = [];
            $publis = [];

            foreach ($personData['publications'] as $publi) {
                if (!isset($publi['title']['default'])) {
                    continue;
                }
                $key = $publi['publication'];
                if (!isset($publis[$key])) {
                    $publis[$key]           = $publi;
                    $publis[$key]['status'] = [$publi['role']];
                } elseif (!in_array($publi['role'], $publis[$key]['status'])) {
                    $publis[$key]['status'][] = $publi['role'];
                }
            }

            foreach ($publis as $publi) {
                $annotation = [
                    'dcterms:date' => [[
                        'property_id' => $this->getProperty('dcterms:date')->id() . '',
                        '@value'      => $publi['year'] ?? '',
                        'type'        => 'literal',
                    ]],
                    'dcterms:isReferencedBy' => [[
                        'property_id' => $this->getProperty('dcterms:isReferencedBy')->id() . '',
                        '@value'      => $publi['publication'],
                        'type'        => 'literal',
                    ]],
                ];
                foreach ($publi['status'] as $role) {
                    $annotation['foaf:status'][] = [
                        'property_id' => $this->getProperty('foaf:status')->id() . '',
                        '@value'      => $role,
                        'type'        => 'literal',
                    ];
                }
                $itemData['foaf:publications'][] = [
                    'type'        => 'literal',
                    'property_id' => $this->getProperty('foaf:publications')->id() . '',
                    '@value'      => $publi['title']['default'],
                    '@annotation' => $annotation,
                ];
            }
            unset($publis);
        }

        return $itemData;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Gestion des concepts (skos) et organisations
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Récupère ou crée un item skos:Concept correspondant au tag.
     *
     * @param array $tag Domaine provenant du dump scanR
     * @return int ID de l'item Omeka
     */
    protected function getConcept(array $tag): int
    {
        if ($tag['type'] === 'wikidata') {
            $prop = $this->getProperty('dcterms:isReferencedBy')->id();
            $val  = 'https://www.wikidata.org/wiki/' . $tag['code'];
            $sql  = 'SELECT resource_id FROM value WHERE property_id = ? AND uri = ?';
        } elseif (isset($tag['code'], $tag['type'])) {
            $prop = $this->getProperty('dcterms:isReferencedBy')->id();
            $val  = $tag['type'] . '_' . $tag['code'];
            $sql  = 'SELECT resource_id FROM value WHERE property_id = ? AND value = ?';
        } else {
            $prop = $this->getProperty('skos:prefLabel')->id();
            $val  = $tag['label']['default'];
            $sql  = 'SELECT resource_id FROM value WHERE property_id = ? AND value = ?';
        }

        $result = $this->connection->fetchAll($sql, [$prop, $val]);

        if (!empty($result)) {
            $id = $result[0]['resource_id'];
            $this->logger->info('Concept find "{val}" = #{resource_id}.', [
                'val' => $val, 'resource_id' => $id,
            ]);
            return (int) $id;
        }

        // Création du concept
        $oItem = ['o:resource_class' => ['o:id' => $this->getRc('skos:Concept')->id()]];
        $oItem['dcterms:title'][]   = ['property_id' => $this->getProperty('dcterms:title')->id(),   '@value' => $tag['label']['default'], 'type' => 'literal'];
        $oItem['skos:prefLabel'][]  = ['property_id' => $this->getProperty('skos:prefLabel')->id(),  '@value' => $tag['label']['default'], 'type' => 'literal'];

        if ($tag['type'] === 'wikidata') {
            $oItem['dcterms:isReferencedBy'][] = [
                'property_id' => $this->getProperty('dcterms:isReferencedBy')->id(),
                '@id'         => 'https://www.wikidata.org/wiki/' . $tag['code'],
                'o:label'     => 'wikidata',
                'type'        => 'uri',
            ];
        }
        if (isset($tag['code'], $tag['type'])) {
            $oItem['dcterms:isReferencedBy'][] = [
                'property_id' => $this->getProperty('dcterms:isReferencedBy')->id(),
                '@value'      => $tag['type'] . '_' . $tag['code'],
                'type'        => 'literal',
            ];
        }

        $cpt = $this->apiOmk->create('items', $oItem, [], ['continueOnError' => true])->getContent();
        $id  = $cpt->id();
        $this->logger->info('Concept create "{val}" = #{resource_id}.', [
            'val' => $val, 'resource_id' => $id,
        ]);

        return (int) $id;
    }

    /**
     * Récupère ou crée un item organisation correspondant à une affiliation.
     *
     * @param array $orga Affiliation provenant du dump scanR
     * @return int ID de l'item Omeka
     */
    protected function getOrga(array $orga): int
    {
        $prop   = $this->getProperty('dcterms:isReferencedBy')->id();
        $val    = $orga['structure']['id_name'];
        $sql    = 'SELECT resource_id FROM value WHERE property_id = ? AND value = ?';
        $result = $this->connection->fetchAll($sql, [$prop, $val]);

        if (!empty($result)) {
            $id = $result[0]['resource_id'];
            $this->logger->info('Organisation find "{val}" = #{resource_id}.', [
                'val' => $val, 'resource_id' => $id,
            ]);
            return (int) $id;
        }

        $oItem = ['o:resource_class' => ['o:id' => $this->getRc($this->classStructure)->id()]];
        $oItem['dcterms:title'][]          = ['property_id' => $this->getProperty('dcterms:title')->id(),          '@value' => $orga['structure']['label']['default'], 'type' => 'literal'];
        $oItem['dcterms:type'][]           = ['property_id' => $this->getProperty('dcterms:type')->id(),           '@value' => $this->getTypeFromOrga($orga),            'type' => 'literal'];
        $oItem['dcterms:isReferencedBy'][] = ['property_id' => $this->getProperty('dcterms:isReferencedBy')->id(), '@value' => $orga['structure']['id_name'],            'type' => 'literal'];

        $cpt = $this->apiOmk->create('items', $oItem, [], ['continueOnError' => true])->getContent();
        $id  = $cpt->id();
        $this->logger->info('Organisation create "{val}" = #{resource_id}.', [
            'val' => $val, 'resource_id' => $id,
        ]);

        return (int) $id;
    }

    /**
     * Détermine le type d'une organisation à partir de son ID scanR.
     */
    protected function getTypeFromOrga(array $orga): string
    {
        if (substr($orga['structure']['id'], 0, 2) === 'ED') {
            return 'Ecole doctorale';
        }
        return $orga['structure']['kind'][0] ?? 'no';
    }
}
