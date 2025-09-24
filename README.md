# Nettbureau – Pipedrive lead-integrasjon (case)

Minimal PHP-integrasjon som:
1) oppretter **Organisasjon**,  
2) oppretter **Person** og knytter til organisasjon,  
3) oppretter **Lead** og knytter til person og organisasjon,  
4) oppdaterer **custom fields** på lead (housing_type, property_size, deal_type),  
5) verifiserer resultatet med et GET-kall (fetchLead).

## Krav til miljø
- PHP 8.1+ med cURL

## Konfigurasjon
Integrasjonen bruker domenet og API-nøkkelen fra case-teksten.
- Domene: `nettbureaucase`
- API-nøkkel: `24eaceaa89c83e18fd4aadd3dbab7a3b01ddffc8`

Disse kan også settes via miljøvariabler om ønskelig:
```bash
export PIPEDRIVE_BASE_URL="https://nettbureaucase.pipedrive.com"
export PIPEDRIVE_API_TOKEN="24eaceaa89c83e18fd4aadd3dbab7a3b01ddffc8"
```

Kjøring, bruker test/test_data.json
```bash
php run.php
```
For å teste egne data, endre run.php eller pek til en annen json fil