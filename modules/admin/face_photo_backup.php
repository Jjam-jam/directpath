<?php
// Export persisted face reference photos for migration to another server/laptop.
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireRole('hr_admin');

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    exit('PHP ZipArchive extension is required to export face photos.');
}

$db = getDB();
$stmt = $db->query("SELECT employee_id, reference_photo FROM users WHERE reference_photo IS NOT NULL AND reference_photo <> '' ORDER BY employee_id");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$sourceDir = realpath(REFERENCE_PHOTO_PATH);
if (!$sourceDir || !is_dir($sourceDir)) {
    http_response_code(500);
    exit('Reference photo storage directory was not found.');
}

$tmp = tempnam(sys_get_temp_dir(), 'face_photos_');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
    @unlink($tmp);
    http_response_code(500);
    exit('Could not create the photo archive.');
}
$manifest = [
    'created_at' => date(DATE_ATOM),
    'instructions' => 'Copy files from reference_photos/ into the destination uploads/reference_photos/ folder and import the matching database. The users.reference_photo field stores each filename.',
    'photos' => [],
    'missing_files' => [],
];
foreach ($rows as $row) {
    $name = basename((string)$row['reference_photo']);
    if ($name === '' || $name !== $row['reference_photo']) {
        $manifest['missing_files'][] = ['employee_id' => $row['employee_id'], 'filename' => $row['reference_photo'], 'reason' => 'Invalid filename'];
        continue;
    }
    $path = $sourceDir . DIRECTORY_SEPARATOR . $name;
    if (!is_file($path)) {
        $manifest['missing_files'][] = ['employee_id' => $row['employee_id'], 'filename' => $name, 'reason' => 'File not found on source server'];
        continue;
    }
    $zip->addFile($path, 'reference_photos/' . $name);
    $manifest['photos'][] = ['employee_id' => $row['employee_id'], 'filename' => $name];
}
$zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
$zip->addFromString('README.txt', "Directpath face photo transfer\n\n1. Back up/export the Directpath database separately.\n2. Extract reference_photos/* into the destination project's uploads/reference_photos/ folder.\n3. Import the database so users.reference_photo filename values match these files.\n4. Ensure the web server account can read the photos.\n5. Open Admin > Face Training and verify Current Photo previews before training faces.\n\nThe archive includes manifest.json mapping employee IDs to filenames and listing any missing source files.\n");
$zip->close();

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="directpath_face_photos_' . date('Ymd_His') . '.zip"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store, no-cache, must-revalidate');
readfile($tmp);
@unlink($tmp);
exit;
