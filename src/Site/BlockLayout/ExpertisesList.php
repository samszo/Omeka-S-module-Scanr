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
            'query' => '',
            'heading' => '',
        ];
        $data = $block ? $block->data() + $defaults : $defaults;

        $form = new Form();

        $form->add([
            'type' => OmekaElement\Query::class,
            'name' => 'o:block[__blockIndex__][o:data][query]',
            'options' => [
                'label' => 'Requête pour récupérer les expertises', // @translate
                'info' => 'Définir la requête utilisée pour lister les personnes dont les expertises pourront être affichées', // @translate
                'query_resource_type' => 'items',
                'query_partial_excludelist' => [
                    'common/advanced-search/site',
                    'common/advanced-search/sort',
                ],
                'query_preview_append_query' => ['site_id' => $site->id()],
            ],
            'attributes' => [
                'value' => $data['query'],
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
        $heading = trim($data['heading'] ?? '');

        $services = $view->site->getServiceLocator();
        /*
        $settings = $services->get('Omeka\Settings');
        $classPerson = $settings->get('scanr_class_person')[0];
        $classItem = $item->resourceClass()->term();
        */
        //vérifie si l'utilisateur est autorisé à voir le block
        $auth = $services->get('Omeka\AuthenticationService');
        $user =  $auth->getIdentity();
        if(!$user){
            return $view->partial('scanr/site/block-layout/expertises-list', [
                'heading' => $heading ?: 'Mots-clefs & expertises',
                'allowed' => false, 
                'classConceptId'=> -1  
            ]);            
        }

        // 1. Récupérer toutes les données soumises par le formulaire (souvent en GET)
        // L'équivalent sécurisé de $_GET dans Laminas
        parse_str($data['query'] ?? '', $queryParams);
        

        // 2. Préparer le tableau de paramètres pour l'API Omeka
        //renvoie beaucoup d'items pour limenter l'autocomplétion 
        //$queryParams['per_page'] = $queryParams['per_page'] ?? 1000;
        
        // 3. Exécuter la requête API avec ces paramètres
        try {
            $response = $view->api()->search('items', $queryParams);
            $items = $response->getContent();
            $totalCount = $response->getTotalResults();
        } catch (\Exception $e) {
            // Gérer l'erreur si la requête est malformée
            $items = [];
            $totalCount = 0;
            throw new \Exception('Error querying : ' . $e->getMessage());
        }

        $settings = $services->get('Omeka\Settings');
        $classConcept = $settings->get('scanr_class_concept')[0];
        $api = $services->get('Scanr\ApiClient');
        $rc = $api->getRc($classConcept);

        // 4. Envoyer les résultats à la vue (.phtml)
        return $view->partial('scanr/site/block-layout/expertises-list', [
            'heading' => $heading ?: 'Mots-clefs & expertises',
            'items' => $items,
            'totalCount' => $totalCount,
            'query' => $queryParams,
            'allowed' => true,
            'classConceptId'=> $rc->id()  
        ]);
    }
}
