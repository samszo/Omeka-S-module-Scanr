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

        /* Vérification amont des droits.
        if (!$ids) {
            $logger->warn(
                'No item set to Transformers pipeline.' // @translate
            );
            return;
        }
        */

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
        $process = 0;

        $logger->info(new Message(
            'Processing %d resources.', // @translate
            $totalToProcess
        ));

        $scanR = $services->get('Scanr\ApiClient');

        //récupère le workspace pour avoir la liste des documents
        $connect = $scanR->testConnection();

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
                        $logger->error(new Message(
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
                            '{num} - {person} #{id} has been updated by user #{userId}.', // @translate
                            ['num' => $process, 'person' => $resource->displayTitle(), 'id' => $resource->id(), 'referenceId' => 'Scanr']
                        );

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
            $totalProcessed, $totalToProcess
        ));
    }
}
