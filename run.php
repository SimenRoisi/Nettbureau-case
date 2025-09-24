<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/pipedrive_lead_integration.php';

use Dotenv\Dotenv;

// 1) Last .env-variabler fra prosjektroten.
$dotenv = Dotenv::createImmutable(__DIR__); // Peker på mappen der .env ligger
$dotenv->load(); // oppstår feil hvis .env mangler

// 2) Hent konfig fra .env
$apiToken = $_ENV['PIPEDRIVE_API_TOKEN'] ?? null;
$baseUrl  = $_ENV['PIPEDRIVE_BASE_URL']  ?? null;

// 3) Verifiser at påkrevde variabler finnes
if (!$apiToken || !$baseUrl) {
    throw new \RuntimeException('Mangler PIPEDRIVE_API_TOKEN eller PIPEDRIVE_BASE_URL i .env');
}

$integration = new PipedriveLeadIntegration($baseUrl, $apiToken);

// Les testdata
$data = json_decode(file_get_contents(__DIR__ . '/test/test_data.json'), true, 512, JSON_THROW_ON_ERROR);
$result = $integration->createFromArray($data);

echo sprintf(
    "OK: lead=%s person=%d org=%d (%s, %s, orgName=%s)\n",
    $result['lead_id'],
    $result['person_id'],
    $result['organization_id'],
    $data['name']  ?? 'N/A',
    $data['email'] ?? 'N/A',
    $data['organization_name'] ?? $data['name']
);
?>
