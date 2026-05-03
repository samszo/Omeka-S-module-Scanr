<?php declare(strict_types=1);

namespace Scanr\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'scanr_json_path',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "Path du fichier jsonl de scanR",
                    'info' => "Path du fichier jsonl de scanR à télécharger dans le répertoire data du module Scanr à partir d'ici : https://scanr.enseignementsup-recherche.gouv.fr/docs/overview",
                ],
                'attributes' => [
                    'id' => 'scanr_json_path',
                    'required' => true
                ],
            ])
            /*
            ->add([
                'name' => 'scanr_json_import',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Import json data to sql', // @translate
                ],
                'attributes' => [
                    'id' => 'scanr_json_import',
                    'required' => false
                ],
            ])
            */            
            ->add([
                'name' => 'scanr_url',
                'type' => Element\Text::class,
                'options' => [
                    'label' => "URL de l'API scanR",
                    'info' => "URL de base pour l'API Elasticsearch de scanR (par défaut: https://scanr-api.enseignementsup-recherche.gouv.fr)",
                ],
                'attributes' => [
                    'id' => 'scanr_url',
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_username',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Scanr Username',
                    'info' => 'Username for Scanr API authentication',
                ],
                'attributes' => [
                    'id' => 'scanr_username',
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_pwd',
                'type' => Element\Password::class,
                'options' => [
                    'label' => 'Scanr Password',
                    'info' => 'Password for Scanr API authentication',
                ],
                'attributes' => [
                    'id' => 'scanr_pwd',
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_properties_fullName',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties to search person', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_fullName',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_class_person',
                'type' => CommonElement\OptionalResourceClassSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Choose Class of the person', // @translate
                    'query' => ['used' => false],
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'scanr_class_person',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource classe…', // @translate'
                ],
            ])
            ->add([
                'name' => 'scanr_template_person',
                'type' => CommonElement\OptionalResourceTemplateSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Choose Resource Template of the person', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'easyadmin_quick_template',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource templates…', // @translate'
                ],
            ])
            ->add([
                'name' => 'scanr_itemset_person',
                'type' => CommonElement\OptionalItemSetSelect::class,
                'options' => [
                    'element_group' => 'advanced_search',
                    'label' => 'Item sets to new person', // @translate
                    'empty_option' => '',
                    'prepend_value_options' => [
                        'all' => 'All item sets', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_itemset_person',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select item sets…', // @translate
                ],
            ])
            ->add([
                'name' => 'scanr_class_labo',
                'type' => CommonElement\OptionalResourceClassSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Choose Class for the laboratory', // @translate
                    'query' => ['used' => false],
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'scanr_class_labo',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource classe…', // @translate'
                ],
            ])
            ->add([
                'name' => 'scanr_class_structure',
                'type' => CommonElement\OptionalResourceClassSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Choose Class for the structure', // @translate
                    'query' => ['used' => false],
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'scanr_class_structure',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource classe…', // @translate'
                ],
            ])
            ->add([
                'name' => 'scanr_properties_hasStructure',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties for relation between person and structure', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_hasStructure',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_properties_isInLabos',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties for relation between person and laboratory', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_isInLabos',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_properties_CasAccount' ,
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties for relation between person and CAS authentification', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_CasAccount',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])           
            ->add([
                'name' => 'scanr_class_concept',
                'type' => CommonElement\OptionalResourceClassSelect::class,
                'options' => [
                    'element_group' => 'editing',
                    'label' => 'Choose Class for the concept', // @translate
                    'query' => ['used' => false],
                    'empty_option' => '',
                    'term_as_value' => true,
                ],
                'attributes' => [
                    'id' => 'scanr_class_concept',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select resource classe…', // @translate'
                ],
            ])
            ->add([
                'name' => 'scanr_properties_conceptLabel',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties for concept label', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_conceptLabel',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])
            ->add([
                'name' => 'scanr_properties_hasConcept',
                'type' => CommonElement\OptionalPropertySelect::class,
                'options' => [
                    'element_group' => 'metadata_display',
                    'label' => 'Properties for relation between person and concept', // @translate
                    'term_as_value' => true,
                    'prepend_value_options' => [
                        'all' => 'All properties', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'scanr_properties_hasConcept',
                    'multiple' => true,
                    'class' => 'chosen-select',
                    'data-placeholder' => 'Select properties…', // @translate
                    'required' => true
                ],
            ])
        ;

    }
}
