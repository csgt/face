<?php
namespace Csgt\Face;

use Log;
use Exception;
use SoapClient;
use DOMDocument;
use SimpleXMLElement;
use Csgt\Components\Components;

class Face
{
    private $resolucion = [
        'tipo'                   => 'FACE63',
        'serie'                  => '',
        'correlativo'            => 0,
        'numeroautorizacion'     => '',
        'fecharesolucion'        => '',
        'rangoinicialautorizado' => 0,
        'rangofinalautorizado'   => 0,
        'proveedorface'          => 'g4s', //g4s, gyt
    ];

    private $factura = [
        'referenciainterna' => 0,
        'nit'               => '',
        'nombre'            => '',
        'direccion'         => '',
        'moneda'            => 'GTQ', //GTQ, USD
    ];

    private $totales = [
        'monto'           => 0,
        'descuento'       => 0,
        'valorSinDRMonto' => 0,
        'valorConDRMonto' => 0,
        'impuestos'       => 0,
    ];

    private $empresa = [
        'nombrecomercial'        => '',
        'direccion'              => '',
        'codigopostal'           => '',
        'regimen'                => 'PAGO_TRIMESTRAL', //FACE: [RET_DEFINITIVA, PAGO_TRIMESTRAL],
        'afiliacioniva'          => 'GEN', //FEL: [GEN, PEQ]
        'retencioniva'           => false,
        'codigoestablecimiento'  => 1,
        'dispositivoelectronico' => '001',
        'moneda'                 => 'GTQ',
        'iva'                    => 12,
        'codigopais'             => 'GT',
        'nit'                    => '',
        'footer'                 => '',
        'requestor'              => '',
        'usuario'                => '',
        'formatos'               => 'XML',
        'test'                   => false,
    ];

    private $reimpresion = [
        'serie'       => '',
        'correlativo' => '',
    ];

    private $anulacion = [
        'serie'        => '',
        'correlativo'  => '',
        'razon'        => 'Anulación',
        'fecha'        => '',
        'autorizacion' => '',
    ];

    private $items    = [];
    private $detalles = [];
    // private $descuentos = ['SumaDeDescuentos' => 0];
    private $descuentosNKeys = 1;
    private $descuentos      = [
        'SumaDeDescuentos' => 0,
    ];

    public function generar()
    {
        // $fels  = ['FACT', 'FCAM', 'FPEQ', 'FCAP', 'FESP', 'NABN', 'RDON', 'RECI', 'NDEB', 'NCRE'];
        $fels  = ['FACT', 'FPEQ', 'NCRE'];
        $faces = ['FACE63', 'FACE66', 'NCE64'];

        if ($this->empresa['dispositivoelectronico'] == '') {
            throw new Exception('El dispositivo electrónico es requerido');
        }
        if ($this->empresa['requestor'] == '') {
            throw new Exception('El requestor es requerido');
        }
        if ($this->empresa['usuario'] == '') {
            throw new Exception('El usuario es requerido');
        }
        if ($this->empresa['codigoestablecimiento'] == '') {
            throw new Exception('El código de establecimiento es requerido');
        }
        if ($this->empresa['nit'] == '') {
            throw new Exception('El NIT de la empresa emisora es requerido');
        }
        if ($this->factura['nit'] == '') {
            throw new Exception('El NIT del comprador es requerido');
        }

        if (in_array($this->resolucion['tipo'], $fels)) {
            if (count($this->items) == 0) {
                throw new Exception('Se debe agregar al menos un detalle a la FEL');
            }
            $this->fel();
        } else if (in_array($this->resolucion['tipo'], $faces)) {
            $this->face();
        } else {
            throw new Exception('El tipo de documento no es conocido');
        }
    }

