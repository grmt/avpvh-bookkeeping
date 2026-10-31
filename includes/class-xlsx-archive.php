<?php
defined('ABSPATH') || exit;

/** Read/write OOXML parts without extracting uploaded files into a public directory. */
class AVBK_Xlsx_Archive {
    private $archive;

    public function __construct(string $path) {
        if (!class_exists('ZipArchive')) throw new \RuntimeException('ZipArchive-extensie is niet beschikbaar.');
        $this->archive = new \ZipArchive();
        if ($this->archive->open($path) !== true) {
            throw new \RuntimeException('Kan het xlsx-bestand niet openen.');
        }
    }

    public function getFromName(string $name) {
        $stat = $this->archive->statName($name);
        if (!$stat) return false;
        if ($stat['size'] > 32 * 1024 * 1024) throw new \RuntimeException('Het werkblad is te groot.');
        return $this->archive->getFromName($name);
    }

    public function names(): array {
        $names = [];
        for ($i = 0; $i < $this->archive->numFiles; $i++) $names[] = $this->archive->getNameIndex($i);
        return $names;
    }

    public function put(string $name, string $contents): void {
        if (!$this->archive->addFromString($name, $contents)) throw new \RuntimeException('Kan de werkmap niet bijwerken.');
    }

    public function remove(string $name): void {
        if ($this->archive->locateName($name) !== false && !$this->archive->deleteName($name)) {
            throw new \RuntimeException('Kan de werkmap niet bijwerken.');
        }
    }

    public function close(): void {
        if ($this->archive && !$this->archive->close()) {
            throw new \RuntimeException('Kan de werkmap niet opslaan.');
        }
        $this->archive = null;
    }
}
