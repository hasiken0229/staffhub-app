<?php

namespace App\Services\Reports;

use TCPDF;

final class MonthlyWorksReportPdf extends TCPDF
{
    public function __construct(private readonly array $identity, private readonly string $reportFont)
    {
        parent::__construct('L', 'mm', 'A4', true, 'UTF-8', false);
        $this->setPrintHeader(false);
        $this->setPrintFooter(true);
    }

    public function Footer(): void
    {
        $this->SetFont($this->reportFont, '', 8);
        $this->SetTextColor(71, 85, 105);
        $this->SetDrawColor(148, 163, 184);
        $this->Line(8, $this->getPageHeight() - 16, $this->getPageWidth() - 8, $this->getPageHeight() - 16);
        $this->SetXY(8, -14);
        $this->Cell(0, 4.5, '月次勤務表 '.$this->identity['month'].'　社員番号: '.$this->identity['code'].'　氏名: '.$this->identity['name'], 0, 1, 'L', false, '', 1);
        $this->Cell(0, 4.5, '集計期間: '.$this->identity['start'].' ～ '.$this->identity['end'].'　'.$this->getAliasNumPage().' / '.$this->getAliasNbPages().' ページ', 0, 0, 'L', false, '', 1);
    }
}
