<?php declare(strict_types=1);

namespace Scanr\Site\BlockLayout;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Renderer\PhpRenderer;
use Omeka\Form\Element as OmekaElement;

class OrganisationsMap extends AbstractBlockLayout
{
    /** Cache en mémoire pour la durée d'une requête HTTP (plusieurs blocs identiques). */
    private static array $memCache = [];

    /** Durée de vie du cache fichier en secondes (défaut : 1 heure). */
    const CACHE_TTL = 3600;

    public function getLabel(): string
    {
        return 'Carte des organisations'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ): string {
        $defaults = [
            'query'      => '',
            'heading'    => '',
            'map_height' => 600,
            'center_lat' => 46.8,
            'center_lng' => 2.3,
            'zoom'       => 6,
        ];
        $data = $block ? $block->data() + $defaults : $defaults;

        $form = new Form();

        $form->add([
            'type'    => OmekaElement\Query::class,
            'name'    => 'o:block[__blockIndex__][o:data][query]',
            'options' => [
                'label'                       => 'Requête pour sélectionner les organisations', // @translate
                'query_resource_type'         => 'items',
                'query_partial_excludelist'   => [
                    'common/advanced-search/site',
                    'common/advanced-search/sort',
                ],
                'query_preview_append_query'  => ['site_id' => $site->id()],
            ],
            'attributes' => ['value' => $data['query']],
        ]);

        $form->add([
            'type'       => Element\Text::class,
            'name'       => 'o:block[__blockIndex__][o:data][heading]',
            'options'    => ['label' => 'Titre du bloc'], // @translate
            'attributes' => ['value' => $data['heading'], 'placeholder' => 'Carte des organisations'],
        ]);

        $form->add([
            'type'       => Element\Number::class,
            'name'       => 'o:block[__blockIndex__][o:data][map_height]',
            'options'    => ['label' => 'Hauteur de la carte (px)'], // @translate
            'attributes' => ['value' => $data['map_height'], 'min' => 200, 'step' => 50],
        ]);

        $form->add([
            'type'       => Element\Number::class,
            'name'       => 'o:block[__blockIndex__][o:data][center_lat]',
            'options'    => ['label' => 'Latitude du centre'], // @translate
            'attributes' => ['value' => $data['center_lat'], 'step' => 'any'],
        ]);

        $form->add([
            'type'       => Element\Number::class,
            'name'       => 'o:block[__blockIndex__][o:data][center_lng]',
            'options'    => ['label' => 'Longitude du centre'], // @translate
            'attributes' => ['value' => $data['center_lng'], 'step' => 'any'],
        ]);

        $form->add([
            'type'       => Element\Number::class,
            'name'       => 'o:block[__blockIndex__][o:data][zoom]',
            'options'    => ['label' => 'Zoom initial'], // @translate
            'attributes' => ['value' => $data['zoom'], 'min' => 1, 'max' => 18],
        ]);

        return $view->formCollection($form);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block): string
    {
        $data      = $block->data();
        $heading   = trim($data['heading']   ?? '');
        $mapHeight = (int) ($data['map_height'] ?? 600);
        $centerLat = (float) ($data['center_lat'] ?? 46.8);
        $centerLng = (float) ($data['center_lng'] ?? 2.3);
        $zoom      = (int) ($data['zoom'] ?? 6);

        $services = $view->site->getServiceLocator();
        $api      = $services->get('Omeka\ApiManager');

        parse_str($data['query'] ?? '', $queryParams);

        // ── Cache ─────────────────────────────────────────────────────────────
        $cacheKey  = md5(serialize($queryParams));
        $cacheFile = sys_get_temp_dir() . '/scanr_orgmap_' . $cacheKey . '.json';

        // 1. Cache mémoire (même requête HTTP, blocs multiples identiques)
        if (isset(self::$memCache[$cacheKey])) {
            ['orgs' => $orgs, 'edges' => $edges] = self::$memCache[$cacheKey];
        // 2. Cache fichier (inter-requêtes)
        } elseif (
            file_exists($cacheFile)
            && (time() - filemtime($cacheFile)) < self::CACHE_TTL
            && ($cached = @json_decode(file_get_contents($cacheFile), true)) !== null
        ) {
            ['orgs' => $orgs, 'edges' => $edges] = $cached;
            self::$memCache[$cacheKey] = $cached;
        } else {
            // 3. Calcul complet
            try {
                $items = $api->search('items', $queryParams + ['per_page' => 500])->getContent();
            } catch (\Exception $e) {
                $items = [];
            }

            // ── Calcul ────────────────────────────────────────────────────────
            $orgs          = [];
            $edges         = [];
            $orgByMemberId = [];
            $collabs       = [];

            foreach ($items as $item) {
                $features = $api->search('mapping_features', ['item_id' => $item->id()])->getContent();
                if (empty($features)) continue;

                $coords = $features[0]->geographyCoordinates(); // [lng, lat]
                $lat    = $coords[1] ?? null;
                $lng    = $coords[0] ?? null;
                if ($lat === null || $lng === null) continue;

                $memberIds    = [];
                $memberTitles = [];
                $subjects     = $item->subjectValues();
                if (isset($subjects['member'])) {
                    foreach ($subjects['member'] as $v) {
                        $vr = $v['val']->resource();
                        if (!$vr) continue;
                        $memberIds[]    = $vr->id();
                        $memberTitles[] = $vr->displayTitle();
                        $orgByMemberId[$vr->id()][] = $item->id();

                        $subjectsMember = $vr->subjectValues();
                        if (isset($subjectsMember['list of contributors'])) {
                            foreach ($subjectsMember['list of contributors'] as $vLOC) {
                                $vrLOC = $vLOC['val']->resource();
                                $class = $vrLOC->resourceClass();
                                if ($class && $class->term() === 'dctype:Event') {
                                    if (!isset($collabs[$vrLOC->id()])) {
                                        $collabs[$vrLOC->id()] = $vrLOC;
                                    }
                                }
                            }
                        }
                    }
                }

                $orgs[$item->id()] = [
                    'id'       => $item->id(),
                    'title'    => $item->displayTitle(),
                    'url'      => $item->siteUrl(null, true),
                    'lat'      => $lat,
                    'lng'      => $lng,
                    'sigle'    => (string) ($item->value('foaf:surname')            ?? ''),
                    'address'  => implode(', ', array_filter([
                        (string) ($item->value('schema:address')         ?? ''),
                        (string) ($item->value('schema:postalCode')      ?? ''),
                        (string) ($item->value('schema:addressLocality') ?? ''),
                    ])),
                    'created'  => (string) ($item->value('dcterms:created')         ?? ''),
                    'site_web' => (string) ($item->value('foaf:workplaceHomepage')  ?? ''),
                    'members'  => $memberTitles,
                    'memberIds'=> $memberIds,
                ];
            }

            // ── Liens de collaboration ────────────────────────────────────────
            $seen = [];
            foreach ($collabs as $collab) {
                $collabOrgIds = [];
                foreach ($collab->value('bibo:contributorList', ['all' => true, 'default' => []]) as $v) {
                    $vr = $v->valueResource();
                    if ($vr && isset($orgByMemberId[$vr->id()])) {
                        foreach ($orgByMemberId[$vr->id()] as $oid) {
                            $collabOrgIds[$oid] = true;
                        }
                    }
                }
                $collabOrgIds = array_keys($collabOrgIds);
                $count = count($collabOrgIds);
                for ($i = 0; $i < $count; $i++) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        $a   = min($collabOrgIds[$i], $collabOrgIds[$j]);
                        $b   = max($collabOrgIds[$i], $collabOrgIds[$j]);
                        $key = "{$a}-{$b}";
                        if (!isset($seen[$collab->id()][$key])) {
                            $seen[$collab->id()][$key] = true;
                            $edges[$key]['from']      = $a;
                            $edges[$key]['to']        = $b;
                            $edges[$key]['collabs'][] = [
                                'title' => $collab->displayTitle(),
                                'url'   => $collab->siteUrl(null, true),
                            ];
                        }
                    }
                }
            }

            $orgs  = array_values($orgs);
            $edges = array_values($edges);

            // Persist cache fichier + mémoire
            $payload = ['orgs' => $orgs, 'edges' => $edges];
            @file_put_contents($cacheFile, json_encode($payload, JSON_UNESCAPED_UNICODE));
            self::$memCache[$cacheKey] = $payload;
        } // fin else (calcul complet)

        return $view->partial('scanr/site/block-layout/organisations-map', [
            'heading'   => $heading ?: 'Carte des organisations',
            'orgs'      => $orgs,
            'edges'     => $edges,
            'mapHeight' => $mapHeight,
            'centerLat' => $centerLat,
            'centerLng' => $centerLng,
            'zoom'      => $zoom,
        ]);
    }
}
