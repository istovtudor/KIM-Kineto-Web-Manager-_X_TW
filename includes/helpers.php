<?php
function jsonResponse(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function sanitize(string $value): string
{
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

function getJsonInput(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function logActivity(PDO $db, int $userId, string $action, ?string $details = null): void
{
    $stmt = $db->prepare(
        'INSERT INTO user_activity (user_id, action, details) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $action, $details]);
}

function sendEmailLog(PDO $db, int $userId, string $subject, string $body): void
{
    $stmt = $db->prepare(
        'INSERT INTO email_logs (user_id, subject, body) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $subject, $body]);
}

function exportCsv(array $headers, array $rows, string $filename): void
{
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $out = fopen('php://output', 'w');
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
    exit;
}

function exportXml(string $root, array $items, string $filename): void
{
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $xml = new SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><' . $root . '/>');
    foreach ($items as $item) {
        $node = $xml->addChild('item');
        foreach ($item as $key => $value) {
            $node->addChild($key, (string) $value);
        }
    }
    echo $xml->asXML();
    exit;
}

function exportXmlRaw(string $xml, string $filename): void
{
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo $xml;
    exit;
}
