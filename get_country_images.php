<?php
declare(strict_types=1);

include "config.php";
session_start();

header("Content-Type: application/json; charset=utf-8");

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Authentication required']);
    exit();
}

const WIKIMEDIA_API = "https://commons.wikimedia.org/w/api.php";
const MAX_STREET_IMAGES = 4;

function send_images_response(array $images, string $source): void
{
    echo json_encode([
        "status" => "success",
        "source" => $source,
        "images" => $images,
    ], JSON_UNESCAPED_SLASHES);
    exit();
}

function send_images_error(int $code, string $message): void
{
    http_response_code($code);
    echo json_encode([
        "status" => "error",
        "message" => $message,
    ]);
    exit();
}

function fetch_remote_json(string $url): ?array
{
    $response = false;

    if (function_exists("curl_init")) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
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
                "timeout" => 12,
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

function is_street_photo(string $title): bool
{
    $lowerTitle = strtolower($title);
    $streetTerms = [
        "street", "road", "avenue", "boulevard", "lane", "alley", "highway",
        "rue ", "straße", "strasse", "strada", "calle", "via ", "plaza", "square",
    ];

    foreach ($streetTerms as $term) {
        if (str_contains($lowerTitle, $term)) {
            return true;
        }
    }

    return false;
}

function is_photo_candidate(string $title, string $url): bool
{
    $lowerTitle = strtolower($title);
    $lowerUrl = strtolower($url);

    if (str_ends_with($lowerUrl, ".svg") || str_contains($lowerUrl, ".svg?")) {
        return false;
    }

    $blocked = ["logo", "icon", "flag", "map", "emblem", "coat of arms", "seal", "diagram", "chart", "satellite"];
    foreach ($blocked as $term) {
        if (str_contains($lowerTitle, $term)) {
            return false;
        }
    }

    if (!preg_match('/\.(jpe?g|png|webp)(\?|$)/i', $lowerUrl)) {
        return false;
    }

    return is_street_photo($title);
}

function wikimedia_attribution(array $extmetadata): string
{
    $artist = trim(strip_tags((string) ($extmetadata["Artist"]["value"] ?? "")));
    $license = trim(strip_tags((string) ($extmetadata["LicenseShortName"]["value"] ?? "CC")));
    $artist = $artist !== "" ? $artist : "Wikimedia contributor";

    return $artist . " / Wikimedia Commons (" . $license . ")";
}

function search_wikimedia_street_images(string $query, int $limit = MAX_STREET_IMAGES): array
{
    $params = http_build_query([
        "action" => "query",
        "format" => "json",
        "generator" => "search",
        "gsrsearch" => $query,
        "gsrnamespace" => 6,
        "gsrlimit" => max($limit * 3, 8),
        "prop" => "imageinfo",
        "iiprop" => "url|extmetadata|timestamp|mime",
        "iiurlwidth" => 900,
    ]);

    $payload = fetch_remote_json(WIKIMEDIA_API . "?" . $params);
    if ($payload === null || !isset($payload["query"]["pages"]) || !is_array($payload["query"]["pages"])) {
        return [];
    }

    $candidates = [];

    foreach ($payload["query"]["pages"] as $page) {
        $info = $page["imageinfo"][0] ?? null;
        if (!is_array($info)) {
            continue;
        }

        $src = (string) ($info["thumburl"] ?? $info["url"] ?? "");
        $title = (string) ($page["title"] ?? "");
        if ($src === "" || !is_photo_candidate($title, $src)) {
            continue;
        }

        $timestamp = (string) ($info["timestamp"] ?? "");
        $caption = preg_replace('/^File:/i', '', $title);
        $caption = str_replace("_", " ", $caption);
        $year = $timestamp !== "" ? substr($timestamp, 0, 4) : "";

        $candidates[] = [
            "src" => $src,
            "alt" => $caption,
            "caption" => $year !== "" ? $caption . " (" . $year . ")" : $caption,
            "attribution" => wikimedia_attribution($info["extmetadata"] ?? []),
            "timestamp" => $timestamp,
        ];
    }

    usort($candidates, static function (array $left, array $right): int {
        return strcmp($right["timestamp"], $left["timestamp"]);
    });

    return array_map(
        static fn (array $image): array => [
            "src" => $image["src"],
            "alt" => $image["alt"],
            "caption" => $image["caption"],
            "attribution" => $image["attribution"],
        ],
        array_slice($candidates, 0, $limit)
    );
}

function collect_street_images(string $country, string $capital): array
{
    $queries = [];
    if ($capital !== "") {
        $queries[] = $capital . " street " . $country;
        $queries[] = $capital . " street scene";
        $queries[] = $capital . " downtown street";
        $queries[] = $capital . " street photography";
    }
    $queries[] = $country . " street scene";
    $queries[] = $country . " street photography";

    $images = [];
    $seen = [];

    foreach ($queries as $query) {
        foreach (search_wikimedia_street_images($query) as $image) {
            if (isset($seen[$image["src"]])) {
                continue;
            }
            $seen[$image["src"]] = true;
            $images[] = $image;
            if (count($images) >= MAX_STREET_IMAGES) {
                return $images;
            }
        }
    }

    return $images;
}

function mapbox_fallback_images(string $country, float $lat, float $lng): array
{
    $token = mapbox_access_token();
    if ($token === "") {
        return [];
    }

    $pin = "pin-l+f97316(" . $lng . "," . $lat . ")";
    $styles = [
        ["style" => "mapbox/streets-v12", "zoom" => 12, "caption" => $country . " street map"],
        ["style" => "mapbox/satellite-streets-v12", "zoom" => 11, "caption" => $country . " satellite streets"],
    ];

    $images = [];
    foreach ($styles as $item) {
        $coordinates = $lng . "," . $lat . "," . $item["zoom"] . ",0,0";
        $src = "https://api.mapbox.com/styles/v1/" . $item["style"] .
            "/static/" . rawurlencode($pin) . "/" . $coordinates .
            "/900x540?access_token=" . rawurlencode($token);

        $images[] = [
            "src" => $src,
            "alt" => $item["caption"],
            "caption" => $item["caption"],
            "attribution" => "Map imagery by Mapbox",
        ];
    }

    return $images;
}

$country = trim((string) ($_GET["country"] ?? ""));
$capital = trim((string) ($_GET["capital"] ?? ""));
$lat = isset($_GET["lat"]) && $_GET["lat"] !== "" ? (float) $_GET["lat"] : null;
$lng = isset($_GET["lng"]) && $_GET["lng"] !== "" ? (float) $_GET["lng"] : null;

if ($country === "") {
    send_images_error(422, "Country is required.");
}

$images = collect_street_images($country, $capital);
if ($images !== []) {
    send_images_response($images, "wikimedia");
}

if ($lat !== null && $lng !== null) {
    $images = mapbox_fallback_images($country, $lat, $lng);
    if ($images !== []) {
        send_images_response($images, "mapbox");
    }
}

send_images_error(404, "No recent street images found for this country.");
