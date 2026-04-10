<?php
declare(strict_types=1);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Method not allowed";
    exit;
}

if (!isset($_FILES['xlsx']) || !is_uploaded_file($_FILES['xlsx']['tmp_name'])) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Missing XLSX upload";
    exit;
}

$tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cartoflu_pdf_' . bin2hex(random_bytes(8));
if (!@mkdir($tmpRoot, 0700, true) && !is_dir($tmpRoot)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unable to create temporary directory";
    exit;
}

$xlsxPath = $tmpRoot . DIRECTORY_SEPARATOR . 'input.xlsx';
$pdfPath = $tmpRoot . DIRECTORY_SEPARATOR . 'input.pdf';

if (!@move_uploaded_file($_FILES['xlsx']['tmp_name'], $xlsxPath)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unable to move uploaded file";
    @rmdir($tmpRoot);
    exit;
}

$commands = [
    'soffice',
    'libreoffice',
    '/usr/bin/soffice',
    '/usr/local/bin/soffice',
    'C:\Program Files\LibreOffice\program\soffice.exe',
    'C:\Program Files (x86)\LibreOffice\program\soffice.exe'
];

$converted = false;
foreach ($commands as $bin) {
    $cmd = escapeshellarg($bin)
      . ' --headless --nologo --nodefault --convert-to pdf --outdir '
      . escapeshellarg($tmpRoot) . ' '
      . escapeshellarg($xlsxPath) . ' 2>&1';
    $out = [];
    $code = 1;
    @exec($cmd, $out, $code);
    if ($code === 0 && is_file($pdfPath)) {
        $converted = true;
        break;
    }
}

if (!$converted || !is_file($pdfPath)) {
    http_response_code(501);
    header('Content-Type: text/plain; charset=utf-8');
    echo "PDF conversion backend unavailable";
    @unlink($xlsxPath);
    @rmdir($tmpRoot);
    exit;
}

$pdfBytes = @file_get_contents($pdfPath);
if ($pdfBytes === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Unable to read generated PDF";
    @unlink($pdfPath);
    @unlink($xlsxPath);
    @rmdir($tmpRoot);
    exit;
}

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="export.pdf"');
header('Content-Length: ' . strlen($pdfBytes));
echo $pdfBytes;

@unlink($pdfPath);
@unlink($xlsxPath);
@rmdir($tmpRoot);
