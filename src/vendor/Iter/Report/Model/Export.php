<?php

declare(strict_types=1);

namespace Iter\Report\Model;

use Dompdf\Dompdf;
use Dompdf\Options as PdfOptions;
use Iter\Core\Config;
use Iter\Core\View\ComponentResolver;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\AutoFilter;
use OpenSpout\Writer\XLSX\Entity\SheetView;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Options\PageOrientation;
use OpenSpout\Writer\XLSX\Options\PageSetup;
use OpenSpout\Writer\XLSX\Options\PaperSize;
use OpenSpout\Writer\XLSX\Writer;

/**
 * A report as a file (REPORT-PLAN §6), with the filters it was made with: CSV (";", decimal comma, BOM,
 * for Excel in Portuguese), XLSX (numbers as numbers, the sums as formulas) and PDF (A4 landscape, the
 * store's logo, page numbers). The lines are the whole result in the screen's order.
 */
final class Export
{
    public const FORMATS = ['csv' => 'text/csv; charset=UTF-8', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'pdf' => 'application/pdf'];

    /** more lines than this: the PDF is refused (use the spreadsheet) */
    public const PDF_MAX_ROWS = 2000;

    public const LABELS = ['csv' => 'CSV', 'xlsx' => 'Excel', 'pdf' => 'PDF'];

    private const XLSX_FORMATS = ['money' => '"R$" #,##0.00', 'pct' => '0.0%', 'int' => '#,##0', 'qty' => '#,##0.####'];

    /**
     * @param array<string, array> $columns the visible ones (ReportService::columns)
     * @param array<int, array> $tiles the report's indicators
     */
    public function __construct(
        private readonly Report $report,
        private readonly array $columns,
        private readonly array $rows,
        private readonly array $totals,
        private readonly array $tiles,
        public readonly string $description,
        private readonly string $note,
        private readonly string $by,
    ) {
    }

    /** how many lines the file has */
    public function count(): int
    {
        return count($this->rows);
    }

    /** "vendas-por-produto_2026-10-01_a_2026-10-16.xlsx" */
    public function filename(string $format, array $f): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT', $this->report->info['title']))), '-');

