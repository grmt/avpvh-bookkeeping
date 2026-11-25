<?php
defined('ABSPATH') || exit;
require_once __DIR__ . '/class-exceptions.php';

/** Read/write OOXML parts without extracting uploaded files into a public directory. */
class AVBK_Xlsx_Archive {
    private $archive;
    private const MAX_TOTAL_BYTES = 64 * 1024 * 1024;
    private const MAX_ENTRIES = 2048;

    public function __construct(string $path) {
        if (!class_exists('ZipArchive')) {
            throw new AVBK_Import_Exception('ZipArchive-extensie is niet beschikbaar.');
        }
        $this->archive = new \ZipArchive();
        if ($this->archive->open($path) !== true) {
            throw new AVBK_Import_Exception('Kan het xlsx-bestand niet openen.');
        }
        $total = 0;
        try {
            if ($this->archive->numFiles > self::MAX_ENTRIES) {
                throw new AVBK_Import_Exception('De werkmap bevat te veel bestanden.');
            }
            for ($i = 0; $i < $this->archive->numFiles; $i++) {
                $stat = $this->archive->statIndex($i);
                if (!$stat || $stat['size'] < 0 || $stat['size'] > 32 * 1024 * 1024) {
                    throw new AVBK_Import_Exception('Het werkblad is te groot of beschadigd.');
                }
                $total += $stat['size'];
                if ($total > self::MAX_TOTAL_BYTES) {
                    throw new AVBK_Import_Exception('De uitgepakte werkmap is te groot.');
                }
            }
        } catch (\Throwable $e) {
            $this->archive->close();
            throw $e;
        }
    }

    public function getFromName(string $name) {
        $stat = $this->archive->statName($name);
        if (!$stat) {
            return false;
        }
        if ($stat['size'] > 32 * 1024 * 1024) {
            throw new AVBK_Import_Exception('Het werkblad is te groot.');
        }
        return $this->archive->getFromName($name);
    }

    public function names(): array {
        $names = [];
        for ($i = 0; $i < $this->archive->numFiles; $i++) {
            $names[] = $this->archive->getNameIndex($i);
        }
        return $names;
    }

    public function put(string $name, string $contents): void {
        if (!$this->archive->addFromString($name, $contents)) {
            throw new AVBK_Import_Exception('Kan de werkmap niet bijwerken.');
        }
    }

    public function remove(string $name): void {
        if ($this->archive->locateName($name) !== false && !$this->archive->deleteName($name)) {
            throw new AVBK_Import_Exception('Kan de werkmap niet bijwerken.');
        }
    }

    public function close(): void {
        if ($this->archive && !$this->archive->close()) {
            throw new AVBK_Import_Exception('Kan de werkmap niet opslaan.');
        }
        $this->archive = null;
    }
}
