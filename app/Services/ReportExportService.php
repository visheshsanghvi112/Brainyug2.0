<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportService
{
    /**
     * Build and stream an XLSX file from tabular rows.
     */
    public function downloadExcel(string $fileBase, string $sheetTitle, array $headers, array $rows, array $meta = []): BinaryFileResponse
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(substr($sheetTitle, 0, 31));

        foreach ($headers as $idx => $header) {
            $sheet->setCellValue([$idx + 1, 1], $header);
        }

        $sheet->getStyle('A1:' . $this->columnFromIndex(count($headers)) . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
        ]);

        $rowNo = 2;
        foreach ($rows as $row) {
            foreach (array_values($row) as $colIdx => $value) {
                $sheet->setCellValue([$colIdx + 1, $rowNo], $value);
            }
            $rowNo++;
        }

        if (!empty($meta)) {
            $rowNo += 1;
            foreach ($meta as $label => $value) {
                $sheet->setCellValue([1, $rowNo], (string) $label);
                $sheet->setCellValue([2, $rowNo], (string) $value);
                $sheet->getStyle('A' . $rowNo)->getFont()->setBold(true);
                $rowNo++;
            }
        }

        $lastDataRow = max(1, $rowNo - 1);
        $sheet->getStyle('A2:' . $this->columnFromIndex(count($headers)) . $lastDataRow)
            ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
            ->getColor()->setRGB('E5E7EB');

        foreach (range(1, count($headers)) as $idx) {
            $sheet->getColumnDimension($this->columnFromIndex($idx))->setAutoSize(true);
        }

        $temp = tempnam(sys_get_temp_dir(), 'report_xlsx_');
        (new Xlsx($spreadsheet))->save($temp);

        return response()->download($temp, $fileBase . '_' . now()->format('Ymd_His') . '.xlsx')->deleteFileAfterSend(true);
    }

    /**
     * Build and stream a PDF table report.
     */
    public function downloadPdf(string $fileBase, string $title, array $headers, array $rows, array $meta = []): \Symfony\Component\HttpFoundation\Response
    {
        $pdf = Pdf::loadView('exports.report-table-pdf', [
            'title' => $title,
            'headers' => $headers,
            'rows' => $rows,
            'meta' => $meta,
            'generatedAt' => now()->format('d M Y, h:i A'),
        ])->setPaper('a4', 'landscape');

        return $pdf->download($fileBase . '_' . now()->format('Ymd_His') . '.pdf');
    }

    private function columnFromIndex(int $index): string
    {
        $index = max(1, $index);
        $column = '';
        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)) . $column;
            $index = intdiv($index, 26);
        }

        return $column;
    }
}