    public function fel()
    {
        if ($this->empresa['nombrecomercial'] == '') {
            throw new Exception('El nombre comercial del emisor es requerido');
        }

        if ($this->empresa['direccion'] == '') {
            throw new Exception('La dirección del emisor es requerido');
        }

        $factorIVA      = 1 + ($this->empresa['iva'] / 100);
        $globalDiscount = isset($this->factura['descuentoGlobal']) ? $this->factura['descuentoGlobal'] : 0;

        $xw = xmlwriter_open_memory();
        xmlwriter_set_indent($xw, 1);
        $res = xmlwriter_set_indent_string($xw, '    ');
        xmlwriter_start_document($xw, '1.0', 'UTF-8');

        xmlwriter_start_element($xw, 'dte:GTDocumento'); //<GTDocumento>

        xmlwriter_start_attribute($xw, 'xmlns:cfe');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/face2/ComplementoFacturaEspecial/0.1.0');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'xmlns:cno');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/face2/ComplementoReferenciaNota/0.1.0');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'xmlns:cex');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/face2/ComplementoExportaciones/0.1.0');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'xmlns:cfc');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/CompCambiaria/0.1.0');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'xmlns:dte');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/0.1.0');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'xmlns:ds');
        xmlwriter_text($xw, 'http://www.w3.org/2000/09/xmldsig#');
        xmlwriter_end_attribute($xw);
        // xmlwriter_start_attribute($xw, 'xmlns:xsi');
        // xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema-instance');
        // xmlwriter_end_attribute($xw);
        // xmlwriter_start_attribute($xw, 'xsi:schemaLocation');
        // xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/0.1.0');
        // xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'Version');
        xmlwriter_text($xw, '0.4');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:SAT'); //<SAT>

        xmlwriter_start_attribute($xw, 'ClaseDocumento');
        xmlwriter_text($xw, 'dte');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:DTE'); //<DTE>
        xmlwriter_start_attribute($xw, 'ID');
        xmlwriter_text($xw, 'DatosCertificados');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:DatosEmision'); //<DatosEmision>
        xmlwriter_start_attribute($xw, 'ID');
        xmlwriter_text($xw, 'DatosEmision');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:DatosGenerales'); //<DatosGenerales>
        xmlwriter_start_attribute($xw, 'FechaHoraEmision');
        xmlwriter_text($xw, date_create()->format('Y-m-d\TH:i:s'));
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NumeroAcceso');
        xmlwriter_text($xw, 550000000);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'CodigoMoneda');
        xmlwriter_text($xw, $this->factura['moneda']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'Tipo');
        xmlwriter_text($xw, $this->resolucion['tipo']);
        xmlwriter_end_attribute($xw);
        xmlwriter_end_element($xw); //</DatosGenerales>

        xmlwriter_start_element($xw, 'dte:Emisor'); //<Emisor>
        xmlwriter_start_attribute($xw, 'CorreoEmisor');
        xmlwriter_text($xw, 'cliente@mail.com'); //TODO
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'CodigoEstablecimiento');
        xmlwriter_text($xw, $this->empresa['codigoestablecimiento']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NITEmisor');
        xmlwriter_text($xw, $this->empresa['nit']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NombreComercial');
        xmlwriter_text($xw, $this->empresa['nombrecomercial']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'AfiliacionIVA');
        xmlwriter_text($xw, $this->empresa['afiliacioniva']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NombreEmisor');
        xmlwriter_text($xw, $this->empresa['nombrecomercial']);
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:DireccionEmisor'); //<DireccionEmisor>
        xmlwriter_start_element($xw, 'dte:Direccion'); //<Direccion />
        xmlwriter_text($xw, $this->empresa['direccion']);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:CodigoPostal'); //<CodigoPostal />
        xmlwriter_text($xw, $this->empresa['codigopostal']);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:Municipio'); //<Municipio />
        xmlwriter_text($xw, 'Guatemala');
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:Departamento'); //<Departamento />
        xmlwriter_text($xw, 'Guatemala');
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:Pais'); //<Pais />
        xmlwriter_text($xw, 'GT');
        xmlwriter_end_element($xw);
        xmlwriter_end_element($xw); //</DireccionEmisor>

        xmlwriter_end_element($xw); //</Emisor>

        xmlwriter_start_element($xw, 'dte:Receptor'); //<Receptor>
        xmlwriter_start_attribute($xw, 'IDReceptor');
        xmlwriter_text($xw, $this->fixnit($this->factura['nit']));
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'CorreoReceptor');
        xmlwriter_text($xw, '');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NombreReceptor');
        xmlwriter_text($xw, $this->factura['nombre']);
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:DireccionReceptor'); //<DireccionReceptor>
        xmlwriter_start_element($xw, 'dte:Direccion'); //<Direccion />
        xmlwriter_text($xw, $this->factura['direccion']);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:CodigoPostal'); //CodigoPostal
        xmlwriter_text($xw, '01001');
        xmlwriter_end_element($xw); //CodigoPostal
        xmlwriter_start_element($xw, 'dte:Municipio'); //Municipio
        xmlwriter_text($xw, 'Guatemala');
        xmlwriter_end_element($xw); //Municipio
        xmlwriter_start_element($xw, 'dte:Departamento'); //Departamento
        xmlwriter_text($xw, 'Guatemala');
        xmlwriter_end_element($xw); //Departamento
        xmlwriter_start_element($xw, 'dte:Pais'); //Pais
        xmlwriter_text($xw, 'GT');
        xmlwriter_end_element($xw); //Pais
        xmlwriter_end_element($xw); //DireccionReceptor
        xmlwriter_end_element($xw); //</Receptor>

        if ($this->resolucion['tipo'] != 'NCRE') {
            xmlwriter_start_element($xw, 'dte:Frases'); //Frases

            //FRASE ISR
            xmlwriter_start_element($xw, 'dte:Frase'); //<Frase>
            xmlwriter_start_attribute($xw, 'CodigoEscenario');
            xmlwriter_text($xw, $this->empresa['regimen'] == 'PAGO_TRIMESTRAL' ? 1 : 2);
            xmlwriter_end_attribute($xw);
            xmlwriter_start_attribute($xw, 'TipoFrase');
            xmlwriter_text($xw, '1');
            xmlwriter_end_attribute($xw);
            xmlwriter_end_element($xw); //</Frase>

            //FRASE IVA
            if ($this->empresa['retencioniva']) {
                xmlwriter_start_element($xw, 'dte:Frase'); //<Frase>
                xmlwriter_start_attribute($xw, 'CodigoEscenario');
                xmlwriter_text($xw, 1);
                xmlwriter_end_attribute($xw);
                xmlwriter_start_attribute($xw, 'TipoFrase');
                xmlwriter_text($xw, '2');
                xmlwriter_end_attribute($xw);
                xmlwriter_end_element($xw); //</Frase>
            }

            xmlwriter_end_element($xw); //Frases
        }

        xmlwriter_start_element($xw, 'dte:Items'); //Items
        $i          = 1;
        $gTotal     = 0;
        $gImpuestos = 0;

        foreach ($this->items as $item) {
            $monto    = $item['precio'] * $item['cantidad'];
            $discount = $item['descuento'];
            $gTotal += $monto;

            if ($item['precio'] > 0 && $globalDiscount > 0) {
                $diff                  = $monto - $discount;
                $globalDiscountPortion = 0;
                if ($globalDiscount >= $diff) {
                    $globalDiscountPortion = $diff;
                } else {
                    $globalDiscountPortion = $globalDiscount;
                }
                $globalDiscount -= $globalDiscountPortion;
                $discount += $globalDiscountPortion;
            }

            $montoGravable = round(($monto / $factorIVA) - $discount, 2);
            $impuestos     = $montoGravable * ($this->empresa['iva'] / 100);
            $gImpuestos += $impuestos;

            xmlwriter_start_element($xw, 'dte:Item'); //Item

            xmlwriter_start_attribute($xw, 'NumeroLinea');
            xmlwriter_text($xw, $i);
            xmlwriter_end_attribute($xw);
            xmlwriter_start_attribute($xw, 'BienOServicio');
            xmlwriter_text($xw, substr($item['tipo'], 0, 1)); //B,S
            xmlwriter_end_attribute($xw);

            xmlwriter_start_element($xw, 'dte:Cantidad');
            xmlwriter_text($xw, $item['cantidad']);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:UnidadMedida');
            xmlwriter_text($xw, $item['unidad']);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:Descripcion');
            xmlwriter_text($xw, $item['descripcion'] . $item['descripcionAmpliada']);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:PrecioUnitario');
            xmlwriter_text($xw, $item['precio']);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:Precio');
            xmlwriter_text($xw, $monto);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:Descuento');
            xmlwriter_text($xw, $item['descuento']);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:Impuestos'); //<Impuestos>

            xmlwriter_start_element($xw, 'dte:Impuesto'); //<Impuesto>

            xmlwriter_start_element($xw, 'dte:NombreCorto');
            xmlwriter_text($xw, 'IVA');
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:CodigoUnidadGravable');
            xmlwriter_text($xw, 1);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:MontoGravable');
            xmlwriter_text($xw, $montoGravable);
            xmlwriter_end_element($xw);

            xmlwriter_start_element($xw, 'dte:MontoImpuesto');
            xmlwriter_text($xw, $impuestos);
            xmlwriter_end_element($xw);

            xmlwriter_end_element($xw); //</Impuesto>
            xmlwriter_end_element($xw); //</Impuestos>

            xmlwriter_start_element($xw, 'dte:Total');
            xmlwriter_text($xw, $monto);
            xmlwriter_end_element($xw);

            xmlwriter_end_element($xw); //Item
            $i++;
        }
        xmlwriter_end_element($xw); //Items

        xmlwriter_start_element($xw, 'dte:Totales'); //<Totales>
        xmlwriter_start_element($xw, 'dte:TotalImpuestos'); //<TotalImpuestos>

        xmlwriter_start_element($xw, 'dte:TotalImpuesto'); //<TotalImpuesto>
        xmlwriter_start_attribute($xw, 'NombreCorto');
        xmlwriter_text($xw, 'IVA');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'TotalMontoImpuesto');
        xmlwriter_text($xw, $gImpuestos);
        xmlwriter_end_attribute($xw);
        xmlwriter_end_element($xw); //</TotalImpuesto>
        xmlwriter_end_element($xw); //</TotalImpuestos>

        xmlwriter_start_element($xw, 'dte:GranTotal'); //<GranTotal>
        xmlwriter_text($xw, $gTotal);
        xmlwriter_end_element($xw); //</GranTotal>
        xmlwriter_end_element($xw); //</Totales>

        if ($this->resolucion['tipo'] == 'NCRE') {
            xmlwriter_start_element($xw, 'dte:Complementos'); //<Complementos>
            xmlwriter_start_element($xw, 'dte:Complemento'); //<Complemento>

            xmlwriter_start_attribute($xw, 'IDComplemento'); //IDComplemento
            xmlwriter_text($xw, 'ReferenciasNota');
            xmlwriter_end_attribute($xw); //IDComplemento

            xmlwriter_start_attribute($xw, 'NombreComplemento'); //NombreComplemento
            xmlwriter_text($xw, 'ReferenciasNota');
            xmlwriter_end_attribute($xw); //NombreComplemento

            xmlwriter_start_attribute($xw, 'URIComplemento'); //URIComplemento
            xmlwriter_text($xw, 'http://www.sat.gob.gt/face2/ComplementoReferenciaNota/0.1.0');
            xmlwriter_end_attribute($xw); //URIComplemento

            xmlwriter_start_element($xw, 'ReferenciasNota'); //<ReferenciasNota>

            xmlwriter_start_attribute($xw, 'xmlns:xsi'); //xmlns:xsi
            xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema-instance');
            xmlwriter_end_attribute($xw); //xmlns:xsi

            xmlwriter_start_attribute($xw, 'xmlns:xsd'); //xmlns:xsd
            xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema');
            xmlwriter_end_attribute($xw); //xmlns:xsd

            xmlwriter_start_attribute($xw, 'xmlns'); //xmlns
            xmlwriter_text($xw, 'http://www.sat.gob.gt/face2/ComplementoReferenciaNota/0.1.0');
            xmlwriter_end_attribute($xw); //xmlns

            xmlwriter_start_attribute($xw, 'Version'); //Version
            xmlwriter_text($xw, '1');
            xmlwriter_end_attribute($xw); //Version
            xmlwriter_start_attribute($xw, 'NumeroAutorizacionDocumentoOrigen'); //NumeroAutorizacionDocumentoOrigen
            xmlwriter_text($xw, $this->anulacion['autorizacion']);
            xmlwriter_end_attribute($xw); //NumeroAutorizacionDocumentoOrigen

            xmlwriter_start_attribute($xw, 'SerieDocumentoOrigen'); //SerieDocumentoOrigen
            xmlwriter_text($xw, $this->anulacion['serie']);
            xmlwriter_end_attribute($xw); //SerieDocumentoOrigen

            xmlwriter_start_attribute($xw, 'NumeroDocumentoOrigen'); //NumeroDocumentoOrigen
            xmlwriter_text($xw, $this->anulacion['correlativo']);
            xmlwriter_end_attribute($xw); //NumeroDocumentoOrigen

            xmlwriter_start_attribute($xw, 'FechaEmisionDocumentoOrigen'); //FechaEmisionDocumentoOrigen
            xmlwriter_text($xw, $this->anulacion['fecha']);
            xmlwriter_end_attribute($xw); //FechaEmisionDocumentoOrigen

            xmlwriter_start_attribute($xw, 'MotivoAjuste'); //MotivoAjuste
            xmlwriter_text($xw, $this->anulacion['razon']);
            xmlwriter_end_attribute($xw); //MotivoAjuste

            xmlwriter_end_element($xw); //</ReferenciasNota>
            xmlwriter_end_element($xw); //</Complemento>
            xmlwriter_end_element($xw); //</Complementos>
        }

        xmlwriter_end_element($xw); //DatosEmision
        xmlwriter_end_element($xw); //DTE
        xmlwriter_end_element($xw); //SAT
        xmlwriter_end_element($xw); //GTDocumento
        xmlwriter_end_document($xw);

        $this->sendXML(xmlwriter_output_memory($xw), 'fel');
        //echo xmlwriter_output_memory($xw);
    }

    public function face()
    {
        $this->generarDetallesFace();

        if (count($this->detalles) == 0) {
            throw new Exception('Se debe agregar al menos un detalle a la FACE');
        }

        $x = ['Version' => 3];

        if ($this->resolucion['correlativo'] !== 0) {
            $arr = [
                'AsignacionSolicitada' => [
                    'Serie'                  => $this->resolucion['serie'],
                    'NumeroDocumento'        => $this->resolucion['correlativo'],
                    'FechaEmision'           => date_create()->format('Y-m-d\TH:i:s'),
                    'NumeroAutorizacion'     => $this->resolucion['numeroautorizacion'],
                    'FechaResolucion'        => $this->resolucion['fecharesolucion'],
                    'RangoInicialAutorizado' => $this->resolucion['rangoinicialautorizado'],
                    'RangoFinalAutorizado'   => $this->resolucion['rangofinalautorizado'],
                ],
            ];
            $x = array_merge($x, $arr);
        }

        $arr = [
            'Encabezado' => [
                'TipoActivo'              => $this->resolucion['tipo'],
                'CodigoDeMoneda'          => $this->empresa['moneda'],
                'TipoDeCambio'            => 1,
                'InformacionDeRegimenIsr' => $this->empresa['regimen'],
                'ReferenciaInterna'       => $this->factura['referenciainterna'],
            ],
            'Vendedor'   => [
                'Nit'                     => $this->fixnit($this->empresa['nit'], true),
                'Idioma'                  => 'es',
                'CodigoDeEstablecimiento' => $this->empresa['codigoestablecimiento'],
                'DispositivoElectronico'  => $this->empresa['dispositivoelectronico'],
            ],
            'Comprador'  => [
                'Nit'    => $this->fixnit($this->factura['nit']),
                'Idioma' => 'es',
            ],
        ];

        $x = array_merge($x, $arr);

        if ($this->factura['nit'] == 'CF') {
            $x['Comprador'] = [
                'Nit'                => $this->factura['nit'],
                'NombreComercial'    => ($this->factura['nombre'] == '' ? 'CONSUMIDOR FINAL' : $this->factura['nombre']),
                'DireccionComercial' => [
                    'Direccion1'   => $this->factura['direccion'],
                    'Direccion2'   => '.',
                    'Municipio'    => 'GUATEMALA',
                    'Departamento' => 'GUATEMALA',
                    'CodigoDePais' => 'GT',
                ],
                'Idioma'             => 'es',
            ];
        }
        $x['Detalles'] = $this->detalles;

        $x['Totales'] = [
            'SubTotalSinDR' => number_format($this->totales['valorSinDRMonto'], 4, '.', ''),
        ];

        if (count($this->descuentos) > $this->descuentosNKeys) {
            $x['Totales']['DescuentosYRecargos'] = $this->descuentos;
        }

        $arr = [
            'SubTotalConDR' => number_format($this->totales['valorConDRMonto'], 4, '.', ''),
            'Impuestos'     => [
                'TotalDeImpuestos'      => number_format($this->totales['impuestos'], 4, '.', ''),
                'IngresosNetosGravados' => number_format($this->totales['valorConDRMonto'], 4, '.', ''),
                'TotalDeIVA'            => number_format($this->totales['impuestos'], 4, '.', ''),
                'Impuesto'              => [
                    'Tipo'  => 'IVA',
                    'Base'  => number_format($this->totales['valorConDRMonto'], 4, '.', ''),
                    'Tasa'  => $this->empresa['iva'],
                    'Monto' => number_format($this->totales['impuestos'], 4, '.', ''),
                ],
            ],
            'Total'         => number_format($this->totales['valorConDRMonto'] + $this->totales['impuestos'], 2, '.', ''),
            'TotalLetras'   => Components::numeroALetras($this->totales['valorConDRMonto'] + $this->totales['impuestos'], $this->empresa['moneda'], 2, false),
        ];

        $x['Totales'] = array_merge($x['Totales'], $arr);

        if ($this->empresa['footer'] != '') {
            $x['TextosDePie'] = [
                'Texto' => substr($this->empresa['footer'], 0, 1000),
            ];
        }

        $xml = new SimpleXMLElement("<FactDocGT xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns=\"http://www.fact.com.mx/schema/gt\" xsi:schemaLocation=\"http://www.fact.com.mx/schema/gt http://www.mysuitemex.com/fact/schema/fx_2013_gt_3.xsd\"></FactDocGT>");
        $this->array_to_xml($x, $xml);

        $xmlText = utf8_encode($xml->asXML());

        for ($i = count($this->detalles) - 1; $i >= 0; $i--) {
            $xmlText = strtr($xmlText, ['item' . $i => 'Detalle']);
            $xmlText = strtr($xmlText, ['desc_' . $i => 'DescuentoORecargo']);
        }

        return $this->sendXML($xmlText, 'face');
    }

    public function sendXML($aXml, $tipo = 'face')
    {
        if ($this->empresa['test']) {
            $url = config('csgtface.' . $tipo . '.testurl');
        } else {
            $url = config('csgtface.' . $tipo . '.url');
        }

        if ($tipo == 'fel') {
            $entity      = $this->empresa['nit'];
            $transaction = 'SYSTEM_REQUEST';
            $data1       = 'POST_DOCUMENT_SAT';
            $data2       = base64_encode($aXml);
            $data3       = $this->factura['referenciainterna'];
        } else {
            $entity      = $this->fixnit($this->empresa['nit'], true);
            $transaction = 'CONVERT_NATIVE_XML';
            $data1       = $aXml;
            $data2       = $this->empresa['formatos'];
            $data3       = '';
        }

        $username = $this->empresa['usuario'];
        if ($this->resolucion['proveedorface'] == 'gyt') {
            $username = $this->empresa['codigopais'] . '.' . $this->fixnit($this->empresa['nit']) . '.' . $this->empresa['usuario'];
        }

        $soapClient = new SoapClient($url, [
            "trace"      => true,
            "keep_alive" => false,
        ]);
        $info = $soapClient->__call("RequestTransaction", ["parameters" => [
            'Requestor'   => $this->empresa['requestor'],
            'Transaction' => $transaction,
            'Country'     => $this->empresa['codigopais'],
            'Entity'      => $entity,
            'User'        => $this->empresa['requestor'],
            'UserName'    => $username,
            'Data1'       => $data1,
            'Data2'       => $data2,
            'Data3'       => $data3,
        ]]);

        $result = $info->RequestTransactionResult;

        if ($result->Response->Result == false) {
            Log::error($aXml);
            Log::error(json_encode($result->Response));
            throw new Exception($result->Response->Description);
        } else {

            $uuid   = $result->Response->Identifier->DocumentGUID;
            $xml    = $result->ResponseData->ResponseData1;
            $xmlDoc = new DOMDocument();
            $xmlDoc->loadXML(base64_decode($xml));

            if ($tipo == 'fel') {
                $serie     = $result->Response->Identifier->Batch;
                $documento = $result->Response->Identifier->Serial;
                $firma     = $uuid;
                $id        = null;
                $nombre    = null;
                $direccion = null;
                $html      = null;
                $pdf       = null;
            } else {

                $invoice = $xmlDoc->getElementsByTagNameNS('urn:ean.ucc:pay:2', '*');
                $invoice = $invoice->item(0);

                $id       = $invoice->parentNode->getAttribute('Id');
                $buyer    = $invoice->getElementsByTagName('buyer');
                $nameaddr = $buyer[0]->getElementsByTagName('nameAndAddress');
                $nombre   = $nameaddr[0]->getElementsByTagName('name')[0]->nodeValue;
                $dir1     = $nameaddr[0]->getElementsByTagName('streetAddressOne')[0]->nodeValue;

                $dir2Node = $nameaddr[0]->getElementsByTagName('streetAddressTwo');
                $dir2     = $dir2Node->length > 0 ? $dir2Node[0]->nodeValue : null;

                $cae  = $xmlDoc->getElementsByTagName('CAE');
                $dcae = $cae[0]->getElementsByTagName('DCAE');
                $fcae = $cae[0]->getElementsByTagName('FCAE');

                $serie     = $dcae[0]->getElementsByTagName('Serie')[0]->nodeValue;
                $documento = $dcae[0]->getElementsByTagName('NumeroDocumento')[0]->nodeValue;
                $firma     = $fcae[0]->getElementsByTagName('SignatureValue')[0]->nodeValue;

                $html = $result->ResponseData->ResponseData2;
                $pdf  = $result->ResponseData->ResponseData3;

                $direccion = trim($dir1 . ($dir2 ? ' ' . $dir2 : ''));
            }

            $respuesta['id']        = $id;
            $respuesta['uuid']      = $uuid;
            $respuesta['serie']     = $serie;
            $respuesta['documento'] = $documento;
            $respuesta['firma']     = $firma;
            $respuesta['nombre']    = $nombre;
            $respuesta['direccion'] = $direccion;
            $respuesta['xml']       = $xml;
            $respuesta['html']      = $html;
            $respuesta['pdf']       = $pdf;

            return $respuesta;
        }
    }

    public function consultar()
    {
        if ($this->empresa['test']) {
            $url = config('csgtface.testurl');
        } else {
            $url = config('csgtface.url');
        }

        $username = $this->empresa['usuario'];
        if ($this->resolucion['proveedorface'] == 'gyt') {
            $username = $this->empresa['codigopais'] . '.' . $this->fixnit($this->empresa['nit']) . '.' . $this->empresa['usuario'];
        }

        $soapClient = new SoapClient($url, ["trace" => true, ""]);

        $info = $soapClient->__call("RequestTransaction", ["parameters" => [
            'Requestor'   => $this->empresa['requestor'],
            'Transaction' => 'GET_DOCUMENT',
            'Country'     => $this->empresa['codigopais'],
            'Entity'      => $this->fixnit($this->empresa['nit'], true),
            'User'        => $this->empresa['requestor'],
            'UserName'    => $username,
            'Data1'       => $this->reimpresion['uuid'],
            'Data2'       => '',
            'Data3'       => 'XML PDF',
        ],
        ]);

        $result = $info->RequestTransactionResult;

        if ($result->Response->Result == false) {
            throw new Exception($result->Response->Description);
        }

        return [
            'xml' => base64_decode($result->ResponseData->ResponseData1),
            'pdf' => $result->ResponseData->ResponseData3,
        ];
    }

    public function anular()
    {
        if ($this->anulacion['serie'] == '') {
            throw new Exception('La serie es requerida. Se debe correr el método setAnulacion');
        }

        if ($this->anulacion['correlativo'] == '') {
            throw new Exception('El número de correlativo es requerido. Se debe correr el método setAnulacion');
        }

        //Si es FEL
        if (in_array($this->resolucion['tipo'], $fels)) {
            if ($this->anulacion['numeroautorizacion'] == '') {
                throw new Exception('El número de autorización es requerido. Se debe correr el método setAnulacion');
            }

            if ($this->anulacion['fecha'] == '') {
                throw new Exception('El fecha del documento a anualr es requerida. Se debe correr el método setAnulacion');
            }

            $this->fel();

            return;
        }

        //Si es FACE
        if ($this->empresa['test']) {
            $url = config('csgtface.testurl');
        } else {
            $url = config('csgtface.url');
        }

        $username = $this->empresa['usuario'];
        if ($this->resolucion['proveedorface'] == 'gyt') {
            $username = $this->empresa['codigopais'] . '.' . $this->fixnit($this->empresa['nit']) . '.' . $this->empresa['usuario'];
        }

        $soapClient = new SoapClient($url, ["trace" => true, ""]);

        $parameters = [
            'Requestor'   => $this->empresa['requestor'],
            'Transaction' => 'CANCEL_XML',
            'Country'     => $this->empresa['codigopais'],
            'Entity'      => $this->fixnit($this->empresa['nit'], true),
            'User'        => $this->empresa['requestor'],
            'UserName'    => $username,
            'Data1'       => $this->anulacion['serie'],
            'Data2'       => $this->anulacion['correlativo'],
            'Data3'       => 'XML',
        ];
        Log::info($parameters);

        $info = $soapClient->__call("RequestTransaction", ["parameters" => $parameters]);

        $result = $info->RequestTransactionResult;

        if ($result->Response->Result == false) {
            throw new Exception($result->Response->Data);
        }

        return [
            'xml' => base64_decode($result->ResponseData->ResponseData1),
        ];
    }

    public function pdf()
    {
        return $this->consultar();
    }

    //Setters
    public function setDetalle($aCantidad, $aPrecioUnitario, $aDescripcion, $aDescripcionAmpliada = '', $aBienServicio = 'BIEN', $aDescuento = 0, $aExtras = '', $aUnidadMedida = 'Un', $aCodigoEAN = '00000000000000')
    {
        if ((float) $aCantidad == 0.0) {
            return false;
        }
        if (($aBienServicio != 'BIEN') && ($aBienServicio != 'SERVICIO')) {
            return false;
        }

        $this->items[] = [
            'cantidad'            => $aCantidad,
            'precio'              => $aPrecioUnitario,
            'descripcion'         => $aDescripcion,
            'descripcionAmpliada' => $aDescripcionAmpliada,
            'tipo'                => $aBienServicio,
            'descuento'           => $aDescuento,
            'extras'              => $aExtras,
            'unidad'              => $aUnidadMedida,
            'ean'                 => $aCodigoEAN,
        ];
    }

    public function setResolucion($aParams)
    {
        $validos = ['tipo', 'serie', 'correlativo', 'numeroautorizacion', 'fecharesolucion', 'rangoinicialautorizado', 'rangofinalautorizado'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->resolucion = array_merge($this->resolucion, $aParams);
    }

    public function setEmpresa($aParams)
    {
        $validos = ['regimen', 'codigoestablecimiento', 'dispositivoelectronico', 'moneda', 'iva', 'codigopais', 'nit', 'footer', 'requestor', 'usuario', 'test', 'formatos', 'afiliacioniva', 'nombrecomercial', 'direccion', 'retencioniva', 'codigopostal'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->empresa = array_merge($this->empresa, $aParams);
    }

    public function setFactura($aParams)
    {
        $validos = ['referenciainterna', 'nit', 'nombre', 'direccion', 'descuentoGlobal'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->factura = array_merge($this->factura, $aParams);

        $this->factura['direccion'] = ($this->factura['direccion'] == '' ? 'CIUDAD' : $this->factura['direccion']);
    }

    public function setReimpresion($aParams)
    {
        $validos = ['uuid'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->reimpresion = array_merge($this->reimpresion, $aParams);
    }

    public function setAnulacion($aParams)
    {
        $validos = ['serie', 'correlativo', 'razon', 'autorizacion', 'fecha'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->anulacion = array_merge($this->anulacion, $aParams);
    }

    public function setFormatos($aParams)
    {
        $validos = ['XML', 'PDF', 'HTML'];

        foreach ($aParams as $key) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->empresa['formatos'] = trim(implode(' ', $aParams));
    }

    //Funciones privadas
    private function generarDetallesFace()
    {
        $descuentoGlobal = isset($this->factura['descuentoGlobal']) ? $this->factura['descuentoGlobal'] : 0;
        foreach ($this->items as $item) {
            $aCantidad            = $item['cantidad'];
            $aPrecioUnitario      = $item['precio'];
            $aDescripcion         = $item['descripcion'];
            $aDescripcionAmpliada = $item['descripcionAmpliada'];
            $aBienServicio        = $item['tipo'];
            $aDescuento           = $item['descuento'];
            $aExtras              = $item['extras'];
            $aUnidadMedida        = $item['unidad'];
            $aCodigoEAN           = $item['ean'];

            if ($aDescripcion == '') {
                $aDescripcion = 'Por su compra';
            }

            $factorIVA = 1 + ($this->empresa['iva'] / 100);
            $monto     = $aPrecioUnitario * $aCantidad;

            if ($aPrecioUnitario > 0 && $descuentoGlobal > 0) {
                $resta                  = $monto - $aDescuento;
                $porcionDescuentoGlobal = 0;
                if ($descuentoGlobal >= $resta) {
                    $porcionDescuentoGlobal = $resta;
                } else {
                    $porcionDescuentoGlobal = $descuentoGlobal;
                }
                $descuentoGlobal -= $porcionDescuentoGlobal;
                $aDescuento += $porcionDescuentoGlobal;
            }

            $descuento        = $aDescuento / $factorIVA;
            $valorSinDRPrecio = $aPrecioUnitario / $factorIVA;
            $valorSinDRMonto  = $valorSinDRPrecio * $aCantidad;
            $valorConDRMonto  = ($monto / $factorIVA) - $descuento;
            $valorConDRPrecio = $valorConDRMonto / $aCantidad;
            $impuestos        = $valorConDRMonto * ($this->empresa['iva'] / 100);

            if ($aPrecioUnitario > 0) {
                $descuentotasa = $aDescuento * 100 / $aPrecioUnitario / $aCantidad;
            } else {
                $descuentotasa = 0;
            }

            $this->totales['monto'] += $monto;
            $this->totales['descuento'] += $descuento;
            $this->totales['valorSinDRMonto'] += $valorSinDRMonto;
            $this->totales['valorConDRMonto'] += $valorConDRMonto;
            $this->totales['impuestos'] += $impuestos;

            $detalle = [
                'Descripcion'    => trim(substr($aDescripcion, 0, 69)),
                'CodigoEAN'      => $aCodigoEAN,
                'UnidadDeMedida' => $aUnidadMedida,
                'Cantidad'       => $aCantidad,
                'ValorSinDR'     => [
                    'Precio' => number_format($valorSinDRPrecio, 4, '.', ''),
                    'Monto'  => number_format($valorSinDRMonto, 4, '.', ''),
                ],
            ];

            if ($aDescuento > 0) {
                $detalle['DescuentosYRecargos'] = [
                    'SumaDeDescuentos'  => number_format($descuento, 4, '.', ''),
                    'DescuentoORecargo' => [
                        'Operacion' => 'DESCUENTO',
                        'Servicio'  => 'ALLOWANCE_GLOBAL',
                        'Base'      => number_format($valorSinDRMonto, 4, '.', ''),
                        'Tasa'      => number_format($descuentotasa, 4, '.', ''),
                        'Monto'     => number_format($descuento, 4, '.', ''),
                    ],
                ];
                $this->descuentos['SumaDeDescuentos'] = number_format($this->descuentos['SumaDeDescuentos'] + $descuento, 4, '.', '');

                $tasaParaBusqueda = number_format($descuentotasa, 4, '.', '');

                $tasaFound = false;

                foreach ($this->descuentos as $key => &$value) {
                    if (is_array($value) && $value['Tasa'] === $tasaParaBusqueda) {
                        $value['Base']  = number_format(((float) $value['Base']) + $valorSinDRMonto, 4, '.', '');
                        $value['Monto'] = number_format(((float) $value['Monto']) + $descuento, 4, '.', '');
                        $tasaFound      = true;
                    }
                }

                if (!$tasaFound) {
                    $this->descuentos['desc_' . (count($this->descuentos) - $this->descuentosNKeys)] = [
                        'Operacion' => 'DESCUENTO',
                        'Servicio'  => 'ALLOWANCE_GLOBAL',
                        'Base'      => number_format($valorSinDRMonto, 4, '.', ''),
                        'Tasa'      => $tasaParaBusqueda,
                        'Monto'     => number_format($descuento, 4, '.', ''),
                    ];
                }
            }

            $detalle['ValorConDR'] = [
                'Precio' => number_format($valorConDRPrecio, 4, '.', ''),
                'Monto'  => number_format($valorConDRMonto, 4, '.', ''),
            ];

            $detalle['Impuestos'] = [
                'TotalDeImpuestos'      => number_format($impuestos, 4, '.', ''),
                'IngresosNetosGravados' => number_format($valorConDRMonto, 4, '.', ''),
                'TotalDeIVA'            => number_format($impuestos, 4, '.', ''),
                'Impuesto'              => [
                    'Tipo'  => 'IVA',
                    'Base'  => number_format($valorConDRMonto, 4, '.', ''),
                    'Tasa'  => $this->empresa['iva'],
                    'Monto' => number_format($impuestos, 4, '.', ''),
                ],
            ];
            $detalle['Categoria'] = $aBienServicio;

            if (trim($aDescripcionAmpliada) != '' || trim(substr($aDescripcion, 69))) {
                $detalle['TextosDePosicion']['Texto'] = trim(substr(trim(substr($aDescripcion, 69) . $aDescripcionAmpliada), 0, 999));
            }

            if ($aExtras != '') {
                $textos = [];
                $arr    = explode(PHP_EOL, $aExtras);
                foreach ($arr as $linea) {
                    $textos[] = $linea;
                }
                $detalle['TextosDePosicion'] = $textos;
            }

            $this->detalles[] = $detalle;
        }
    }

    private function array_to_xml($student_info, &$xml_student_info)
    {
        foreach ($student_info as $key => $value) {
            if (is_array($value)) {
                $key     = is_numeric($key) ? "item$key" : $key;
                $subnode = $xml_student_info->addChild($key);
                $this->array_to_xml($value, $subnode);
            } else {
                $key = is_numeric($key) ? "item$key" : $key;
                $xml_student_info->addChild($key, htmlspecialchars($value));
            }
        }
    }

    private function fixnit($aNit, $aPadding = false)
    {
        $nit = trim(str_replace('-', '', $aNit));
        //Solo GyT espera los nits con 12 ceros
        if ($aPadding) {
            $nit = str_pad($nit, 12, '0', STR_PAD_LEFT);
        }

        return $nit;
    }
}
