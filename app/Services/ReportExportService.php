<?php

namespace App\Services;

use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ReportExportService
{
    /**
     * Build and stream an XLSX file from tabular rows.
     * Optimized for large datasets with memory management.
     */
    public function downloadExcel(string $fileBase, string $sheetTitle, array $headers, array $rows, array $meta = []): BinaryFileResponse
    {
        // Increase memory limit and timeout for large exports
        ini_set('memory_limit', '512M');
        set_time_limit(300); // 5 minutes
        
        try {
            $spreadsheet = new Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($sheetTitle, 0, 31));

            // Add headers
            foreach ($headers as $idx => $header) {
                $sheet->setCellValueByColumnAndRow($idx + 1, 1, $header);
            }

            // Style header row
            $lastColumn = $this->columnFromIndex(count($headers));
            $sheet->getStyle("A1:{$lastColumn}1")->applyFromArray([
                'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '1F4E79']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D1D5DB']]],
            ]);

            // Add data rows - process in chunks for memory efficiency
            $rowNo = 2;
            $chunkSize = 1000; // Process 1000 rows at a time
            $totalRows = count($rows);

            for ($i = 0; $i < $totalRows; $i += $chunkSize) {
                $chunk = array_slice($rows, $i, $chunkSize);
                
                foreach ($chunk as $row) {
                    foreach (array_values($row) as $colIdx => $value) {
                        $sheet->setCellValueByColumnAndRow($colIdx + 1, $rowNo, $value);
                    }
                    $rowNo++;
                }

                // Periodically garbage collect to free memory
                if ($i % 5000 === 0) {
                    gc_collect_cycles();
                }
            }

            // Add metadata if provided
            if (!empty($meta)) {
                $rowNo += 1;
                foreach ($meta as $label => $value) {
                    $sheet->setCellValueByColumnAndRow(1, $rowNo, (string) $label);
                    $sheet->setCellValueByColumnAndRow(2, $rowNo, (string) $value);
                    $sheet->getStyle('A' . $rowNo)->getFont()->setBold(true);
                    $rowNo++;
                }
            }

            // Apply borders to data area
            $lastDataRow = max(1, $rowNo - 1);
            if ($lastDataRow >= 2) {
                $sheet->getStyle("A2:{$lastColumn}{$lastDataRow}")
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)
                    ->getColor()->setRGB('E5E7EB');
            }

            // Auto-size columns
            foreach (range(1, count($headers)) as $idx) {
                $sheet->getColumnDimension($this->columnFromIndex($idx))->setAutoSize(true);
            }

            // Save to temp file
            $temp = tempnam(sys_get_temp_dir(), 'report_xlsx_');
            if ($temp === false) {
                throw new \RuntimeException('Failed to create temporary file for Excel export');
            }

            (new Xlsx($spreadsheet))->save($temp);
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);

            return response()
                ->download($temp, $fileBase . '_' . now()->format('Ymd_His') . '.xlsx', [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                ])
                ->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            Log::error('Export error (Excel)', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            abort(500, "Export failed: {$e->getMessage()}");
        }
    }

    /**
     * Build and stream a PDF table report.
     * Optimized for large datasets.
     */
    public function downloadPdf(string $fileBase, string $title, array $headers, array $rows, array $meta = []): \Symfony\Component\HttpFoundation\Response
    {
        // Increase memory limit and timeout for large exports
        ini_set('memory_limit', '512M');
        set_time_limit(300); // 5 minutes

        try {
            // Limit rows for PDF to avoid memory/complexity issues
            $maxRows = 5000;
            if (count($rows) > $maxRows) {
                $rows = array_slice($rows, 0, $maxRows);
            }

            $pdf = Pdf::loadView('exports.report-table-pdf', [
                'title' => $title,
                'headers' => $headers,
                'rows' => $rows,
                'meta' => $meta,
                'generatedAt' => now()->format('d M Y, h:i A'),
            ]);

            $pdf->setPaper('a4', 'landscape');

            if (method_exists($pdf, 'setOption')) {
                // Set PDF options using Dompdf-compatible keys when the wrapper supports them.
                $pdf->setOption('isHtml5ParserEnabled', true);
                $pdf->setOption('isRemoteEnabled', true);
            }

            return $pdf->download($fileBase . '_' . now()->format('Ymd_His') . '.pdf');
        } catch (\Throwable $e) {
            Log::error('Export error (PDF)', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            abort(500, "PDF export failed: {$e->getMessage()}");
        }
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

