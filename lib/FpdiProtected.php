<?php
/**
 * AVBA Certificaciones — FpdiProtected
 *
 * FPDI subclass that adds 128-bit RC4 PDF encryption (Standard Security Handler Rev 3).
 * Overrides _textstring, _putstream, writePdfType, _putresources and _puttrailer so that
 * ALL content (FPDF-generated AND FPDI-imported) is encrypted when SetProtection() is called.
 *
 * Usage:
 *   $pdf = new FpdiProtected();
 *   $count = $pdf->setSourceFile($existingPdfPath);
 *   for ($i = 1; $i <= $count; $i++) {
 *       $tpl = $pdf->importPage($i);
 *       $sz  = $pdf->getTemplateSize($tpl);
 *       $pdf->AddPage($sz['width'] > $sz['height'] ? 'L' : 'P', [$sz['width'], $sz['height']]);
 *       $pdf->useTemplate($tpl, 0, 0, $sz['width'], $sz['height']);
 *   }
 *   $pdf->SetProtection(['print', 'print-hi'], '', 'ownerPass');
 *   $bytes = $pdf->Output('', 'S');
 */

require_once __DIR__ . '/fpdi_loader.php';

use setasign\Fpdi\PdfParser\Type\PdfHexString;
use setasign\Fpdi\PdfParser\Type\PdfStream;
use setasign\Fpdi\PdfParser\Type\PdfString;
use setasign\Fpdi\PdfParser\Type\PdfType;

class FpdiProtected extends \setasign\Fpdi\Fpdi
{
    private bool   $pdfEncrypted = false;
    private string $pdfUvalue   = '';   // 32 bytes
    private string $pdfOvalue   = '';   // 32 bytes
    private int    $pdfPvalue   = 0;
    private string $pdfEncKey   = '';   // 16 bytes (128-bit)
    private string $pdfFileId   = '';   // 16 bytes
    private int    $encryptObjN = 0;

    /** Standard 32-byte PDF password padding (PDF spec Appendix C) */
    private const PAD =
        "\x28\xBF\x4E\x5E\x4E\x75\x8A\x41\x64\x00\x4E\x56\xFF\xFA\x01\x08" .
        "\x2E\x2E\x00\xB6\xD0\x68\x3E\x80\x2F\x0C\xA9\xFE\x64\x53\x69\x7A";

