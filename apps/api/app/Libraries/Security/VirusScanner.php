<?php

namespace App\Libraries\Security;

/**
 * VirusScanner
 * Scans uploaded tender documents for malicious scripts, embedded payloads, and macro viruses.
 */
class VirusScanner
{
    /**
     * Scans a file path. Returns [clean: bool, reason: ?string].
     */
    public static function scan(string $filePath): array
    {
        if (! file_exists($filePath)) {
            return ['clean' => false, 'reason' => 'File not found'];
        }

        // 1. Read header sample safely across platforms
        $handle = @fopen($filePath, 'rb');
        if ($handle === false) {
            return ['clean' => false, 'reason' => 'Security alert: Potential malware detected by antivirus scanner.'];
        }
        $sample = fread($handle, 4096);
        $header = substr($sample, 0, 8);
        fclose($handle);

        if ($sample !== '') {
            // Check for known malware/test signatures (e.g. standard EICAR test string)
            if (str_contains($sample, 'EICAR-STANDARD-ANTIVIRUS-TEST-FILE!')) {
                return ['clean' => false, 'reason' => 'Security alert: Potential malware detected by antivirus scanner.'];
            }

            // 2. Reject executable signatures or script tags masquerading as documents
            if (str_starts_with($sample, 'MZ') || str_starts_with($sample, "\x7fELF")) {
                return ['clean' => false, 'reason' => 'Security alert: Executable binary files are strictly prohibited.'];
            }
            if (str_starts_with($sample, '#!') || stripos($sample, '<?php') !== false || stripos($sample, '<?=') !== false) {
                return ['clean' => false, 'reason' => 'Security alert: Executable script files are strictly prohibited.'];
            }
        }

        // 3. Magic Byte Verification for allowed document types

        $isPdf  = str_starts_with($header, '%PDF-');
        $isZip  = str_starts_with($header, "PK\x03\x04"); // DOCX, XLSX, ZIP
        $isOle  = str_starts_with($header, "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1"); // DOC, XLS
        $isJpg  = str_starts_with($header, "\xFF\xD8\xFF"); // JPEG
        $isPng  = str_starts_with($header, "\x89PNG\r\n\x1a\n"); // PNG

        if (! $isPdf && ! $isZip && ! $isOle && ! $isJpg && ! $isPng) {
            return ['clean' => false, 'reason' => 'Invalid file format. Only verified PDF, DOC, DOCX, XLS, XLSX, ZIP, and image tenders are accepted.'];
        }

        // 4. ClamAV Daemon check (if clamdscan binary or socket is available on host)
        $clamd = getenv('CLAMD_SOCKET') ?: '/var/run/clamav/clamd.ctl';
        if (file_exists($clamd) && function_exists('exec')) {
            $cmd = escapeshellcmd("clamdscan --no-summary {$filePath}");
            exec($cmd, $output, $returnCode);
            if ($returnCode !== 0) {
                return ['clean' => false, 'reason' => 'Security alert: Potential malware detected by antivirus scanner.'];
            }
        }

        // Clean
        return ['clean' => true, 'reason' => null];
    }
}
