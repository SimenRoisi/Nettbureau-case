<?php
/*
 * Integrasjon mot Pipedrive for å:
 *  - Opprette organisasjon
 *  - Opprette person (knyttet til organisasjonen)
 *  - Opprette lead (knyttet til person og organisasjon)
 *  - Oppdatere lead med egendefinerte felter (custom fields)
 */
final class PipedriveLeadIntegration 
{
    private string $apiToken;
    private string $baseUrl;
    private string $logFile;

    // Felt-IDer (API-keys) som Pipedrive krever for custom fields
    private const PERSON_CONTACT_TYPE = 'c0b071d74d13386af76f5681194fd8cd793e6020';
    private const LEAD_HOUSING_TYPE  = '35c4e320a6dee7094535c0fe65fd9e748754a171';
    private const LEAD_PROPERTY_SIZE = '533158ca6c8a97cc1207b273d5802bd4a074f887';
    private const LEAD_COMMENT       = '1fe6a0769bd867d36c25892576862e9b423302f3';
    private const LEAD_DEAL_TYPE     = '761dd27362225e433e1011b3bd4389a48ae4a412';

    public function __construct(string $baseUrl, string $apiToken) {
        $this->baseUrl  = rtrim($baseUrl, '/');
        $this->apiToken = $apiToken;
        // sett standard loggfil
        $this->logFile = __DIR__ . '/../logs/integration.log';
    }

