# Nettbureau – Pipedrive lead-integrasjon (case)

PHP-integrasjon som:
1. Oppretter **Organisasjon**  
2. Oppretter **Person** og knytter til organisasjon  
3. Oppretter **Lead** og knytter til person og organisasjon  
4. Oppdaterer **custom fields** på lead (`housing_type`, `property_size`, `deal_type`, `comment`)  
5. Verifiserer resultatet med et GET-kall (`fetchLead`)  
6. Logger hendelser til `logs/integration.log` (INFO/ERROR)

---

## Krav til miljø
- PHP 8.1+ med cURL
- Composer (for å hente inn `vlucas/phpdotenv`)

---

## Oppsett

1. **Installer avhengigheter**
   ```bash
   composer install
2. Konfigurer miljøvariabler  
Kopier eksempel-filen .env.example til .env:
PIPEDRIVE_API_TOKEN=din_api_nokkel
PIPEDRIVE_BASE_URL=https://nettbureaucase.pipedrive.com
3. Loggmappe  
Integrasjonen skriver til logs/integration.log.
En tom mappe logs/ er lagt med i repoet.

Kjøring
Standard kjøring med testdata:
```bash
php run.php
```
Scriptet leser inn test/test_data.json, oppretter organisasjon, person og lead i Pipedrive, og skriver ut output til terminalen:
```
OK: lead=<uuid> person=<id> org=<id> (Navn, E-post)
```

Feilhåndtering
Hvis .env mangler eller API-token ikke er satt, stopper programmet med feilmelding.
Output viser alltid om kjøringen var vellykket (OK) eller feilet (ERROR).
Testfilen forventes å inneholde ett lead-objekt
