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
    protected const API_URL   = 'https://pub.orcid.org/v3.0';
    protected const TOKEN_URL = 'https://orcid.org/oauth/token';



    /** @var HttpClient */
    protected $httpClient;

    /** @var string */
    protected $clientId;

    /** @var string */
    protected $clientSecret;

    /** @var string|null  Jeton d'accès en cache pour la durée de vie du client */
    protected $accessToken;

    /** @var int|null  Timestamp d'expiration du jeton en cache */
    protected $accessTokenExpiresAt;

    public function __construct(Settings $settings, $api, $logger, $connection, $entityManager, HttpClient $httpClient)
    {
        $this->initFromSettings($settings);

        $this->apiOmk        = $api;
        $this->logger        = $logger;
        $this->connection    = $connection;
        $this->entityManager = $entityManager;
        $this->httpClient    = $httpClient;
        $this->clientId      = $settings->get('scanr_orcid_client_id', '');
        $this->clientSecret  = $settings->get('scanr_orcid_client_secret', '');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Interface publique
    // ──────────────────────────────────────────────────────────────────────────

    public function testConnection(): bool
    {
        try {
            $this->httpClient->resetParameters();
            $this->httpClient->setUri(self::API_URL . '/search?q=orcid&rows=1');
            $this->httpClient->setHeaders($this->authHeaders());
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

        $uri = self::API_URL . '/search?q=' . $luceneQuery//problème avec urlencode($luceneQuery)
            . '&start=' . $page
            . '&rows=' . $size;
        $this->logger->info('ORCID search: {uri}', ['uri' => $uri, 'referenceId' => 'OrcidClient']);

        try {
            $token = $this->getAccessToken();
            $cmd = "curl -H 'Accept: application/vnd.orcid+json' -H 'Authorization: Bearer ".$token."' '".$uri."'";
            exec($cmd, $output, $retval);
            $data = json_decode(implode(' ',$output),true);
            /*PROBbleme avec httpClient 
            $this->httpClient->resetParameters();
            $this->httpClient->setUri($uri);
            $this->httpClient->setHeaders($this->authHeaders());
            $response = $this->httpClient->send();            
            if (!$response->isSuccess()) {
                throw new \Exception('ORCID API error ' . $response->getStatusCode());
            }
            $data  = json_decode($response->getBody(), true);
            */
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
        $this->httpClient->setHeaders($this->authHeaders());
        $response = $this->httpClient->send();

        if (!$response->isSuccess()) {
            throw new \Exception('ORCID person not found: ' . $orcid);
        }

        $data = json_decode($response->getBody(), true);

        return $this->formatPersonFromOrcid($orcid, $data);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Authentification OAuth2 (client_credentials)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * En-têtes HTTP à joindre à chaque appel à l'API publique ORCID.
     */
    protected function authHeaders(): array
    {
        return [
            'Accept'        => 'application/vnd.orcid+json',
            'Authorization' => 'Bearer ' . $this->getAccessToken(),
        ];
    }

    /**
     * Récupère un jeton d'accès via le flux OAuth2 client_credentials
     * (2-legged, sans redirection utilisateur), et le met en cache
     * pour la durée de vie de l'instance.
     *
     * Documentation : https://info.orcid.org/documentation/api-tutorials/api-tutorial-read-data-on-a-record/
     */
    protected function getAccessToken(): string
    {
        if ($this->accessToken !== null && time() < $this->accessTokenExpiresAt) {
            return $this->accessToken;
        }

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new \Exception(
                'ORCID API : identifiants client manquants. Renseignez le Client ID et le Client Secret '
                . 'dans les paramètres du module (obtenus sur https://orcid.org, Developer Tools).'
            );
        }

        $this->httpClient->resetParameters();
        $this->httpClient->setUri(self::TOKEN_URL);
        $this->httpClient->setMethod('POST');
        $this->httpClient->setHeaders(['Accept' => 'application/json']);
        $this->httpClient->setParameterPost([
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'grant_type'    => 'client_credentials',
            'scope'         => '/read-public',
        ]);
        $response = $this->httpClient->send();

        if (!$response->isSuccess()) {
            throw new \Exception('ORCID OAuth error ' . $response->getStatusCode() . ': ' . $response->getBody());
        }

        $data = json_decode($response->getBody(), true);

        if (empty($data['access_token'])) {
            throw new \Exception('ORCID OAuth: réponse invalide, access_token manquant.');
        }

        $this->accessToken = $data['access_token'];
        // Marge de sécurité de 60s pour éviter d'utiliser un jeton expiré entre la vérification et l'appel.
        $this->accessTokenExpiresAt = time() + (int) ($data['expires_in'] ?? 0) - 60;

        return $this->accessToken;
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
