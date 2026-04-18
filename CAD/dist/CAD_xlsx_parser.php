<?php
/**
 * SimpleXLSX - ZipArchive 없이 xlsx 파싱
 * zip 함수 직접 사용
 */
class SimpleXLSX {
    private $sheets = [];
    private $sharedStrings = [];

    public static function parse($file) {
        $xlsx = new self();
        if ($xlsx->load($file)) return $xlsx;
        return false;
    }

    private function load($file) {
        if (!file_exists($file)) return false;

        // ZipArchive 사용 가능하면 사용
        if (class_exists('ZipArchive')) {
            return $this->loadWithZipArchive($file);
        }

        // COM 객체 (Windows IIS)
        if (class_exists('COM')) {
            return $this->loadWithCOM($file);
        }

        return false;
    }

    private function loadWithZipArchive($file) {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) return false;

        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml) {
            $dom = new DOMDocument();
            @$dom->loadXML($xml);
            foreach ($dom->getElementsByTagName('si') as $si) {
                $this->sharedStrings[] = $si->textContent;
            }
        }

        $sheetIdx = 1;
        $workbook = $zip->getFromName('xl/workbook.xml');
        if ($workbook) {
            $dom = new DOMDocument();
            @$dom->loadXML($workbook);
            foreach ($dom->getElementsByTagName('sheet') as $sheet) {
                $name = $sheet->getAttribute('name') ?: "Sheet{$sheetIdx}";
                $xmlSheet = $zip->getFromName("xl/worksheets/sheet{$sheetIdx}.xml");
                if ($xmlSheet) {
                    $this->sheets[$name] = $this->parseSheet($xmlSheet);
                }
                $sheetIdx++;
            }
        }
        $zip->close();
        return !empty($this->sheets);
    }

    private function loadWithCOM($file) {
        try {
            $excel = new COM('Excel.Application');
            $excel->Visible = false;
            $wb = $excel->Workbooks->Open(realpath($file));
            $ws = $wb->Sheets(1);
            $rows = [];
            $usedRange = $ws->UsedRange;
            $rowCount = $usedRange->Rows->Count;
            $colCount = $usedRange->Columns->Count;
            for ($r = 1; $r <= $rowCount; $r++) {
                $row = [];
                for ($c = 1; $c <= $colCount; $c++) {
                    $row[] = (string)$ws->Cells($r, $c)->Value;
                }
                $rows[] = $row;
            }
            $wb->Close(false);
            $excel->Quit();
            $this->sheets['Sheet1'] = $rows;
            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    private function parseSheet($xml) {
        $rows = [];
        $dom = new DOMDocument();
        @$dom->loadXML($xml);
        $maxCol = 0;

        foreach ($dom->getElementsByTagName('row') as $row) {
            $rowIdx = (int)$row->getAttribute('r') - 1;
            $rowData = [];
            foreach ($row->getElementsByTagName('c') as $c) {
                $ref = $c->getAttribute('r');
                $colIdx = $this->colIndex($ref);
                $type = $c->getAttribute('t');
                $v = '';
                $vNode = $c->getElementsByTagName('v')->item(0);
                if ($vNode) {
                    $v = $vNode->textContent;
                    if ($type === 's') {
                        $v = $this->sharedStrings[(int)$v] ?? '';
                    }
                }
                $rowData[$colIdx] = $v;
                if ($colIdx > $maxCol) $maxCol = $colIdx;
            }
            $filled = [];
            for ($i = 0; $i <= $maxCol; $i++) {
                $filled[] = $rowData[$i] ?? '';
            }
            $rows[$rowIdx] = $filled;
        }
        ksort($rows);
        return array_values($rows);
    }

    private function colIndex($ref) {
        preg_match('/([A-Z]+)/', $ref, $m);
        $col = $m[1] ?? 'A';
        $idx = 0;
        for ($i = 0; $i < strlen($col); $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - 64);
        }
        return $idx - 1;
    }

    public function rows($sheetName = null) {
        if ($sheetName === null) {
            $first = array_key_first($this->sheets);
            return $this->sheets[$first] ?? [];
        }
        return $this->sheets[$sheetName] ?? [];
    }
}