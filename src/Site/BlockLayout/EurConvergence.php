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

class EurConvergence extends AbstractBlockLayout
{
    public function getLabel(): string
    {
        return 'Convergences EUR Scanr'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ): string {
        $defaults = [
            'query'       => '',
            'heading'     => '',
            'max_per_eur' => 5,
        ];
        $data = $block ? $block->data() + $defaults : $defaults;

        $form = new Form();

        $form->add([
            'type'    => OmekaElement\Query::class,
            'name'    => 'o:block[__blockIndex__][o:data][query]',
            'options' => [
                'label'                       => 'Requête pour récupérer les enseignants-chercheurs', // @translate
                'info'                        => 'Définir la requête pour lister les chercheurs [valo:EnseignantChercheur] à évaluer', // @translate
                'query_resource_type'         => 'items',
                'query_partial_excludelist'   => [
                    'common/advanced-search/site',
                    'common/advanced-search/sort',
                ],
                'query_preview_append_query'  => ['site_id' => $site->id()],
            ],
            'attributes' => [
                'value' => $data['query'],
            ],
        ]);

        $form->add([
            'type'    => Element\Text::class,
            'name'    => 'o:block[__blockIndex__][o:data][heading]',
            'options' => [
                'label' => 'Titre du bloc', // @translate
            ],
            'attributes' => [
                'value'       => $data['heading'],
                'placeholder' => 'Convergences avec les EUR',
            ],
        ]);

        $form->add([
            'type'    => Element\Number::class,
            'name'    => 'o:block[__blockIndex__][o:data][max_per_eur]',
            'options' => [
                'label' => 'Nombre max de chercheurs affichés par EUR', // @translate
            ],
            'attributes' => [
                'value' => $data['max_per_eur'],
                'min'   => 1,
                'max'   => 20,
            ],
        ]);

        return $view->formCollection($form);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block): string
    {
        $data      = $block->data();
        $heading   = trim($data['heading'] ?? '');
        $maxPerEur = (int) ($data['max_per_eur'] ?? 5);

        $services = $view->site->getServiceLocator();
        $auth     = $services->get('Omeka\AuthenticationService');
        $user     = $auth->getIdentity();

        if (!$user) {
            return $view->partial('scanr/site/block-layout/eur-convergence', [
                'heading'   => $heading ?: 'Convergences avec les EUR',
                'allowed'   => false,
                'itemsJson' => '[]',
                'maxPerEur' => $maxPerEur,
                'ajaxUrl'   => '',
            ]);
        }

        parse_str($data['query'] ?? '', $queryParams);

        try {
            $response = $view->api()->search('items', $queryParams);
            $items    = $response->getContent();
        } catch (\Exception $e) {
            $items = [];
        }

        $itemsData = array_map(fn($item) => [
            'id'    => $item->id(),
            'title' => $item->displayTitle() ?? '',
        ], $items);

        $ajaxUrl = $view->url('admin/scanr/eur-convergence-ajax', [], ['force_canonical' => true]);

        return $view->partial('scanr/site/block-layout/eur-convergence', [
            'heading'   => $heading ?: 'Convergences avec les EUR',
            'allowed'   => true,
            'itemsJson' => json_encode($itemsData, JSON_UNESCAPED_UNICODE),
            'maxPerEur' => $maxPerEur,
            'ajaxUrl'   => $ajaxUrl,
        ]);
    }
}
