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
                'name' => 'scanr_claude_api_key',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Clé API Claude (Anthropic)',
                    'info'  => 'Clé API Anthropic pour l\'agent expert en pilotage de la science (évaluation des convergences EUR). Obtenir une clé sur https://console.anthropic.com',
                ],
                'attributes' => [
                    'id'       => 'scanr_claude_api_key',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'scanr_claude_model',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Modèle Claude',
                    'info'  => 'Identifiant du modèle Claude à utiliser (par défaut : claude-haiku-4-5-20251001)',
                ],
                'attributes' => [
                    'id'          => 'scanr_claude_model',
                    'required'    => false,
                    'placeholder' => 'claude-haiku-4-5-20251001',
                ],
            ])
            ->add([
                'name' => 'scanr_albert_api_key',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Clé API Albert (DINUM)',
                    'info'  => 'Clé API pour l\'agent expert en pilotage de la science (évaluation des convergences EUR). Obtenir une clé sur https://albert.sites.beta.gouv.fr/access/',
                ],
                'attributes' => [
                    'id'       => 'scanr_albert_api_key',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'scanr_albert_model',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Modèle Albert',
                    'info'  => 'Identifiant du modèle Albert à utiliser (modèle disponible : https://doc.incubateur.net/alliance/albert-api/modeles/available-models)',
                ],
                'attributes' => [
                    'id'          => 'scanr_albert_model',
                    'required'    => false,
                    'placeholder' => 'openai/gpt-oss-120b',
                ],
            ])
            ->add([
                'name' => 'scanr_orcid_client_id',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'ORCID - Client ID',
                    'info'  => 'Identifiant client public API ORCID, obtenu en enregistrant une application sur https://orcid.org (Developer Tools > Register for ORCID Public API Credentials).',
                ],
                'attributes' => [
                    'id'       => 'scanr_orcid_client_id',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'scanr_orcid_client_secret',
                'type' => Element\Password::class,
                'options' => [
                    'label' => 'ORCID - Client Secret',
                    'info'  => 'Secret associé au client ID ORCID, utilisé pour obtenir un jeton d\'accès (OAuth2 client_credentials).',
                ],
                'attributes' => [
                    'id'       => 'scanr_orcid_client_secret',
                    'required' => false,
                ],
            ])
            ->add([
                'name' => 'scanr_orcid_redirect_uri',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'ORCID - Redirect URI',
                    'info'  => 'URL de redirection déclarée lors de l\'enregistrement de l\'application ORCID. Non utilisée par ce module (recherche via OAuth2 client_credentials, sans redirection utilisateur), conservée ici pour mémoire lors du renouvellement des identifiants.',
                ],
                'attributes' => [
                    'id'          => 'scanr_orcid_redirect_uri',
                    'required'    => false,
                    'placeholder' => 'https://mon-site.example.org/',
                ],
            ])
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
