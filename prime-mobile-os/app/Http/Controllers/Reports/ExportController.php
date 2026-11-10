<?php

namespace App\Http\Controllers\Reports;

use App\Domain\Reports\Catalog\Registry;
use App\Filament\Reports\Pages\Reports;
use App\Support\Ui\Access;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel of a report (TZ 06 §5.1–5.2): sheet 1 — the table with a header of the report name, period and filters; money
 * as numbers in ₸ (not text), so Excel can sum them; sheet 2 — the extra tables. Closed columns are not in the file.
 */
class ExportController
{
    public function __invoke(Request $request)
    {
        abort_unless(Access::allows('report.export'), 403);
        $report = Registry::find((string) $request->query('r'));
        abort_unless($report && $report->allowed(), 403);
        $res = Reports::result($report, $request->user(), $request->query());
        activity('reports')->causedBy($request->user())->withProperties(['report' => $report->code(), 'period' => $res['period']->label()])->log('report.exported');

        $file = $report->code().'-'.str_replace(' ', '-', $report->title()).'-'.now()->format('Ymd-Hi').'.xlsx';
        $path = tempnam(sys_get_temp_dir(), 'rep');
        $w = new Writer;
        $w->openToFile($path);
        $bold = (new Style)->setFontBold();
        $w->addRow(Row::fromValues([$report->code().'. '.$report->title()], $bold));
        $w->addRow(Row::fromValues([__('Период').': '.$res['period']->label().' · '.__('выгружено').' '.now()->format('d.m.Y H:i')]));
        foreach ($res['tiles'] as $t) {
            $w->addRow(Row::fromValues([$t['label'], self::cell($t['value'], $t['kind'])]));
        }
        $w->addRow(Row::fromValues([]));
        self::table($w, $res['columns'], $res['rows'], $res['total'] ?? null, $bold);
        foreach ($res['extra'] as $x) {
            $w->addNewSheetAndMakeItCurrent()->setName(mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/u', '', $x['title']), 0, 31));
            self::table($w, $x['columns'], $x['rows'], null, $bold);
        }
        $w->close();

        return response()->download($path, $file, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    private static function table(Writer $w, array $cols, array $rows, ?array $total, Style $bold): void
    {
        $w->addRow(Row::fromValues(array_map(fn ($c) => $c['label'].(in_array($c['kind'], ['money'], true) ? ', ₸' : ''), array_values($cols)), $bold));
        foreach ($rows as $r) {
            $w->addRow(Row::fromValues(array_map(fn ($k) => self::cell($r[$k] ?? null, $cols[$k]['kind']), array_keys($cols))));
        }
        if ($total) {
            $w->addRow(Row::fromValues(array_map(fn ($k) => self::cell($total[$k] ?? null, $cols[$k]['kind']), array_keys($cols)), $bold));
        }
    }

    private static function cell($v, string $kind)
    {
        return match (true) {
            $v === null => '',
            $kind === 'money' && is_int($v) => round($v / 100, 2),
            $kind === 'usd' && is_int($v) => round($v / 100, 2),
            default => $v,
        };
    }
}
