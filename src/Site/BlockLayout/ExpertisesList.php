<?php declare(strict_types=1);

namespace Scanr\Site\BlockLayout;

use Omeka\Api\Representation\SiteRepresentation;
use Omeka\Api\Representation\SitePageRepresentation;
use Omeka\Api\Representation\SitePageBlockRepresentation;
use Omeka\Site\BlockLayout\AbstractBlockLayout;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Laminas\View\Renderer\PhpRenderer;

class ExpertisesList extends AbstractBlockLayout
{
    public function getLabel(): string
    {
        return 'Expertises Scanr'; // @translate
    }

    public function form(
        PhpRenderer $view,
        SiteRepresentation $site,
        SitePageRepresentation $page = null,
        SitePageBlockRepresentation $block = null
    ): string {
        $defaults = [
            'item_id' => '',
            'heading' => '',
        ];
        $data = $block ? $block->data() + $defaults : $defaults;

        // Recherche des items de type foaf:Person
        $personItems = [];
        try {
            $settings = $view->setting();
            $classPerson = $settings ? ($settings->get('scanr_class_person')[0] ?? 'foaf:Person') : 'foaf:Person';
            $results = $view->api()->search('items', [
                'resource_class_term' => $classPerson,
                'per_page' => 500,
                'sort_by' => 'title',
            ])->getContent();
            foreach ($results as $item) {
                $personItems[$item->id()] = sprintf('[%d] %s', $item->id(), $item->displayTitle());
            }
        } catch (\Exception $e) {
            // pas de filtre si erreur
        }

        $form = new Form();

        $form->add([
            'type' => Element\Select::class,
            'name' => 'o:block[__blockIndex__][o:data][item_id]',
            'options' => [
                'label' => 'Personne (item)', // @translate
                'empty_option' => '— Choisir une personne —', // @translate
                'value_options' => $personItems,
            ],
            'attributes' => [
                'value' => $data['item_id'],
                'class' => 'chosen-select',
            ],
        ]);

        $form->add([
            'type' => Element\Text::class,
            'name' => 'o:block[__blockIndex__][o:data][heading]',
            'options' => [
                'label' => 'Titre du bloc', // @translate
            ],
            'attributes' => [
                'value' => $data['heading'],
                'placeholder' => 'Mots-clefs & expertises',
            ],
        ]);

        return $view->formCollection($form);
    }

    public function render(PhpRenderer $view, SitePageBlockRepresentation $block): string
    {
        $data = $block->data();
        $itemId = (int) ($data['item_id'] ?? 0);
        $heading = trim($data['heading'] ?? '');

        if (!$itemId) {
            return '';
        }

        try {
            $item = $view->api()->read('items', $itemId)->getContent();
        } catch (\Exception $e) {
            return '';
        }

        return $view->partial('scanr/site/block-layout/expertises-list', [
            'item' => $item,
            'heading' => $heading ?: 'Mots-clefs & expertises',
        ]);
    }
}
