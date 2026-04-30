<?php declare(strict_types=1);

namespace Scanr\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Omeka\Form\Element\ResourceSelect;

class UserSettingsFieldset extends Fieldset
{
    /**
     * @var string
     */
    protected $label = 'Scanr'; // @translate

    protected $elementGroups = [
        'scanr' => 'Scanr', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'scanr')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'scanr_creator_id',
                'type' => Element\Number::class,
                'options' => [
                    'label' => 'ID item évaluateur (dcterms:creator)', // @translate
                    'info'  => 'Identifiant Omeka S de l\'item représentant l\'évaluateur pour le CRUD des expertises.', // @translate
                ],
                'attributes' => [
                    'id'       => 'scanr_creator_id',
                    'required' => false,
                    'min'      => 1,
                ],
            ])
            ->add([
                'name' => 'scanr_labos_admin',
                'type' => ResourceSelect::class,
                'options' => [
                    'label' => 'Administration laboratoire(s)', // @translate
                    'empty_option' => 'Select laboratoire(s)…', // @translate
                    'resource_value_options' => [
                        'resource' => 'items',
                        'query' => ["resource_class_id"=>163],
                        'option_text_callback' => fn ($resource) => $resource->displayTitle(),
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_labos_admin',
                    'class' => 'chosen-select',
                    'multiple' => true,
                    'required' => false,
                    'data-placeholder' => 'Select laboratoire(s)…', // @translate
                ],
            ]);


        ;
    }
}
