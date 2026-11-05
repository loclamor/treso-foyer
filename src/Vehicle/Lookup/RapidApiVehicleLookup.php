<?php

declare(strict_types=1);

namespace App\Vehicle\Lookup;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Recherche de véhicule via « API Plaque Immatriculation France » sur RapidAPI :
 * GET https://api-de-plaque-d-immatriculation-france.p.rapidapi.com/?plaque={PLAQUE}
 * avec les en-têtes x-rapidapi-host et x-rapidapi-key.
 *
 * Le format exact de la réponse n'étant pas documenté, le mappage est défensif :
 * clés usuelles recherchées à plat ou sous « data », insensible à la casse.
 * La plaque et la clé API ne sont jamais journalisées.
 */
final class RapidApiVehicleLookup implements VehicleLookup
{
    private const BASE_URL = 'https://api-de-plaque-d-immatriculation-france.p.rapidapi.com/';
    private const DEFAULT_HOST = 'api-de-plaque-d-immatriculation-france.p.rapidapi.com';
    private const TIMEOUT_SECONDS = 10;

    public function __construct(
        private readonly HttpClientInterface $client,
        #[Autowire('%env(VEHICLE_LOOKUP_TOKEN)%')]
        private readonly string $apiKey,
        private readonly string $host = self::DEFAULT_HOST,
    ) {
    }

    public function isEnabled(): bool
    {
        return '' !== trim($this->apiKey);
    }

