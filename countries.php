<?php
declare(strict_types=1);

header("Content-Type: application/json; charset=utf-8");

const LOCAL_COUNTRIES_FILE = __DIR__ . "/data/countries.json";
const REMOTE_COUNTRIES_URL = "https://raw.githubusercontent.com/mledoze/countries/master/countries.json";
const POPULATION_API_URL = "https://api.worldbank.org/v2/country/all/indicator/SP.POP.TOTL?format=json&date=2022:2024&per_page=10000";
const POPULATION_CACHE_FILE = __DIR__ . "/data/population.json";
const POPULATION_CACHE_TTL = 2592000;

function send_json(mixed $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit();
}

function load_country_json_from_file(string $path): ?array
{
    if (!is_readable($path)) {
        return null;
    }

    $raw = file_get_contents($path);
    if ($raw === false) {
        return null;
    }

    $data = json_decode($raw, true);
    return is_array($data) && isset($data[0]["name"]) ? $data : null;
}

function fetch_remote_json(string $url): ?array
{
    $response = false;

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => "WorldExplorerApp/1.0",
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $response = false;
        }
    }

    if ($response === false) {
        $context = stream_context_create([
            "http" => [
                "timeout" => 15,
                "header" => "User-Agent: WorldExplorerApp/1.0\r\n",
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
    }

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    return is_array($data) ? $data : null;
}

function fetch_remote_country_json(): ?array
{
    $response = false;

    if (function_exists("curl_init")) {
        $ch = curl_init(REMOTE_COUNTRIES_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT => "WorldExplorerApp/1.0",
            CURLOPT_FOLLOWLOCATION => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $httpCode < 200 || $httpCode >= 300) {
            $response = false;
        }
    }

    if ($response === false) {
        $context = stream_context_create([
            "http" => [
                "timeout" => 15,
                "header" => "User-Agent: WorldExplorerApp/1.0\r\n",
            ],
        ]);
        $response = @file_get_contents(REMOTE_COUNTRIES_URL, false, $context);
    }

    if ($response === false) {
        return null;
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data[0]["name"])) {
        return null;
    }

    $directory = dirname(LOCAL_COUNTRIES_FILE);
    if (is_dir($directory) && is_writable($directory)) {
        @file_put_contents(LOCAL_COUNTRIES_FILE, $response, LOCK_EX);
    }

    return $data;
}

function country_matches_code(array $country, string $code): bool
{
    $needle = strtolower(trim($code));
    $codes = [
        $country["cca2"] ?? "",
        $country["cca3"] ?? "",
        $country["ccn3"] ?? "",
    ];

    foreach ($codes as $candidate) {
        if (strtolower((string) $candidate) === $needle) {
            return true;
        }
    }

    return false;
}

function countries_missing_population(array $countries): bool
{
    $sample = array_slice($countries, 0, 12);
    foreach ($sample as $country) {
        if (is_numeric($country["population"] ?? null) && (int) $country["population"] > 0) {
            return false;
        }
    }

    return true;
}

function country_lookup_key(array $country): string
{
    return strtoupper(trim((string) ($country["cca3"] ?? $country["cca2"] ?? $country["name"]["common"] ?? "")));
}

function merge_country_records(array $base, array $reference): array
{
    $referenceByKey = [];
    foreach ($reference as $country) {
        $key = country_lookup_key($country);
        if ($key !== "") {
            $referenceByKey[$key] = $country;
        }
    }

    $merged = [];
    foreach ($base as $country) {
        $key = country_lookup_key($country);
        $ref = $referenceByKey[$key] ?? null;
        if ($ref === null) {
            $merged[] = $country;
            continue;
        }

        if (empty($country["population"]) && !empty($ref["population"])) {
            $country["population"] = $ref["population"];
        }
        if (empty($country["flags"]) && !empty($ref["flags"])) {
            $country["flags"] = $ref["flags"];
        }
        if (empty($country["flag"]) && !empty($ref["flag"])) {
            $country["flag"] = $ref["flag"];
        }

        $merged[] = $country;
    }

    return $merged;
}

function load_population_index(): ?array
{
    if (is_readable(POPULATION_CACHE_FILE)) {
        $cached = json_decode((string) file_get_contents(POPULATION_CACHE_FILE), true);
        if (is_array($cached) && isset($cached["data"], $cached["updated"]) && is_array($cached["data"])) {
            if (time() - (int) $cached["updated"] < POPULATION_CACHE_TTL && $cached["data"] !== []) {
                return $cached["data"];
            }
        }
    }

    $payload = fetch_remote_json(POPULATION_API_URL);
    if (!is_array($payload) || !isset($payload[1]) || !is_array($payload[1])) {
        return null;
    }

    $index = [];
    foreach ($payload[1] as $row) {
        if (!is_array($row)) {
            continue;
        }

        $population = (int) ($row["value"] ?? 0);
        if ($population <= 0) {
            continue;
        }

        $year = (string) ($row["date"] ?? "");
        $entry = [
            "population" => $population,
            "year" => $year,
        ];

        $cca3 = strtoupper(trim((string) ($row["countryiso3code"] ?? "")));
        $cca2 = strtoupper(trim((string) ($row["country"]["id"] ?? "")));

        if ($cca3 !== "") {
            if (!isset($index[$cca3]) || $year > ($index[$cca3]["year"] ?? "")) {
                $index[$cca3] = $entry;
            }
        }
        if ($cca2 !== "") {
            if (!isset($index[$cca2]) || $year > ($index[$cca2]["year"] ?? "")) {
                $index[$cca2] = $entry;
            }
        }
    }

    if ($index === []) {
        return null;
    }

    $directory = dirname(POPULATION_CACHE_FILE);
    if (is_dir($directory) && is_writable($directory)) {
        @file_put_contents(POPULATION_CACHE_FILE, json_encode([
            "updated" => time(),
            "data" => $index,
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    return $index;
}

function apply_population_index(array $countries, array $populationIndex): array
{
    foreach ($countries as &$country) {
        if (!empty($country["population"])) {
            continue;
        }

        $key = country_lookup_key($country);
        if ($key !== "" && !empty($populationIndex[$key])) {
            $entry = $populationIndex[$key];
            if (is_array($entry)) {
                $country["population"] = (int) ($entry["population"] ?? 0);
                if (!empty($entry["year"])) {
                    $country["populationYear"] = (string) $entry["year"];
                }
            } else {
                $country["population"] = (int) $entry;
            }
        }
    }
    unset($country);

    return $countries;
}

function ensure_country_population(array $countries): array
{
    if (!countries_missing_population($countries)) {
        return $countries;
    }

    $populationIndex = load_population_index();
    if ($populationIndex !== null) {
        return apply_population_index($countries, $populationIndex);
    }

    $remoteCountries = fetch_remote_country_json();
    if ($remoteCountries !== null) {
        return merge_country_records($countries, $remoteCountries);
    }

    return $countries;
}

$countries = load_country_json_from_file(LOCAL_COUNTRIES_FILE);

if ($countries === null) {
    $countries = fetch_remote_country_json();
} else {
    $countries = ensure_country_population($countries);
}

if ($countries === null) {
    send_json([
        "status" => "error",
        "message" => "Country data is unavailable. Check data/countries.json or the server network connection.",
    ], 503);
}

$code = trim((string) ($_GET["code"] ?? ""));
if ($code !== "") {
    $matches = array_values(array_filter(
        $countries,
        static fn (array $country): bool => country_matches_code($country, $code)
    ));

    send_json($matches, $matches ? 200 : 404);
}

usort($countries, static function (array $left, array $right): int {
    return strcasecmp($left["name"]["common"] ?? "", $right["name"]["common"] ?? "");
});

send_json($countries);
