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

class ItemsMap extends AbstractBlockLayout
{
    public function getLabel(): string
    {
        return 'Carte des items (Leaflet)'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ): string {
        $defaults = [
            'query'        => '',
            'heading'      => '',
            'prop_address' => 'schema:address',
            'prop_lat'     => '',
            'prop_lon'     => '',
            'map_height'   => 450,
            'center_lat'   => 48.85,
            'center_lon'   => 2.35,
            'zoom'         => 6,
        ];
        $data = $block ? $block->data() + $defaults : $defaults;

        $form = new Form();

        $form->add([
            'type' => OmekaElement\Query::class,
            'name' => 'o:block[__blockIndex__][o:data][query]',
            'options' => [
                'label' => 'Requête pour sélectionner les items', // @translate
                'info'  => 'Les items retournés par cette requête seront placés sur la carte.',
                'query_resource_type' => 'items',
                'query_partial_excludelist' => [
                    'common/advanced-search/site',
                    'common/advanced-search/sort',
                ],
                'query_preview_append_query' => ['site_id' => $site->id()],
            ],
            'attributes' => ['value' => $data['query']],
        ]);

        $form->add([
            'type' => Element\Text::class,
            'name' => 'o:block[__blockIndex__][o:data][heading]',
            'options' => ['label' => 'Titre du bloc'], // @translate
            'attributes' => [
                'value'       => $data['heading'],
                'placeholder' => 'Carte des localisations',
            ],
        ]);

        $form->add([
            'type' => Element\Text::class,
            'name' => 'o:block[__blockIndex__][o:data][prop_address]',
            'options' => [
                'label' => 'Propriété adresse', // @translate
                'info'  => 'Terme de la propriété contenant l\'adresse textuelle (ex : schema:address, vcard:hasAddress, dcterms:spatial).',
            ],
            'attributes' => [
                'value'       => $data['prop_address'],
                'placeholder' => 'schema:address',
            ],
        ]);

        $form->add([
            'type' => Element\Text::class,
            'name' => 'o:block[__blockIndex__][o:data][prop_lat]',
            'options' => [
                'label' => 'Propriété latitude (optionnel)', // @translate
                'info'  => 'Si renseignée, le géocodage Nominatim est ignoré pour cet item.',
            ],
            'attributes' => [
                'value'       => $data['prop_lat'],
                'placeholder' => 'schema:latitude',
            ],
        ]);

        $form->add([
            'type' => Element\Text::class,
            'name' => 'o:block[__blockIndex__][o:data][prop_lon]',
            'options' => ['label' => 'Propriété longitude (optionnel)'], // @translate
            'attributes' => [
                'value'       => $data['prop_lon'],
                'placeholder' => 'schema:longitude',
            ],
        ]);

        $form->add([
            'type' => Element\Number::class,
            'name' => 'o:block[__blockIndex__][o:data][map_height]',
            'options' => ['label' => 'Hauteur de la carte (px)'], // @translate
            'attributes' => [
                'value' => $data['map_height'],
                'min'   => 100,
                'max'   => 1200,
                'step'  => 10,
            ],
        ]);

        $form->add([
            'type' => Element\Number::class,
            'name' => 'o:block[__blockIndex__][o:data][center_lat]',
            'options' => ['label' => 'Latitude centre'], // @translate
            'attributes' => [
                'value' => $data['center_lat'],
                'step'  => 'any',
            ],
        ]);

        $form->add([
            'type' => Element\Number::class,
            'name' => 'o:block[__blockIndex__][o:data][center_lon]',
            'options' => ['label' => 'Longitude centre'], // @translate
            'attributes' => [
                'value' => $data['center_lon'],
                'step'  => 'any',
            ],
        ]);

        $form->add([
            'type' => Element\Number::class,
            'name' => 'o:block[__blockIndex__][o:data][zoom]',
            'options' => ['label' => 'Zoom initial'], // @translate
            'attributes' => [
                'value' => $data['zoom'],
                'min'   => 1,
                'max'   => 18,
            ],
        ]);

        return $view->formCollection($form);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block): string
    {
        $data        = $block->data();
        $heading     = trim($data['heading']      ?? '');
        $propAddress = trim($data['prop_address'] ?? 'schema:address');
        $propLat     = trim($data['prop_lat']     ?? '');
        $propLon     = trim($data['prop_lon']     ?? '');
        $mapHeight   = (int)  ($data['map_height']  ?? 450);
        $centerLat   = (float)($data['center_lat']  ?? 48.85);
        $centerLon   = (float)($data['center_lon']  ?? 2.35);
        $zoom        = (int)  ($data['zoom']         ?? 6);

        parse_str($data['query'] ?? '', $queryParams);

        try {
            $response    = $view->api()->search('items', $queryParams);
            $items       = $response->getContent();
        } catch (\Exception $e) {
            $items = [];
        }

        // Pré-charge tous les marqueurs Mapping existants pour ces items (1 seule requête)
        $mappingByItem = [];
        $itemIds = array_map(fn($i) => $i->id(), $items);
        if ($itemIds) {
            try {
                $mappingMarkers = $view->api()->search('mapping_markers', [
                    'item_id' => $itemIds,
                    'per_page' => count($itemIds),
                ])->getContent();
                foreach ($mappingMarkers as $marker) {
                    $md  = json_decode(json_encode($marker), true);
                    $iid = $md['o:item']['o:id'] ?? 0;
                    if ($iid && !isset($mappingByItem[$iid])) {
                        $mappingByItem[$iid] = [
                            'lat' => isset($md['o-module-mapping:lat']) ? (float)$md['o-module-mapping:lat'] : null,
                            'lon' => isset($md['o-module-mapping:lng']) ? (float)$md['o-module-mapping:lng'] : null,
                        ];
                    }
                }
            } catch (\Exception $e) {
                // Module Mapping absent ou requête échouée — on continue sans
            }
        }

        // Construction des markers
        $markers = [];
        foreach ($items as $item) {
            $iid     = $item->id();
            $addrVal = $item->value($propAddress);
            $address = $addrVal ? (string) $addrVal : '';

            // 1) Coordonnées depuis le module Mapping (prioritaire)
            $lat = $mappingByItem[$iid]['lat'] ?? null;
            $lon = $mappingByItem[$iid]['lon'] ?? null;

            // 2) Propriétés lat/lon explicites configurées dans le bloc
            if ($lat === null && $propLat && $propLon) {
                $latVal = $item->value($propLat);
                $lonVal = $item->value($propLon);
                if ($latVal && $lonVal) {
                    $lat = (float) $latVal->value();
                    $lon = (float) $lonVal->value();
                }
            }

            // 3) Géocodage côté client (aucune coord disponible mais adresse présente)
            if (!$address && $lat === null) {
                continue; // rien à afficher, skip
            }

            $markers[] = [
                'id'      => $iid,
                'title'   => $item->displayTitle() ?? '',
                'address' => $address,
                'url'     => $item->siteUrl(null, true),
                'lat'     => $lat,
                'lon'     => $lon,
            ];
        }

        $ajaxUrl = $view->url('admin/scanr/expertise-ajax', [], ['force_canonical' => true]);

        return $view->partial('scanr/site/block-layout/items-map', [
            'heading'   => $heading ?: 'Carte des localisations',
            'markers'   => $markers,
            'mapHeight' => $mapHeight,
            'centerLat' => $centerLat,
            'centerLon' => $centerLon,
            'zoom'      => $zoom,
            'ajaxUrl'   => $ajaxUrl,
        ]);
    }
}