    // ─────────────────────────────────────────────────────────────────────────
    // Public API
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Enable PDF protection. Call this BEFORE Output().
     *
     * @param string[] $permissions  Subset of: 'print','print-hi','modify','copy',
     *                               'annot-forms','fill-forms','extract','assemble'
     * @param string   $userPass     Password required to open the file ('' = no password)
     * @param string   $ownerPass    Full-access password (random if empty)
     */
    public function SetProtection(
        array  $permissions = ['print', 'print-hi'],
        string $userPass    = '',
        string $ownerPass   = ''
    ): void {
        // Build permissions bitmask.
        // Start from -3904 (0xFFFFF0C0): reserved bits set, no permission bits set.
        $p = -3904;
        foreach ($permissions as $perm) {
            switch ($perm) {
                case 'print':        $p |= 4;    break; // bit 3
                case 'modify':       $p |= 8;    break; // bit 4
                case 'copy':         $p |= 16;   break; // bit 5
                case 'annot-forms':  $p |= 32;   break; // bit 6
                case 'fill-forms':   $p |= 256;  break; // bit 9
                case 'extract':      $p |= 512;  break; // bit 10
                case 'assemble':     $p |= 1024; break; // bit 11
                case 'print-hi':     $p |= 2048; break; // bit 12
            }
        }
        $this->pdfPvalue = $p;

        // Random 16-byte file identifier
        $this->pdfFileId = random_bytes(16);

        // Pad/truncate passwords to 32 bytes
        $userPass32  = substr($userPass  . self::PAD, 0, 32);
        if ($ownerPass === '') {
            $ownerPass = bin2hex(random_bytes(16));
        }
        $ownerPass32 = substr($ownerPass . self::PAD, 0, 32);

        // ── Compute O value ──────────────────────────────────────────────────
        // Rev 3: MD5(owner_password_padded), then 50 more MD5 rounds → 16-byte key
        $ownerKey = md5($ownerPass32, true);
        for ($i = 0; $i < 50; $i++) {
            $ownerKey = md5($ownerKey, true);
        }
        // RC4-encrypt the padded user password 20 times with XOR'd key variants
        $oVal = $userPass32;
        for ($i = 0; $i < 20; $i++) {
            $key = '';
            for ($j = 0; $j < 16; $j++) {
                $key .= chr(ord($ownerKey[$j]) ^ $i);
            }
            $oVal = $this->pdfRc4($key, $oVal);
        }
        $this->pdfOvalue = $oVal; // 32 bytes

        // ── Compute file encryption key ──────────────────────────────────────
        // Rev 3: MD5(user_pad + O + P_4bytes_LE + fileId), then 50 more rounds
        $pBytes = pack('V', $p & 0xFFFFFFFF);
        $keyMd5 = md5($userPass32 . $this->pdfOvalue . $pBytes . $this->pdfFileId, true);
        for ($i = 0; $i < 50; $i++) {
            $keyMd5 = md5($keyMd5, true);
        }
        $this->pdfEncKey = substr($keyMd5, 0, 16);

        // ── Compute U value ──────────────────────────────────────────────────
        // Rev 3: RC4(file_key, MD5(padding + fileId)), then 19 more XOR rounds
        $uBase = md5(self::PAD . $this->pdfFileId, true);
        $uVal  = $this->pdfRc4($this->pdfEncKey, $uBase);
        for ($i = 1; $i < 20; $i++) {
            $key = '';
            for ($j = 0; $j < 16; $j++) {
                $key .= chr(ord($this->pdfEncKey[$j]) ^ $i);
            }
            $uVal = $this->pdfRc4($key, $uVal);
        }
        $this->pdfUvalue = $uVal . str_repeat("\x00", 16); // pad to 32 bytes

        // Encryption requires PDF 1.4+
        if (version_compare($this->PDFVersion, '1.4') < 0) {
            $this->PDFVersion = '1.4';
        }

        $this->pdfEncrypted = true;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /** Per-object RC4 key: MD5(file_key + obj_num[3 bytes LE] + gen[2 bytes LE])[0..15] */
    private function pdfObjectKey(int $n): string
    {
        $b = $this->pdfEncKey
           . chr($n & 0xff)
           . chr(($n >> 8) & 0xff)
           . chr(($n >> 16) & 0xff)
           . "\x00\x00"; // generation number = 0
        return substr(md5($b, true), 0, min(strlen($this->pdfEncKey) + 5, 16));
    }

    /** RC4 stream cipher via OpenSSL */
    private function pdfRc4(string $key, string $data): string
    {
        return openssl_encrypt($data, 'RC4', $key, OPENSSL_RAW_DATA);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FPDF overrides — encrypt FPDF-generated strings and streams
    // ─────────────────────────────────────────────────────────────────────────

    /** Encrypt metadata/info strings written by FPDF */
    protected function _textstring($s)
    {
        if (!$this->pdfEncrypted) {
            return parent::_textstring($s);
        }
        if (!$this->_isascii($s)) {
            $s = $this->_UTF8toUTF16($s);
        }
        $enc = $this->pdfRc4($this->pdfObjectKey($this->n), $s);
        return '(' . $this->_escape($enc) . ')';
    }

    /** Encrypt page-content and template streams written by FPDF/FpdfTpl */
    protected function _putstream($data)
    {
        if (!$this->pdfEncrypted) {
            parent::_putstream($data);
            return;
        }
        $enc = $this->pdfRc4($this->pdfObjectKey($this->n), $data);
        $this->_put('stream');
        $this->_put($enc);
        $this->_put('endstream');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // FPDI override — encrypt FPDI-imported strings and streams
    // ─────────────────────────────────────────────────────────────────────────

    protected function writePdfType(PdfType $value): void
    {
        if (!$this->pdfEncrypted) {
            parent::writePdfType($value);
            return;
        }

        if ($value instanceof PdfString) {
            // Unescape → encrypt → re-escape
            $raw = PdfString::unescape($value->value);
            $enc = $this->pdfRc4($this->pdfObjectKey($this->n), $raw);
            $this->_put('(' . PdfString::escape($enc) . ')', false);

        } elseif ($value instanceof PdfHexString) {
            // Decode hex → encrypt → re-encode as hex string
            $raw = hex2bin($value->value);
            $enc = $this->pdfRc4($this->pdfObjectKey($this->n), $raw);
            $this->_put('<' . bin2hex($enc) . '>', false);

        } elseif ($value instanceof PdfStream) {
            // Write the stream dictionary (recursing through MY override for nested strings)
            $this->writePdfType($value->value);
            // Encrypt the raw stream content (after any compression filters)
            $stream = $value->getStream();
            $enc    = $this->pdfRc4($this->pdfObjectKey($this->n), $stream);
            $this->_put('stream');
            $this->_put($enc);
            $this->_put('endstream');

        } else {
            // Numbers, names, booleans, arrays, dictionaries, indirect refs — pass through.
            // Arrays and dictionaries will recursively call $this->writePdfType() for their
            // values, so nested strings/streams are still encrypted.
            parent::writePdfType($value);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Add /Encrypt object and trailer entries
    // ─────────────────────────────────────────────────────────────────────────

    protected function _putresources(): void
    {
        parent::_putresources();

        if (!$this->pdfEncrypted) {
            return;
        }

        // Append the Standard Security Handler dictionary as a new PDF object
        $this->_newobj();
        $this->encryptObjN = $this->n;
        $this->_put('<<');
        $this->_put('/Filter /Standard');
        $this->_put('/V 2');        // RC4 with variable key length
        $this->_put('/R 3');        // Revision 3 (128-bit)
        $this->_put('/Length 128');
        $this->_put('/P ' . $this->pdfPvalue);
        $this->_put('/O <' . bin2hex($this->pdfOvalue) . '>');
        $this->_put('/U <' . bin2hex($this->pdfUvalue) . '>');
        $this->_put('>>');
        $this->_put('endobj');
    }

    protected function _puttrailer(): void
    {
        parent::_puttrailer();

        if (!$this->pdfEncrypted) {
            return;
        }

        $this->_put('/Encrypt ' . $this->encryptObjN . ' 0 R');
        $id = bin2hex($this->pdfFileId);
        $this->_put('/ID [<' . $id . '> <' . $id . '>]');
    }
}
