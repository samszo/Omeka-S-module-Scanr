<?php
namespace Scanr\Service;

use Omeka\Settings\Settings;
use Laminas\Http\Client as HttpClient;

/**
 * Client pour l'API publique ORCID.
 * Utilisé en fallback lorsqu'une personne n'est pas trouvée dans scanR.
 * Documentation : https://info.orcid.org/documentation/api-tutorials/api-tutorial-searching-the-orcid-registry/
 */
class OrcidClient extends MainClient
{
    protected const API_URL = 'https://pub.orcid.org/v3.0';

    /** @var HttpClient */
    protected $httpClient;

    public function __construct(Settings $settings, $api, $logger, $connection, $entityManager, HttpClient $httpClient)
    {
        $this->initFromSettings($settings);

        $this->apiOmk        = $api;
        $this->logger        = $logger;
        $this->connection    = $connection;
        $this->entityManager = $entityManager;
        $this->httpClient    = $httpClient;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface publique
    // ──────────────────────────────────────────────────────────────────────────

    public function testConnection(): bool
    {
        try {
            $this->httpClient->resetParameters();
            $this->httpClient->setUri(self::API_URL . '/search?q=orcid&rows=1');
            $this->httpClient->setHeaders(['Accept' => 'application/json']);
            $response = $this->httpClient->send();
            return $response->isSuccess();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Recherche des personnes dans l'API publique ORCID.
     *
     * @param string $query  Nom complet ou partiel (ex : "John Doe")
     * @param int    $page   Offset (0-based)
     * @param int    $size   Nombre de résultats
     */
    public function searchPersons(string $query, int $page = 0, int $size = 3): array
    {
        // Construit la requête ORCID (recherche dans tous les champs texte)
        $luceneQuery = $this->buildLuceneQuery($query);

        $uri = self::API_URL . '/search?q=' . urlencode($luceneQuery)
            . '&start=' . $page
            . '&rows=' . $size;

        $this->logger->info('ORCID search: {uri}', ['uri' => $uri, 'referenceId' => 'OrcidClient']);

        try {
            $this->httpClient->resetParameters();
            $this->httpClient->setUri($uri);
            $this->httpClient->setHeaders(['Accept' => 'application/json']);
            $response = $this->httpClient->send();

            if (!$response->isSuccess()) {
                throw new \Exception('ORCID API error ' . $response->getStatusCode());
            }
//"{"response-code":500,"developer-message":"org.apache.solr.client.solrj.impl.HttpSolrClient.RemoteSolrException Full validation error: Error from server at http://localhost:7983/solr/profile: org.apache.solr.search.SyntaxError: Cannot parse 'given-names:Andreas+AND+family-name:Giannakoulopoulos': Encountered \" \":\" \": \"\" at line 1, column 35.\nWas expecting one of:\n    <EOF> \n    <AND> ...\n    <OR> ...\n    <NOT> ...\n    \"+\" ...\n    \"-\" ...\n    <BAREOPER> ...\n    \"(\" ...\n    \"*\" ...\n    \"^\" ...\n    <QUOTED> ...\n    <TERM> ...\n    <FUZZY_SLOP> ...\n    <PREFIXTERM> ...\n    <WILDTERM> ...\n    <REGEXPTERM> ...\n    \"[\" ...\n    \"{\" ...\n    <LPARAMS> ...\n    \"filter(\" ...\n    <NUMBER> ...","user-message":"Something went wrong in ORCID.","error-code":9008,"more-info":"https://members.orcid.org/api/resources/troubleshooting"}"

            $data  = json_decode($response->getBody(), true);
            $total = (int) ($data['num-found'] ?? 0);
            $hits  = [];

            foreach ($data['result'] ?? [] as $result) {
                $orcid = $result['orcid-identifier']['path'] ?? null;
                if (!$orcid) {
                    continue;
                }
                try {
                    $person = $this->fetchPerson($orcid);
                    $hits[] = $person;
                } catch (\Exception $e) {
                    $this->logger->warn('ORCID fetch failed for ' . $orcid . ': ' . $e->getMessage());
                }
            }

            return ['total' => $total, 'hits' => $hits];
        } catch (\Exception $e) {
            throw new \Exception('Error querying ORCID API: ' . $e->getMessage());
        }
    }

    /**
     * Récupère une personne par son identifiant ORCID.
     *
     * @param string $orcid  Identifiant ORCID (ex : 0000-0001-2345-6789)
     */
    public function getPersonById(string $orcid): array
    {
        return $this->fetchPerson($orcid);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers internes
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Récupère et formate un enregistrement ORCID complet.
     */
    protected function fetchPerson(string $orcid): array
    {
        $this->httpClient->resetParameters();
        $this->httpClient->setUri(self::API_URL . '/' . $orcid . '/person');
        $this->httpClient->setHeaders(['Accept' => 'application/json']);
        $response = $this->httpClient->send();

        if (!$response->isSuccess()) {
            throw new \Exception('ORCID person not found: ' . $orcid);
        }

        $data = json_decode($response->getBody(), true);

        return $this->formatPersonFromOrcid($orcid, $data);
    }

    /**
     * Convertit la réponse ORCID /person en structure canonique.
     */
    protected function formatPersonFromOrcid(string $orcid, array $data): array
    {
        $name      = $data['name'] ?? [];
        $firstName = $name['given-names']['value']  ?? '';
        $lastName  = $name['family-name']['value']  ?? '';
        $fullName  = trim($firstName . ' ' . $lastName);

        // Identifiants externes (DOI, ResearcherID, Scopus…)
        $externalIds = [];
        foreach ($data['external-identifiers']['external-identifier'] ?? [] as $ext) {
            $type = $ext['external-id-type'] ?? '';
            $id   = $ext['external-id-value'] ?? '';
            $url  = $ext['external-id-url']['value'] ?? '';
            $externalIds[] = ['type' => $type, 'id' => $id, 'url' => $url ?: '#'];
        }

        // On ajoute l'ORCID lui-même comme identifiant externe
        $externalIds[] = [
            'type' => 'orcid',
            'id'   => $orcid,
            'url'  => 'https://orcid.org/' . $orcid,
        ];

        // Mots-clés de recherche en tant que domaines
        $domains = [];
        foreach ($data['keywords']['keyword'] ?? [] as $kw) {
            $label = $kw['content'] ?? '';
            if ($label) {
                $domains[] = ['label' => ['default' => $label], 'count' => 1, 'type' => 'keyword'];
            }
        }

        // Affiliations (depuis le champ employments, non disponible sur /person)
        // Sera enrichi via /employments si nécessaire.

        $source = [
            'id'           => 'orcid:' . $orcid,
            'firstName'    => $firstName,
            'lastName'     => $lastName,
            'fullName'     => $fullName,
            'domains'      => $domains,
            'top_domains'  => $domains,
            'externalIds'  => $externalIds,
            'coContributors' => [],
            'affiliations' => [],
            'awards'       => [],
            'publications' => [],
        ];

        return parent::formatPerson($source, 0.0);
    }

    /**
     * Construit une requête Lucene pour ORCID à partir d'un nom.
     * Si la chaîne contient un espace, on divise en prénom / nom de famille.
     */
    protected function buildLuceneQuery(string $query): string
    {
        $parts = preg_split('/\s+/', trim($query), 2);

        if (count($parts) === 2) {
            return 'given-names:' . $parts[0] . '+AND+family-name:' . $parts[1];
        }

        // Recherche large sur tous les champs
        return $query;
    }
}