        return $slug . (in_array('period', $this->report->common(), true) ? '_' . $f['from'] . '_a_' . $f['to'] : '_' . date('Y-m-d')) . '.' . $format;
    }

    public function write(string $format, string $path): void
    {
        match ($format) {
            'csv' => $this->csv($path),
            'xlsx' => $this->xlsx($path),
            'pdf' => $this->pdf($path),
        };
    }

    private function csv(string $path): void
    {
        $out = fopen($path, 'wb');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [$this->report->info['title'], $this->description, $this->by], ';', '"', '');
        fputcsv($out, array_column($this->columns, 'label'), ';', '"', '');
        foreach ($this->rows as $row) {
            fputcsv($out, array_map(static fn (string $k, array $c) => Format::plain($c['type'], $row[$k] ?? null), array_keys($this->columns), $this->columns), ';', '"', '');
        }
        fputcsv($out, $this->totalLine(static fn (string $type, mixed $v) => Format::plain($type, $v)), ';', '"', '');
        fclose($out);
    }

    private function xlsx(string $path): void
    {
        $writer = new Writer(new Options(pageSetup: new PageSetup(PageOrientation::LANDSCAPE, PaperSize::A4, fitToHeight: 0, fitToWidth: 1)));
        $writer->openToFile($path);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName(mb_substr($this->report->info['title'], 0, 31));
        $keys = array_keys($this->columns);
        $count = count($keys);
        $header = 4;
        $first = $header + 1;
        $last = $header + count($this->rows);

        $writer->addRow(new Row([new StringCell($this->report->info['title'], new Style(fontBold: true, fontSize: 14))]));
        $writer->addRow(new Row([new StringCell($this->description . ' · ' . $this->by, new Style(fontItalic: true, fontSize: 10, fontColor: '666666'))]));
        $writer->addRow(new Row([]));
        $dark = new Style(fontBold: true, fontColor: 'FFFFFF', backgroundColor: '1F1F1F');
        $writer->addRow(new Row(array_map(static fn (array $c) => new StringCell($c['label'], $dark), array_values($this->columns))));
        $styles = array_map(static fn (array $c) => isset(self::XLSX_FORMATS[$c['type']]) ? new Style(format: self::XLSX_FORMATS[$c['type']]) : null, $this->columns);
        foreach ($this->rows as $row) {
            $writer->addRow(new Row(array_map(fn (string $k) => self::cell($row[$k] ?? null, $this->columns[$k]['type'], $styles[$k]), $keys)));
        }

        $line = new Border(new BorderPart(BorderName::TOP, '1F1F1F', BorderWidth::MEDIUM, BorderStyle::SOLID));
        $cells = [];
        foreach ($keys as $i => $k) {
            $style = new Style(fontBold: true, backgroundColor: 'EEEEEE', border: $line, format: self::XLSX_FORMATS[$this->columns[$k]['type']] ?? null);
            $letter = self::letter($i);
            $cells[] = match (true) {
                $i === 0 => new StringCell('Total', $style),
                !empty($this->columns[$k]['sum']) && $this->rows !== [] => new FormulaCell("=SUM({$letter}{$first}:{$letter}{$last})", null, $style),
                default => self::cell($this->totals[$k] ?? null, $this->columns[$k]['type'], $style),
            };
        }
        $writer->addRow(new Row($cells));
        if ($this->note !== '') {
            $writer->addRow(new Row([]));
            $writer->addRow(new Row([new StringCell($this->note, new Style(fontItalic: true, fontSize: 10, fontColor: '666666'))]));
        }

        foreach ($keys as $i => $k) {
            $sheet->setColumnWidth($this->columns[$k]['type'] === 'text' ? ($i < 2 ? 34 : 22) : 15, $i + 1);
        }
        $sheet->setSheetView(new SheetView(freezeRow: $first));
        $sheet->setAutoFilter(new AutoFilter(0, $header, $count - 1, max($header, $last)));
        $sheet->setPrintTitleRows($header . ':' . $header);
        $writer->close();
    }

    private function pdf(string $path): void
    {
        if (count($this->rows) > self::PDF_MAX_ROWS) {
            throw new \RuntimeException('O PDF tem no máximo ' . self::PDF_MAX_ROWS . ' linhas. Use o Excel para relatórios grandes.');
        }
        $logo = Config::get('store/info/logo');
        $logoFile = $logo ? Config::mediaDir() . $logo : '';
        $data = [
            'title' => $this->report->info['title'], 'description' => $this->description, 'by' => $this->by, 'note' => $this->note,
            'columns' => $this->columns, 'rows' => $this->rows, 'tiles' => $this->tiles,
            'total' => $this->totalLine(static fn (string $type, mixed $v) => $v === null || $v === '' ? '' : Format::show($type, $v)),
            'store' => (string) (Config::get('store/info/name') ?: 'Iter Store'),
            'logo' => is_file($logoFile) ? $logoFile : \getPath('public') . 'imagem' . DIRECTORY_SEPARATOR . 'logo.png',
        ];
        $html = (static function (array $data): string {
            extract($data);
            ob_start();
            require (new ComponentResolver())->resolveTemplate('report/pdf');

            return (string) ob_get_clean();
        })($data);

        $options = new PdfOptions();
        $options->setChroot([\getPath('public')]);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text(34, $canvas->get_height() - 28, $data['store'] . ' · ' . $this->report->info['title'], $font, 7, [0.45, 0.45, 0.45]);
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 28, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 7, [0.45, 0.45, 0.45]);
        file_put_contents($path, $pdf->output());
    }

    /** the totals' line: "Total" first, then each column's total (or nothing) */
    private function totalLine(callable $format): array
    {
        $line = [];
        foreach (array_keys($this->columns) as $i => $k) {
            $line[] = $i === 0 ? 'Total' : $format($this->columns[$k]['type'], $this->totals[$k] ?? null);
        }

        return $line;
    }

    /** text always as text (a name starting with "=" is not a formula), numbers as numbers */
    private static function cell(mixed $value, string $type, ?Style $style): Cell
    {
        return match (true) {
            $value === null || $value === '' => Cell::fromValue(null, $style),
            in_array($type, ['money', 'pct', 'int', 'qty'], true) => Cell::fromValue((float) $value, $style),
            in_array($type, ['date', 'datetime', 'age'], true) => new StringCell(Format::show($type, $value), $style),
            default => new StringCell((string) $value, $style),
        };
    }

    private static function letter(int $index): string
    {
        $letter = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letter = chr(65 + ($n - 1) % 26) . $letter;
        }

        return $letter;
    }
}