    /* Enkel logger – skriver til logs/integration.log */
    private function log(string $level, string $msg): void
    {
        $line = sprintf("[%s] %s: %s\n", date('c'), $level, $msg);
        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
    /**
     * Tar et komplett lead-data-array
     * og kjører hele flyten (org -> person -> lead).
     *
     * @param array $data Data fra Strøm.no (name, phone, email, housing_type, etc.)
     * @return array ['organization_id'=>..., 'person_id'=>..., 'lead_id'=>...]
     */
    public function createFromArray(array $data): array
    {
        // 1) Organization
        $orgId = $this->createOrganization($data);

        // 2) Person
        $personId = $this->createPerson($data, $orgId);

        // 3) Lead
        $leadId = $this->createLead($data, $orgId, $personId);

        return [
            'organization_id'   => (int)$orgId,
            'person_id'         => (int)$personId,
            'lead_id'           => (string)$leadId,
        ];
    }

    /*
     * Oppretter en organisasjon (v1).
     *
     * @param string $name Organisasjonsnavn
     * @return int ID-en til organisasjonen i Pipedrive
     */
    private function createOrganization(array $data): int
    {
        // 1) Finn et organisasjonsnavn
        $name = trim((string)($data['organization_name'] ?? $data['name'] ?? ''));
        if ($name === '') {
            $this->log('ERROR', 'Mangler organisasjonsnavn (organization_name eller name)');
            throw new \InvalidArgumentException('Mangler organisasjonsnavn (organization_name eller name).');
        }

        // 2) Bygg URL for Pipedrive v1 API
        $url = $this->baseUrl . '/api/v1/organizations?api_token=' . urlencode($this->apiToken);

        // 3) Minste payload: "name" er eneste påkrevde felt
        $payload = ['name' => $name];

        // 4) POST-request med cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        // Utfør request
        $raw = curl_exec($ch);
        if ($raw == false) {
            // Nettverks- eller cURL-feil
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL feil ved opprettelse av organisasjon: ' . $err);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Dekod JSON-respons
        $resp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        // Sjekk HTTP-status (må være 2xx)
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $resp['error'] ?? $resp['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('Pipedrive API-feil ved opprettelse av organisasjon: ' . $message);
        }

        // Hent ut ID-en til organisasjonen
        $id = $resp['data']['id'] ?? null;

        if ($id === null) {
            throw new \RuntimeException(
                'Uventet respons ved opprettelse av organisasjon - fant ingen id. Utdrag: ' . substr($raw, 0, 500)
            );
        }
        // loggføring
        $this->log('INFO', "Opprettet organisasjon '{$name}' med id={$id}");
        return (int)$id;
    }

    /**
     * Oppretter en person (v2) og knytter til en organisasjon.
     * - Setter navn, e-post og telefonnummer.
     * - Setter egendefinert felt contact_type hvis oppgitt.
     *
     * @param array $data Lead-data (fra Strøm.no)
     * @param int $orgId ID på organisasjonen personen skal knyttes til
     * @return int Person-ID fra Pipedrive
     */
    private function createPerson(array $data, int $orgId): int
    {
        // 1) Valider påkrevde felt
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            $this->log('ERROR', 'Mangler personnavn (name)');
            throw new \InvalidArgumentException('Mangler personnavn (name).');
        }

        // 2) Hent standardfelter (e-post og telefon) hvis de finnes
        $email = isset($data['email']) ? trim((string)$data['email']) : null;
        $phone = isset($data['phone']) ? trim((string)$data['phone']) : null;

        // 3) Bygg payload for PERSON v2 
        //   - Påkrevde felter: name, org_id
        //   - Tillegg: emails[], phones[], custom_fields
        $payload = [
            'name'      => $name,
            'org_id'    => $orgId,
        ];

        // Legg til e-post hvis oppgitt
        if ($email !== null && $email !== '') {
            $payload['emails'] = [[
                'value'     => $email,
                'label'     => 'work',
                'primary'   => true,
            ]];
        }

        // Legg til telefon hvis oppgitt
        if ($phone !== null && $phone !== '') {
            $payload['phones'] = [[
                'value'     => $phone,
                'label'     => 'work',
                'primary'   => true,
            ]];
        }
        
        // Mapper contact_type (Privat, Borettslag, Bedrift) til riktig option-ID
        $contactTypeId = $this->mapContactType($data['contact_type'] ?? null);
        if ($contactTypeId !== null) {
            $payload['custom_fields'][self::PERSON_CONTACT_TYPE] = $contactTypeId;
        }

        // 4) Utfør POST mot /api/v2/persons
        $url = $this->baseUrl . '/api/v2/persons?api_token=' . urlencode($this->apiToken);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $raw = curl_exec($ch);

        // Sjekk for cURL-feil (nettverk, timeout etc.)
        if ($raw == false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL-feil ved opprettelse av person: ' . $err);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Dekod JSON-respons
        $resp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $resp['error'] ?? $resp['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('Pipedrive API-feil ved opprettelse av person. ' . $message);
        }

        // Hent ut person-ID fra responsen
        $id = $resp['data']['id'] ?? null;
        if (!is_int($id)) {
            throw new \RuntimeException('Uventet respons: mangler person id.');
        }

        // loggføring
        $this->log('INFO', "Opprettet person '{$name}' med id={$id}, org_id={$orgId}");
        return $id;    
    }

    /**
     * Oppretter en lead (v1).
     * - Knyttes til org og person
     * - Oppdateres etterpå med custom fields (PATCH)
     *
     * @param array $data Lead-data
     * @param int $orgId Organisasjons-ID
     * @param int $personId Person-ID
     * @return string Lead-ID fra Pipedrive
     */
    private function createLead(array $data, int $orgId, int $personId): string
    {
        // 1) Sett tittel: først prøv data['title'], ellers navn/org, ellers fallback
        $fallbackName = trim((string)($data['name'] ?? $data['organization_name'] ?? ''));
        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            $title = $fallbackName !== '' ? "Lead: {$fallbackName}" : 'Lead fra integrasjon';
        }

        // 2) Bygg payload for POST-kall (ingen custom fields settes her)
        $payload = [
            'title'             => $title,
            'organization_id'   => $orgId,
            'person_id'         => $personId,
        ];

        $url = $this->baseUrl . '/api/v1/leads?api_token=' . urlencode($this->apiToken);

        // 3) POST request med cURL
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL-feil ved opprettelse av lead: ' . $err);
        }
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 4) Dekod JSON-respons og sjekk HTTP-statuskode
        $resp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $resp['error'] ?? $resp['message'] ?? ('HTTP ' . $httpCode);
            throw new \RuntimeException('Pipedrive API-feil ved opprettelse av lead: ' . $message);
        }

        // 5) Validerer at vi fikk tilbake en gyldig lead-ID
        $leadId = $resp['data']['id'] ?? null;
        if (!is_string($leadId) || $leadId === '') {
            $snippet = substr($raw, 0, 300);
            throw new \RuntimeException('Uventet respons: mangler lead id. Utdrag: ' . $snippet);
        }

        // 6) Oppdater leaden med custom fields (PATCH etter at lead er laget)
        $this->updateLeadCustomFields($leadId, $data);

        // loggføring
        $this->log('INFO', "Opprettet lead '{$title}' med id={$leadId}, org_id={$orgId}, person_id={$personId}");
        return $leadId;

    }

    /**
     * Oppdaterer custom fields på en eksisterende lead (PATCH v1).
     * @param string $leadId: Leadens ID
     * @param array $data: Original lead-data som brukes til custom fields
     */
    private function updateLeadCustomFields(string $leadId, array $data): void
    {
        // bygg custom fields fra input + mapping-metodene
        $custom = [];

        // Housing type → mapping til riktig option-ID
        if (($v = $this->mapHousingType($data['housing_type'] ?? null)) !== null) {
            $custom[self::LEAD_HOUSING_TYPE] = $v;
        }
        // Property size → int-verdi > 0
        if (($ps = (int)($data['property_size'] ?? 0)) > 0) {
            $custom[self::LEAD_PROPERTY_SIZE] = $ps;
        }
        // Deal type → mapping til riktig option-ID
        if (($v = $this->mapDealType($data['deal_type'] ?? null)) !== null) {
            $custom[self::LEAD_DEAL_TYPE] = $v;
        }
        // Comment → kun hvis oppgitt og ikke tom
        if (($v = $this->mapComment($data['comment'] ?? null)) !== null) {
            $custom[self::LEAD_COMMENT] = $v;
        }

        // Hvis ingen custom fields å oppdatere → returner tidlig
        if (!$custom) return;

        // Bygg endpoint-URL for PATCH
        $url = $this->baseUrl . '/api/v1/leads/' . urlencode($leadId) . '?api_token=' . urlencode($this->apiToken);
        
        // JSON-encode payload
        $payload = $custom;
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);

        // Sett opp cURL for PATCH
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH'); // PATCH metode
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);

        // Utfør request
        $raw = curl_exec($ch);
        if ($raw === false) {
            // cURL-feil (nettverksfeil, timeout osv.)
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('cURL-feil ved oppdatering av lead (custom_fields): ' . $err);
        }
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Sjekk at responsen er gyldig JSON
        $trim = ltrim($raw);
        if ($raw === false || $trim === '' || ($trim[0] !== '{' && $trim[0] !== '[')) {
            $snippet = substr($trim, 0, 300);
            throw new \RuntimeException("Uventet ikke-JSON respons (HTTP $http). Utdrag: " . $snippet);
        }

        // Dekod JSON-respons
        $resp = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        // Sjekk HTTP-statuskode (må være 2xx)
        if ($http < 200 || $http >= 300) {
            $msg = $resp['error'] ?? $resp['message'] ?? ('HTTP ' . $http);
            $this->log('ERROR', "Feil ved oppdatering av lead {$leadId}: {$msg}");
            throw new \RuntimeException('Pipedrive API-feil ved oppdatering av lead custom_fields: ' . $msg);
        }

    }

    /*
     * Henter et lead fra Pipedrive (GET v1).
     *
     * @param string $leadId ID på lead som skal hentes
     * @return array Dekodet JSON-respons (inneholder lead-data)
     * @throws \RuntimeException hvis HTTP-status ikke er 200 eller JSON-dekoding feiler
     */
    private function fetchLead(string $leadId): array
    {
        // Bygg URL med lead-ID og API-token
        $url = $this->baseUrl . '/api/v1/leads/' . urlencode($leadId) . '?api_token=' . urlencode($this->apiToken);
        
        // Sett opp cURL med GET-request
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);

        // Utfør request og hent HTTP-statuskode
        $raw = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // Hvis statuskode ikke er 200 OK → feil
        if ($http !== 200) {
            throw new \RuntimeException("GET lead feilet (HTTP $http).");
        }

        // Dekod JSON til PHP-array
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
    
    // --------- Mapping-hjelpere) --------- //

    /* Mapper housing_type (string) til riktig option-ID. */
    private function mapHousingType(?string $val): ?int
    {
        // Lead-felt: housing_type (Single_Option)
        // Enebolig (30), Leilighet (31), Tomannsbolig (32), Rekkehus (33), Hytte (34), Annet (35)

        $map = [
            'enebolig'          => 30,
            'leilighet'         => 31,
            'tomannsbolig'      => 32,
            'rekkehus'          => 33,
            'hytte'             => 34,
            'annet'             => 35,
        ];
        return $val ? ($map[mb_strtolower($val)] ?? null) : null;
    }

    private function mapComment(?string $val): ?string
    {
        // Lead-felt: Comment (Text)
        if ($val == null) {
            return null;
        }
        $s = trim((string)$val);
        return $s === '' ? null : $s; // tom streng --> null

    }
    /* Mapper deal_type (string) til riktig option-ID. */
    private function mapDealType(?string $val): ?int
    {
        // Lead_felt: deal_type (Single_Option)
        // Alle strømavtaler er aktuelle (42), Fastpris (43), Spotpris (44), 
        // Kraftforvaltning (425), Annen avtale/vet ikke (46)
        $map = [
            'alle strømavtaler er aktuelle' => 42,
            'fastpris'                      => 43,
            'spotpris'                      => 44,
            'kraftforvaltning'              => 425,
            'annen avtale/vet ikke'         => 46,          
        ];
        return $val ? ($map[mb_strtolower($val)] ?? null) : null;
    }

    /* Mapper contact_type (string) til riktig option-ID */
    private function mapContactType(?string $val): ?int
    {
        // Person-felt: contact_type (Single-Option)
        // Privat (27), Borettslag (28), Bedrift (29)
        $map = [
            'privat'        => 27,
            'borettslag'    => 28,
            'bedrift'       => 29,
        ];
        return $val ? ($map[mb_strtolower($val)] ?? null) : null;
    }
}
?>