    public function lookup(string $normalizedPlate): VehicleLookupResult
    {
        try {
            $response = $this->client->request('GET', self::BASE_URL, [
                'query' => ['plaque' => $normalizedPlate],
                'headers' => [
                    'x-rapidapi-host' => $this->host,
                    'x-rapidapi-key' => $this->apiKey,
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]);
            $status = $response->getStatusCode();
            // getContent(false) : ne lève pas sur les codes 4xx/5xx, on les traite nous-mêmes.
            $body = $response->getContent(false);
        } catch (TransportExceptionInterface) {
            throw new VehicleLookupException(
                'Le service de recherche par plaque est indisponible.',
                VehicleLookupException::REASON_UNAVAILABLE,
            );
        }

        if (404 === $status) {
            throw new VehicleLookupException(
                'Aucun véhicule trouvé pour cette plaque.',
                VehicleLookupException::REASON_NOT_FOUND,
            );
        }
        if (429 === $status) {
            throw new VehicleLookupException(
                'Le quota de l’API de recherche par plaque est atteint.',
                VehicleLookupException::REASON_QUOTA,
            );
        }
        if ($status >= 400) {
            // 401, 403, 5xx et autres erreurs : service indisponible côté fournisseur.
            throw new VehicleLookupException(
                'Le service de recherche par plaque est indisponible.',
                VehicleLookupException::REASON_UNAVAILABLE,
            );
        }
        if ('' === trim($body)) {
            throw new VehicleLookupException(
                'Aucun véhicule trouvé pour cette plaque.',
                VehicleLookupException::REASON_NOT_FOUND,
            );
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new VehicleLookupException(
                'La réponse du service de recherche par plaque est illisible.',
                VehicleLookupException::REASON_INVALID_RESPONSE,
            );
        }

        // Le fournisseur peut imbriquer les champs sous « data ».
        $nested = $data['data'] ?? $data['Data'] ?? null;
        $sources = is_array($nested) ? [$data, $nested] : [$data];

        $energy = $this->findString($sources, ['energie', 'energy', 'carburant', 'fuel', 'motorisation']);
        $registration = $this->findString($sources, [
            'date1ercir', 'datepremiereimmatriculation', 'firstregistration',
            'datemiseencirculation', 'date1eremiseencirculation', 'dateimmatriculation',
        ]);

        return new VehicleLookupResult(
            make: $this->findString($sources, ['marque', 'make', 'brand', 'constructeur']),
            model: $this->findString($sources, ['modele', 'model', 'gamme']),
            version: $this->findString($sources, ['version', 'trim', 'finition', 'designation']),
            energy: null === $energy ? null : $this->mapEnergy($energy),
            fiscalPower: $this->parseFiscalPower($this->findString($sources, [
                // Pas de clé « puissance » seule : souvent la puissance réelle (kW/ch), pas les CV fiscaux.
                'puissancefiscale', 'cv', 'fiscalpower', 'chevauxfiscaux',
            ])),
            firstRegistrationAt: null === $registration ? null : $this->parseDate($registration),
            vin: $this->findString($sources, ['vin', 'numserie', 'serialnumber', 'numerodeserie']),
            raw: $data,
        );
    }

    /**
     * Recherche la première valeur scalaire non vide parmi les clés candidates,
     * en parcourant chaque source (à plat puis sous « data »). Les clés sont
     * comparées en minuscules, sans accents ni caractères spéciaux.
     *
     * @param array<int, array> $sources
     * @param array<int, string> $candidates clés déjà normalisées (minuscules, alphanumérique)
     */
    private function findString(array $sources, array $candidates): ?string
    {
        foreach ($sources as $source) {
            $normalized = [];
            foreach ($source as $key => $value) {
                if (is_string($key)) {
                    $normalized[$this->normalizeKey($key)] = $value;
                }
            }
            foreach ($candidates as $candidate) {
                $value = $normalized[$candidate] ?? null;
                if (is_scalar($value) && '' !== trim((string) $value)) {
                    return trim((string) $value);
                }
            }
        }

        return null;
    }

    /** Minuscules, sans accents, uniquement les caractères alphanumériques. */
    private function normalizeKey(string $key): string
    {
        $lower = mb_strtolower($key, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $lower);
        if (false === $ascii) {
            $ascii = $lower;
        }

        return preg_replace('/[^a-z0-9]/', '', $ascii) ?? '';
    }

    /**
     * Mappe l'énergie libre du fournisseur vers petrol|diesel|electric|hybrid|
     * plugin_hybrid|lpg|other. « Hybride rechargeable » est testé avant « hybride ».
     */
    private function mapEnergy(string $energy): string
    {
        $normalized = mb_strtoupper($energy, 'UTF-8');
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $normalized);
        if (false !== $ascii) {
            $normalized = $ascii;
        }
        $compact = preg_replace('/[^A-Z0-9]/', '', $normalized) ?? $normalized;

        if (str_contains($compact, 'HYBRIDERECHARGEABLE') || str_contains($compact, 'PLUGIN') || str_contains($compact, 'PHEV')) {
            return 'plugin_hybrid';
        }
        if (str_contains($compact, 'HYBRID') || 'HEV' === $compact) {
            return 'hybrid';
        }
        if (str_contains($compact, 'ELECTRIQUE') || str_contains($compact, 'ELECTRIC') || in_array($compact, ['EV', 'BEV'], true)) {
            return 'electric';
        }
        if (str_contains($compact, 'GAZOLE') || str_contains($compact, 'DIESEL')) {
            return 'diesel';
        }
        if (str_contains($compact, 'ESSENCE') || str_contains($compact, 'SP95') || str_contains($compact, 'SP98') || str_contains($compact, 'E85') || str_contains($compact, 'SUPERETHANOL')) {
            return 'petrol';
        }
        if (str_contains($compact, 'GPL') || str_contains($compact, 'LPG')) {
            return 'lpg';
        }

        return 'other';
    }

    /** Extrait la puissance fiscale (CV) : « 6 CV », « 6 » ou 6 → 6. */
    private function parseFiscalPower(?string $raw): ?int
    {
        if (null === $raw) {
            return null;
        }
        if (preg_match('/\d+/', $raw, $m) === 1) {
            return (int) $m[0];
        }

        return null;
    }

    /** Formats acceptés : JJ-MM-AAAA, JJ/MM/AAAA, AAAA-MM-JJ. */
    private function parseDate(string $raw): ?\DateTimeImmutable
    {
        foreach (['!d-m-Y', '!d/m/Y', '!Y-m-d'] as $format) {
            $date = \DateTimeImmutable::createFromFormat($format, trim($raw));
            if (false !== $date) {
                return $date;
            }
        }

        return null;
    }
}
