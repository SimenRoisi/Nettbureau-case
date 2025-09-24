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
- API-nøkkel: `your_api_token_here`


Kjøring, bruker test/test_data.json
```bash
php run.php
```
For å teste egne data, endre run.php eller pek til en annen json fil
