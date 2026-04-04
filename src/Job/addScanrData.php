<?php declare(strict_types=1);

namespace Scanr\Job;

use Omeka\Job\AbstractJob;
use Omeka\Stdlib\Message;

class addScanrData extends AbstractJob
{
    /**
     * Limit for the loop to avoid heavy sql requests.
     *
     * @var int
     */
    const BULK_LIMIT = 100;

    public function perform(): void
    {
        /**
         * @var \Laminas\Log\Logger $logger
         * @var \Omeka\Api\Manager $api
         * @var \Doctrine\ORM\EntityManager $entityManager
         */
        $services = $this->getServiceLocator();
        $logger = $services->get('Omeka\Logger');
        $api = $services->get('Omeka\ApiManager');
        $entityManager = $services->get('Omeka\EntityManager');
        $ids = $this->getArg('ids');

        $logger->info(new Message(
            'Start of the job : for %1$d items.', // @translate
            count($ids)
        ));

        // Check existence and rights.
        $itemIds = $api->search('items', ['id' => $ids], ['returnScalar' => 'id', 'per_page' => 10000])->getContent();
        if (count($itemIds) < count($ids)) {
            $logger->warn(new Message(
                'These items are not available: #%s', // @translate
                implode(', #', array_diff($ids, $itemIds))
            ));
        }

        if (!$itemIds) {
            return;
        }

        $totalToProcess = count($itemIds);
        
        $logger->info(new Message(
            'Processing %d resources.', // @translate
            $totalToProcess
        ));

        $scanR = $this->setRequester($services);

        //test la connexion
        $connect = $scanR->testConnection();

        $process = 0;

        if(!$connect){
            $logger->warn(new Message('Unable to connect to scanR. Please check your configuration.'));
        }else{

            foreach (array_chunk($itemIds, self::BULK_LIMIT) as $listItemIds) {
                    /** @var \Omeka\Api\Representation\AbstractRepresentation[] $resources */
                $resources = $api
                    ->search('items', [
                        'id' => $listItemIds,
                    ])
                    ->getContent();
                if (empty($resources)) {
                    continue;
                }

                foreach ($resources as $resource) {
                    if ($this->shouldStop()) {
                        $logger->warn(new Message(
                            'The job "%s" was stopped.', // @translate
                            'scanR'
                        ));
                        break 2;
                    }

                    try {
                        $result = $scanR->searchPersons($resource->displayTitle(),0,1);
                    } catch (\Exception $e) {
                        $result = false;
                        $logger->warn(new Message(
                            'Personne non trouvée : %s', // @translate
                            $resource->displayTitle()." ".$e->getMessage()
                        ));
                    }
                
                    if ($result) {
                        $personData = $result['hits'][0];

                        // Créer un item Omeka S avec les données de la personne
                        $personData["items"][]=$resource;
                        $itemData = $scanR->mapPersonToItem($personData);                        
                        $response = $api->update('items',$resource->id(),$itemData,[], ['continueOnError' => true,'isPartial' => 1, 'collectionAction' => 'replace']);
                        $logger->info(
                            '{num} - {person} #{resource_id} has been updated by user #{userId}.', // @translate
                            ['num' => $process, 'person' => $resource->displayTitle(), 'resource_id' => $resource->id(), 'referenceId' => 'Scanr']
                        );
                        unset($itemData);                    
                        unset($personData);                    
                    }

                    ++$process;

                    if (!empty($result['error'])) {
                        $logger->warn(new Message(
                            'Item #%1$s: An error occurred: %2$s', // @translate
                            $resource->id(), $result['message']
                        ));
                    }

                    // Avoid memory issue.
                    unset($resource);
                }

                // Avoid memory issue.
                unset($resources);
                $entityManager->clear();
            }
        }

        $logger->info(new Message(
            'End of the job: %1$d/%2$d processed.', // @translate
            $process, $totalToProcess
        ));
    }

    private function setRequester($services){

        $sqlClient = $services->get('Scanr\SqlClient');    
        if ($sqlClient->testConnection()) {
            // Table SQL disponible → recherche rapide
            return $sqlClient;
        }else{
            $apiClient = $services->get('Scanr\ApiClient');    
            if ($apiClient->testConnection()) {
                // requête sur l'API scanr = la plus à jour mais pas toujours disponible
                return $apiClient;
            } else {
                // Fallback pur PHP (lent sur de grands fichiers)
                return $services->get('Scanr\JsonlClient');    
            }
        }

    }

}
