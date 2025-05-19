<?php
require __DIR__ . '/vendor/autoload.php';

use Ovh\Api;

$config = require '/var/www/vhosts/aldea.social/ovh.php';

$ovh = new Api(
    $config['application_key'],
    $config['application_secret'],
    'ovh-eu',
    $config['consumer_key']
);

// Leer JSON recibido
$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$validTokens = ['caucheta']; // añade y quita lo que quieras

if (!in_array($token, $validTokens)) {
    http_response_code(403);
    echo json_encode(['detail' => 'Código de uso inválido. Escribe a @joan.aldea.social en bluesky!']);
    exit;
}

$handle = $input['handle'] ?? '';

if (!preg_match('/^[a-z0-9\-\.]+\.[a-z]{2,}$/i', $handle)) {
    http_response_code(400);
    echo json_encode(['detail' => 'Handle inválido']);
    exit;
}

// Protección diaria por IP (máx 5 usos)
$ip = $_SERVER['REMOTE_ADDR'];
$exemptIps = ['89.128.53.228'];
$logdir = __DIR__ . '/logs';

if (!in_array($ip, $exemptIps)) {
    $logfile = $logdir . '/' . md5($ip) . '.json';

    if (!file_exists($logdir)) {
        mkdir($logdir, 0700, true);
    }

    $log = file_exists($logfile)
        ? json_decode(file_get_contents($logfile), true)
        : ['count' => 0, 'date' => date('Y-m-d')];

    if ($log['date'] !== date('Y-m-d')) {
        $log = ['count' => 0, 'date' => date('Y-m-d')];
    }

    if ($log['count'] >= 5) {
        http_response_code(429);
        echo json_encode(['detail' => 'Límite de registros diarios alcanzado para esta IP']);
        exit;
    }

    $log['count'] += 1;
    file_put_contents($logfile, json_encode($log));
}

// Resolver DID
function resolveDid($handle) {
    file_put_contents(__DIR__ . '/logs/resolve.log', date('c') . " inicio de resolveDid\n", FILE_APPEND);
    try {
        $url = 'https://bsky.social/xrpc/com.atproto.identity.resolveHandle?handle=' . urlencode($handle);
        file_put_contents(__DIR__ . '/logs/resolve.log', date('c') . " $url\n", FILE_APPEND);
        $response = @file_get_contents($url);
        file_put_contents(__DIR__ . '/logs/resolve.log', date('c') . " $handle => $response\n", FILE_APPEND);
        if ($response === false) return null;
        $data = json_decode($response, true);
        return $data['did'] ?? null;
    } catch (Exception $e) {
        echo "Error al resolver el DID: " . $e->getMessage();
        return null;
    }
}

$did = resolveDid($handle);

if (!$did) {
    http_response_code(400);
    echo json_encode(['detail' => 'No se pudo resolver el DID']);
    exit;
}

// Añadir registro TXT
$subdomain = '_atproto.' . explode('.', $handle)[0];
$target = "did=$did";
try {
    $existing = $ovh->get("/domain/zone/aldea.social/record", [
        'fieldType' => 'TXT',
        'subDomain' => $subdomain
    ]);

    if (!empty($existing)) {
        http_response_code(409);
        echo json_encode(['detail' => 'Este handle ya está registrado.']);
        exit;
    }
} catch (\Exception $e) {
    // continuar si no hay registros
}

try {
    $ovh->post('/domain/zone/aldea.social/record', [
        'fieldType' => 'TXT',
        'subDomain' => $subdomain,
        'ttl' => 3600,
        'target' => $target
    ]);

    // Refrescar zona para aplicar cambios
    $ovh->post('/domain/zone/aldea.social/refresh');

    // Log general
    $registroLog = $logdir . '/registro.log';
    $logEntry = sprintf("[%s] %s => %s (%s)\n", date('Y-m-d H:i:s'), $ip, $handle, $did);
    file_put_contents($registroLog, $logEntry, FILE_APPEND);

    // Respuesta al frontend
    echo json_encode([
        'success' => true,
        'handle' => explode('.', $subdomain, 2)[1] . '.aldea.social',
        'did' => $did
    ]);
    exit;

} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['detail' => 'Error OVH: ' . $e->getMessage()]);
    exit;
}
