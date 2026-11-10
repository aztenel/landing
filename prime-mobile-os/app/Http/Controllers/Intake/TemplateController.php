<?php

namespace App\Http\Controllers\Intake;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Template of the intake import (TZ 04 Э11.4, 01 П3.6). */
class TemplateController
{
    public function __invoke(): StreamedResponse
    {
        abort_unless(\App\Support\Ui\Access::allows('stock.receive'), 403);

        return response()->streamDownload(function () {
            $w = new Writer;
            $w->openToFile('php://output');
            $w->addRow(Row::fromValues(['Модель', 'Память', 'Цвет', 'IMEI 1', 'IMEI 2', 'Состояние (новый / б/у)', 'Цена закупа', 'Розница, ₸']));
            $w->addRow(Row::fromValues(['iPhone 15 Pro', '256 ГБ', 'Black Titanium', '356938035643809', '', 'новый', 1140, 649000]));
            $w->close();
        }, 'prihod-shablon.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
