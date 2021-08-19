<?php
namespace Csgt\Face;

// use Elibyy\TCPDF\Facades\TCPDF as PDF;
use TCPDF;

class FormatoTicket
{
    public static function generar($params)
    {
        $factura     = $params['factura'];
        $empresa     = $params['empresa'];
        $reimpresion = $params['reimpresion'];
        $items       = $params['items'];
        $descuentos  = $params['descuentos'];

        $pdf = new TCPDF;
        $pdf->setmargins(8, 0, 8);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->setFont('helvetica', '', 7);
        $pdf->addPage('P', [66.5, 230]);

        $y = 5;
        $pdf->image($empresa['logo'], 5, $y, 20, 0, '', '', '', false, 300, 'C');

        $y = 30;
        $pdf->multicell(0, 1, mb_strtoupper($empresa['sucursal']['nombre_establecimiento']), 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 6;
        $pdf->multiCell(0, 1, 'NIT:' . $params['nit'], 0, 'C', 0, 2, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, mb_strtoupper($empresa['sucursal']['direccion']), 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, mb_strtoupper($empresa['departamento']), 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 10;

        $pdf->multiCell(0, 1, 'DOCUMENTO TRIBUTARIO ELECTRONICO', 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, 'FACTURA', 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 8;

        $pdf->multiCell(0, 1, 'NUMERO DE AUTORIZACION:', 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, $reimpresion['uuid'], 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, 'SERIE: ' . $reimpresion['serie'], 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, 'NO.: ' . $reimpresion['documento'], 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 10;

        $pdf->multiCell(0, 1, 'Sujeto a pagos trimestrales ISR', 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 8;

        $pdf->multiCell(0, 1, 'FECHA DE EMISION ' . $reimpresion['fecha'], 0, 'C', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, 'NIT: ' . $factura['nit'], 0, 'L', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, 'NOMBRE: ' . $factura['nombre'], 0, 'L', 0, 1, 5, $y, true, 0);
        $y += 4;
        $pdf->multiCell(0, 1, 'DIRECCION: ' . $factura['direccion'], 0, 'L', 0, 1, 5, $y, true, 0);
        $y += 8;

        $pdf->setFont('helvetica', 'B', 7);
        $pdf->multiCell(9, 1, 'CANT', 'TB', 'L', 0, 1, 5, $y, true, 0);
        $pdf->multiCell(34, 1, 'DESCRIPCION', 'TB', 'L', 0, 1, 13, $y, true, 0);
        $pdf->multiCell(13, 1, 'TOTAL', 'TB', 'R', 0, 1, 47, $y, true, 0);
        $pdf->setFont('helvetica', '', 7);
        $y += 4;

        $sub_total = 0;
        $count     = 0;
        foreach ($items as $item) {
            $count++;

            $border = 0;
            if ($count == count($items)) {
                $border = 'B';
            }

            $item_total = ($item['cantidad'] * $item['precio']);

            $pdf->multiCell(9, 1, $item['cantidad'], $border, 'C', 0, 1, 5, $y, true, 0);
            $pdf->multiCell(34, 1, mb_strtoupper($item['descripcion']), $border, 'L', 0, 1, 13, $y, true, 0);
            $pdf->multiCell(13, 1, number_format($item_total, 2), $border, 'R', 0, 1, 47, $y, true, 0);

            $y += 6;
            $sub_total += $item_total;
        }
        $y += 4;

        $pdf->multiCell(35, 1, 'SUBTOTAL:', 0, 'R', 0, 1, 5, $y, true, 0);
        $pdf->setFont('helvetica', 'B', 7);
        $pdf->multiCell(0, 1, 'Q ' . number_format($sub_total, 2), 0, 'R', 0, 1, 40, $y, true, 0);
        $pdf->setFont('helvetica', '', 7);
        $y += 4;
        $pdf->multiCell(35, 1, 'DESCUENTO:', 0, 'R', 0, 1, 5, $y, true, 0);
        $pdf->setFont('helvetica', 'B', 7);
        $pdf->multiCell(0, 1, 'Q ' . number_format($descuentos['SumaDeDescuentos'], 2), 0, 'R', 0, 1, 40, $y, true, 0);
        $pdf->setFont('helvetica', '', 7);
        $y += 4;
        $pdf->multiCell(35, 1, 'TOTAL:', 0, 'R', 0, 1, 5, $y, true, 0);
        $pdf->setFont('helvetica', 'B', 7);
        $pdf->multiCell(0, 1, 'Q ' . number_format($sub_total + $descuentos['SumaDeDescuentos'], 2), 0, 'R', 0, 1, 40, $y, true, 0);
        $y += 10;

        $pdf->multiCell(0, 1, 'Fecha de certificación: ' . $reimpresion['fecha_certificacion'], 0, 'L', 0, 1, 5, $y, true, 0);

        $file = $pdf->output('doc.pdf', 'S');

        return [
            'xml' => '',
            'pdf' => base64_encode($file),
        ];
    }
}
