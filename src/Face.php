<?php
namespace Csgt\Face;

use Log;
use SoapClient;
use DOMDocument;
use Carbon\Carbon;
use GuzzleHttp\Client;
use Csgt\Face\FormatoEmisor;
use SoftlogicGT\ValidationRulesGT\Rules\Dpi;
use SoftlogicGT\ValidationRulesGT\Rules\Nit;

class Face
{
    private $tipo               = 'fel';
    private const G4S           = 'g4s';
    private const Infile        = 'infile';
    private const Guatefacturas = 'guatefacturas';

    private $urls = [
        'fel' => [
            'g4s'           => [
                'testurl' => 'https://pruebasfel.g4sdocumenta.com/webservicefront/factwsfront.asmx?wsdl',
                'testnit' => 'http://pruebasfel.g4sdocumenta.com/ConsultaNIT/ConsultaNIT.asmx?wsdl',
                'url'     => 'https://fel.g4sdocumenta.com/webservicefront/factwsfront.asmx?wsdl',
                'nit'     => 'http://fel.g4sdocumenta.com/ConsultaNIT/ConsultaNIT.asmx?wsdl',
            ],
            'infile'        => [
                'testurl' => 'https://certificador.feel.com.gt/fel/procesounificado/transaccion/v2/xml',
                'testnit' => 'https://consultareceptores.feel.com.gt/rest/action',
                'url'     => 'https://certificador.feel.com.gt/fel/procesounificado/transaccion/v2/xml',
                'nit'     => 'https://consultareceptores.feel.com.gt/rest/action',
                'pdf'     => 'https://report.feel.com.gt/ingfacereport/ingfacereport_documento?uuid=',
            ],
            'guatefacturas' => [
                'testurl'      => 'https://dte.guatefacturas.com/webservices63/feltestSB/Guatefac?wsdl',
                'url'          => 'https://dte.guatefacturas.com/webservices63/feltestSB/Guatefac?wsdl',
                'testuser'     => 'usr_guatefac',
                'testpassword' => 'usrguatefac',
            ],
        ],
    ];

    private $proveedores = [
        self::G4S,
        self::Infile,
        self::Guatefacturas,
    ];

    private $resolucion = [
        'correlativo'            => 0,
        'fecharesolucion'        => '',
        'numeroautorizacion'     => '',
        'proveedorface'          => self::G4S, //g4s, infile, guatefacturas
        'rangofinalautorizado' => 0,
        'rangoinicialautorizado' => 0,
        'serie'                  => '',
        'tipo'                   => 'FACE63',
    ];

    // monedas: GTQ, USD
    // tipos: NIT, CUI, EXT
    private $factura = [
        'direccion'         => '',
        'moneda'            => 'GTQ',
        'nit'               => '',
        'nombre'            => '',
        'tipo'              => 'NIT',
        'referenciainterna' => 0,
    ];

    private $totales = [
        'descuento'       => 0,
        'impuestos'       => 0,
        'monto'           => 0,
        'valorConDRMonto' => 0,
        'valorSinDRMonto' => 0,
    ];

    private $empresa = [
        'afiliacioniva'          => 'GEN', //FEL: [GEN, PEQ]
        'codigoestablecimiento' => 1,
        'codigopais'             => 'GT',
        'codigopostal'           => '',
        'departamento'           => 'Guatemala',
        'direccion'              => '',
        'dispositivoelectronico' => '001',
        'email'                  => 'email@email.com',
        'firmaalias'             => '',
        'firmallave'             => '',
        'footer'                 => '',
        'formatos'               => 'XML',
        'iva'                    => 12,
        'moneda'                 => 'GTQ',
        'municipio'              => 'Guatemala',
        'nit'                    => '',
        'nombrecomercial'        => '',
        'nombreestablecimiento'  => '',
        'regimen'                => 'PAGO_TRIMESTRAL', //FACE: [RET_DEFINITIVA, PAGO_TRIMESTRAL],
        'requestor'             => '',
        'retencioniva'           => false,
        'test'                   => false,
        'usuario'                => '',
        'logo'                   => null,
        'totalletras'            => '',
    ];

    private $reimpresion = [
        'uuid'               => '',
        'fecha'              => '',
        'serie'              => '',
        'documento'          => '',
        'fechacertificacion' => '',
    ];

    private $anulacion = [
        'serie'        => '',
        'correlativo'  => '',
        'razon'        => 'Anulación',
        'fecha'        => '',
        'autorizacion' => '',
        'nit'          => '',
        'tipo'         => 'NIT',
        'uid'          => '',
    ];

    private $items           = [];
    private $detalles        = [];
    private $descuentosNKeys = 1;
    private $descuentos      = [
        'SumaDeDescuentos' => 0,
    ];

    public function buscarID($tipo, $numero)
    {
        switch ($tipo) {
            case 'NIT':
                return $this->buscarNit($numero);
                break;

            case 'CUI':
                return $this->buscarCui($numero);
                break;

            case 'EXT':
                return [
                    'nit'       => $numero,
                    'nombre'    => "",
                    'direccion' => null,
                ];
                break;

            default:
                abort(400, 'Tipo de documento inválido');
                break;
        }
    }

    //Este es para backwards compatibility en SM
    public function buscarDocumento($tipo, $numero)
    {
        return $this->buscarID($tipo, $numero);
    }

    public function buscarNit($nit)
    {
        $nit = $this->fixnit($nit);

        $validator = new Nit($nit);
        if (!$validator->passes('nit', $nit)) {
            abort(400, 'NIT no válido');
        }

        if (strlen($nit) <= 0) {
            abort(404, 'NIT no encontrado');
        }

        $arr = [];
        if ($this->empresa['requestor'] == '') {
            abort(400, 'El requestor es requerido');
        }
        switch ($this->resolucion['proveedorface']) {
            case self::G4S:
                if ($this->empresa['nit'] == '') {
                    abort(422, 'El NIT de la empresa emisora es requerido');
                }

                $params = [
                    'vNIT'      => $nit,
                    'Entity'    => $this->fixnit($this->empresa['nit']),
                    'Requestor' => $this->empresa['requestor'],
                ];
                $soap     = new SoapClient($this->urls['fel'][self::G4S]['nit']);
                $response = $soap->getNit($params);
                $response = $response->getNITResult->Response;
                if (!$response->Result) {
                    abort(404, "NIT no encontrado");
                }
                $nombre = html_entity_decode($response->nombre);
                $nombre = str_replace(',,', '|', $nombre);
                $nombre = str_replace(',', ' ', $nombre);
                $arr    = explode('|', $nombre);
                if (sizeof($arr) == 2) {
                    $nombre = $arr[1] . ", " . $arr[0];
                } else {
                    $nombre = str_replace('|', ',', $nombre);
                }

                $arr = [
                    'nit'       => $response->NIT,
                    'nombre'    => $nombre,
                    'direccion' => null,
                ];
                break;
            case self::Infile:
                if ($this->empresa['usuario'] == '') {
                    abort(422, 'El usuario de empresa emisora es requerido');
                }
                $params = [
                    'emisor_codigo' => $this->empresa['usuario'],
                    'emisor_clave'  => $this->empresa['requestor'],
                    'nit_consulta'  => $nit,
                ];

                $client   = new Client;
                $response = $client->post($this->urls['fel'][self::Infile]['nit'], [
                    'json' => $params,
                ]);

                $json = json_decode((string) $response->getBody());
                if ($json->mensaje != '') {
                    abort(404, $json->mensaje);
                }
                $arr = [
                    'nit'       => $json->nit,
                    'nombre'    => html_entity_decode($json->nombre),
                    'direccion' => null,
                ];
                break;

            default:
                abort(422, 'El proveedor es incorrecto');
                break;
        }

        return $arr;
    }

    public function buscarCui($cui)
    {
        $arr = [];

        $validator = new Dpi($cui);
        if (!$validator->passes('dpi', $cui)) {
            abort(400, 'CUI no válido');
        }

        if ($this->empresa['requestor'] == '') {
            abort(400, 'El requestor es requerido');
        }

        switch ($this->resolucion['proveedorface']) {
            case self::G4S:
                if ($this->empresa['nit'] == '') {
                    abort(422, 'El NIT de la empresa emisora es requerido');
                }

                $params = [
                    'Requestor'   => $this->empresa['requestor'],
                    'Transaction' => 'SYSTEM_REQUEST',
                    'Country'     => $this->empresa['codigopais'],
                    'Entity'      => $this->empresa['nit'],
                    'User'        => $this->empresa['requestor'],
                    'UserName'    => $this->empresa['usuario'],
                    'Data1'       => 'CONSULTA_CUI',
                    'Data2'       => $cui,
                    'Data3'       => "",
                ];

                $soap     = new SoapClient($this->urls['fel'][self::G4S]['url']);
                $response = $soap->RequestTransaction($params);
                $result   = $response->RequestTransactionResult;

                if ($result->Response->Result == false) {
                    abort(404, 'DPI Inválido');

                    return;
                }

                $data = json_decode($result->ResponseData->ResponseData1);
                if ($data->fallecido) {
                    abort(404, 'DPI Inválido');
                }

                return [
                    'nit'       => $data->CUI,
                    'nombre'    => $data->nombre,
                    'direccion' => null,
                ];
                break;

            case self::Infile:
                return [
                    'nit'       => $cui,
                    'nombre'    => "",
                    'direccion' => null,
                ];
                break;

            default:
                abort(422, 'El proveedor es incorrecto');
                break;
        }

        return $arr;
    }

    public function generar()
    {
        if ($this->empresa['requestor'] == '') {
            abort(400, 'El requestor es requerido');
        }
        if ($this->empresa['usuario'] == '') {
            abort(400, 'El usuario es requerido');
        }
        if ($this->empresa['nit'] == '') {
            abort(400, 'El NIT de la empresa emisora es requerido');
        }
        if ($this->factura['nit'] == '') {
            abort(400, 'El NIT del comprador es requerido');
        }

        switch ($this->tipo) {
            case 'fel':
                switch ($this->resolucion['proveedorface']) {
                    case self::Guatefacturas:
                        return $this->felGuatefacturas();
                        break;

                    default:
                        return $this->fel();
                        break;
                }
                break;
            default:
                abort(400, 'El tipo de documento no es conocido');
                break;
        }
    }

    public function felAnular()
    {
        if ($this->anulacion['fecha'] == '') {
            abort(400, 'El fecha del documento a anualar es requerida. Se debe correr el método setAnulacion');
        }

        if ($this->anulacion['nit'] == '') {
            abort(400, 'El NIT a anualar es requerido. Se debe correr el método setAnulacion');
        }

        if ($this->anulacion['uid'] == '') {
            abort(400, 'El UID del documento a anualar es requerido. Se debe correr el método setAnulacion');
        }

        $xw = xmlwriter_open_memory();
        xmlwriter_set_indent($xw, 1);
        $res = xmlwriter_set_indent_string($xw, '    ');
        xmlwriter_start_document($xw, '1.0', 'UTF-8');

        xmlwriter_start_element($xw, 'dte:GTAnulacionDocumento'); //<GTAnulacionDocumento>

        xmlwriter_start_attribute($xw, 'xmlns:dte');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/0.1.0');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'xmlns:ds');
        xmlwriter_text($xw, 'http://www.w3.org/2000/09/xmldsig#');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'xmlns:xsi');
        xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema-instance');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'Version');
        xmlwriter_text($xw, '0.1');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'xsi:schemaLocation');
        xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/0.1.0 GT_AnulacionDocumento-0.1.0.xsd');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:SAT'); //<SAT>

        xmlwriter_start_element($xw, 'dte:AnulacionDTE'); //<AnulacionDTE>
        xmlwriter_start_attribute($xw, 'ID');
        xmlwriter_text($xw, 'DatosCertificados');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_element($xw, 'dte:DatosGenerales'); //<DatosGenerales>

        xmlwriter_start_attribute($xw, 'FechaEmisionDocumentoAnular');
        xmlwriter_text($xw, $this->anulacion['fecha']);
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'FechaHoraAnulacion');
        xmlwriter_text($xw, date_create()->format('Y-m-d\TH:i:s'));
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'ID');
        xmlwriter_text($xw, 'DatosAnulacion');
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'IDReceptor');
        xmlwriter_text($xw, $this->anulacion['nit']);
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'MotivoAnulacion');
        xmlwriter_text($xw, $this->anulacion['razon']);
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'NITEmisor');
        xmlwriter_text($xw, $this->empresa['nit']); //TODO revisar
        xmlwriter_end_attribute($xw);

        xmlwriter_start_attribute($xw, 'NumeroDocumentoAAnular');
        xmlwriter_text($xw, $this->anulacion['uid']);
        xmlwriter_end_attribute($xw);
        xmlwriter_end_element($xw); //</DatosGenerales>

        xmlwriter_end_element($xw); //AnulacionDTE
        xmlwriter_end_element($xw); //SAT
        xmlwriter_end_element($xw); //GTAnulacionDocumento
        xmlwriter_end_document($xw);

        return $this->sendXML(xmlwriter_output_memory($xw), 'fel', 'anular');
        //echo xmlwriter_output_memory($xw);
    }

    public function fel()
    {
        if ($this->empresa['nombrecomercial'] == '') {
            abort(400, 'El nombre comercial del emisor es requerido');
        }

        if ($this->empresa['direccion'] == '') {
            abort(400, 'La dirección del emisor es requerido');
        }

        if ($this->resolucion['proveedorface'] == 'infile') {
            if ($this->empresa['firmallave'] == '') {
                abort(400, 'La llave para firma es requerida.  Revise su configuracion de empresa.');
            }
        }

        $factorIVA      = 1 + ($this->empresa['iva'] / 100);
        $globalDiscount = isset($this->factura['descuentoGlobal']) ? $this->factura['descuentoGlobal'] : 0;

        $xw = xmlwriter_open_memory();
        xmlwriter_set_indent($xw, 1);
        $res = xmlwriter_set_indent_string($xw, '    ');
        xmlwriter_start_document($xw, '1.0', 'UTF-8');

        xmlwriter_start_element($xw, 'dte:GTDocumento'); //<GTDocumento>

        xmlwriter_start_attribute($xw, 'xmlns:ds');
        xmlwriter_text($xw, 'http://www.w3.org/2000/09/xmldsig#');
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'xmlns:xsi');
        xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema-instance');
        xmlwriter_end_attribute($xw);

        //Encabezados version 0.1
        if ($this->resolucion['proveedorface'] == self::G4S) {
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
            xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/0.2.0');
            xmlwriter_end_attribute($xw);
            xmlwriter_start_attribute($xw, 'Version');
            xmlwriter_text($xw, '0.1');
            xmlwriter_end_attribute($xw);
        } else {

            //Encabezados Version 0.2

            xmlwriter_start_attribute($xw, 'xmlns:dte');
            xmlwriter_text($xw, 'http://www.sat.gob.gt/dte/fel/0.2.0');
            xmlwriter_end_attribute($xw);

            xmlwriter_start_attribute($xw, 'Version');
            xmlwriter_text($xw, '0.1');
            xmlwriter_end_attribute($xw);
        }
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
        xmlwriter_start_attribute($xw, 'CodigoMoneda');
        xmlwriter_text($xw, $this->factura['moneda']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'Tipo');
        xmlwriter_text($xw, $this->resolucion['tipo']);
        xmlwriter_end_attribute($xw);
        xmlwriter_end_element($xw); //</DatosGenerales>

        xmlwriter_start_element($xw, 'dte:Emisor'); //<Emisor>
        xmlwriter_start_attribute($xw, 'CorreoEmisor');
        xmlwriter_text($xw, $this->empresa['email']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'CodigoEstablecimiento');
        xmlwriter_text($xw, $this->empresa['codigoestablecimiento']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NITEmisor');
        xmlwriter_text($xw, $this->empresa['nit']);
        xmlwriter_end_attribute($xw);
        xmlwriter_start_attribute($xw, 'NombreComercial');
        xmlwriter_text($xw, $this->empresa['nombreestablecimiento']);
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
        xmlwriter_text($xw, $this->empresa['municipio']);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:Departamento'); //<Departamento />
        xmlwriter_text($xw, $this->empresa['departamento']);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'dte:Pais'); //<Pais />
        xmlwriter_text($xw, 'GT');
        xmlwriter_end_element($xw);
        xmlwriter_end_element($xw); //</DireccionEmisor>

        xmlwriter_end_element($xw); //</Emisor>

        xmlwriter_start_element($xw, 'dte:Receptor'); //<Receptor>

        $type = $this->factura['tipo'];
        if ($type != 'NIT') {
            xmlwriter_start_attribute($xw, 'TipoEspecial');
            xmlwriter_text($xw, $type);
            xmlwriter_end_attribute($xw);
        }

        xmlwriter_start_attribute($xw, 'IDReceptor');
        xmlwriter_text($xw, $this->factura['nit']);
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

        // if ($this->resolucion['tipo'] != 'NCRE') {
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
        // }

        xmlwriter_start_element($xw, 'dte:Items'); //Items
        $i          = 1;
        $gTotal     = 0;
        $gImpuestos = 0;

        foreach ($this->items as $item) {
            $monto    = $item['precio'] * $item['cantidad'];
            $discount = $item['descuento'];

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
            $discount = round($discount, 6);
            $gTotal += round($monto - $discount, 2);
            $montoGravable = round((($monto - $discount) / $factorIVA), 2);
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
            xmlwriter_text($xw, $discount);
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
            xmlwriter_text($xw, round($monto - $discount, 2));
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
        xmlwriter_text($xw, round($gTotal, 2));
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

            xmlwriter_start_element($xw, 'cno:ReferenciasNota'); //<cno:ReferenciasNota>

            xmlwriter_start_attribute($xw, 'xmlns:xsi'); //xmlns:xsi
            xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema-instance');
            xmlwriter_end_attribute($xw); //xmlns:xsi

            xmlwriter_start_attribute($xw, 'xmlns:xsd'); //xmlns:xsd
            xmlwriter_text($xw, 'http://www.w3.org/2001/XMLSchema');
            xmlwriter_end_attribute($xw); //xmlns:xsd

            xmlwriter_start_attribute($xw, 'xmlns:cno'); //xmlns
            xmlwriter_text($xw, 'http://www.sat.gob.gt/face2/ComplementoReferenciaNota/0.1.0');
            xmlwriter_end_attribute($xw); //xmlns

            xmlwriter_start_attribute($xw, 'Version'); //Version
            xmlwriter_text($xw, '0');
            xmlwriter_end_attribute($xw); //Version

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

            if ($this->anulacion['uid']) {
                xmlwriter_start_attribute($xw, 'NumeroAutorizacionDocumentoOrigen'); //NumeroAutorizacionDocumentoOrigen
                xmlwriter_text($xw, $this->anulacion['uid']);
                xmlwriter_end_attribute($xw); //NumeroAutorizacionDocumentoOrigen
            } else {
                xmlwriter_start_attribute($xw, 'NumeroAutorizacionDocumentoOrigen'); //NumeroAutorizacionDocumentoOrigen
                xmlwriter_text($xw, $this->anulacion['autorizacion']);
                xmlwriter_end_attribute($xw); //NumeroAutorizacionDocumentoOrigen

                xmlwriter_start_attribute($xw, 'RegimenAntiguo'); //RegimenAntiguo
                xmlwriter_text($xw, 'Antiguo');
                xmlwriter_end_attribute($xw); //RegimenAntiguo
            }

            xmlwriter_end_element($xw); //</cno:ReferenciasNota>
            xmlwriter_end_element($xw); //</Complemento>
            xmlwriter_end_element($xw); //</Complementos>
        }

        xmlwriter_end_element($xw); //DatosEmision
        xmlwriter_end_element($xw); //DTE

        xmlwriter_start_element($xw, 'dte:Adenda'); //<Adenda>
        xmlwriter_start_element($xw, 'dte:CamposAdicionales'); //<CamposAdicionales>

        xmlwriter_start_element($xw, 'dte:TotalenLetras'); //<TotalenLetras>
        xmlwriter_text($xw, $this->empresa['totalletras']);
        xmlwriter_end_element($xw); //TotalenLetras

        if ($this->empresa['footer'] != '') {
            xmlwriter_start_element($xw, 'dte:PieDePagina'); //<PieDePagina>
            xmlwriter_text($xw, $this->empresa['footer']);
            xmlwriter_end_element($xw); //PieDePagina
        }

        xmlwriter_end_attribute($xw); //CamposAdicionales
        xmlwriter_end_element($xw); //Adenda

        xmlwriter_end_element($xw); //SAT
        xmlwriter_end_element($xw); //GTDocumento
        xmlwriter_end_document($xw);

        return $this->sendXML(xmlwriter_output_memory($xw), 'emitir');
        //echo xmlwriter_output_memory($xw);
    }

    public function felGuatefacturas()
    {
        if ($this->empresa['nombrecomercial'] == '') {
            abort(400, 'El nombre comercial del emisor es requerido');
        }

        if ($this->empresa['direccion'] == '') {
            abort(400, 'La dirección del emisor es requerido');
        }

        $factorIVA      = 1 + ($this->empresa['iva'] / 100);
        $globalDiscount = isset($this->factura['descuentoGlobal']) ? $this->factura['descuentoGlobal'] : 0;

        $xw = xmlwriter_open_memory();
        xmlwriter_set_indent($xw, 1);
        $res = xmlwriter_set_indent_string($xw, '    ');
        xmlwriter_start_document($xw, '1.0', 'UTF-8');

        xmlwriter_start_element($xw, 'DocElectronico'); //<DocElectronico>

        $gMonto         = 0;
        $gTotal         = 0;
        $gImpuestos     = 0;
        $gDescuentos    = 0;
        $gMontoGravable = 0;

        $xwd = xmlwriter_open_memory();
        foreach ($this->items as $item) {
            $monto    = $item['precio'] * $item['cantidad'];
            $discount = $item['descuento'];

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
            $discount = round($discount, 6);
            $gTotal += round($monto - $discount, 2);
            $montoGravable = round((($monto - $discount) / $factorIVA), 2);
            $impuestos     = $montoGravable * ($this->empresa['iva'] / 100);
            $gImpuestos += $impuestos;
            $gDescuentos += $discount;
            $gMonto += $monto;
            $gMontoGravable += $montoGravable;

            xmlwriter_start_element($xwd, 'Productos'); //Productos

            xmlwriter_write_element($xwd, 'Producto', $item['ean']);
            xmlwriter_write_element($xwd, 'Descripcion', $item['descripcion'] . $item['descripcionAmpliada']);
            xmlwriter_write_element($xwd, 'Medida', $item['unidad']);
            xmlwriter_write_element($xwd, 'Cantidad', $item['cantidad']);
            xmlwriter_write_element($xwd, 'Precio', $item['precio']);
            xmlwriter_write_element($xwd, 'PorcDesc', 0);
            xmlwriter_write_element($xwd, 'ImpBruto', $monto);
            xmlwriter_write_element($xwd, 'ImpDescuento', $discount);
            xmlwriter_write_element($xwd, 'ImpExento', 0.00);
            xmlwriter_write_element($xwd, 'ImpOtros', 0.00);
            xmlwriter_write_element($xwd, 'ImpNeto', $montoGravable);
            xmlwriter_write_element($xwd, 'ImpIsr', 0.00);
            xmlwriter_write_element($xwd, 'ImpIva', $impuestos);
            xmlwriter_write_element($xwd, 'ImpTotal', $monto);
            xmlwriter_write_element($xwd, 'TipoVentaDet', substr($item['tipo'], 0, 1)); //B,S
            xmlwriter_write_element($xwd, 'DatosAdicionalesProd');

            // xmlwriter_start_element($xwd, 'MontoGravable');
            // xmlwriter_text($xwd, $montoGravable);
            // xmlwriter_end_element($xwd);

            // xmlwriter_start_element($xwd, 'MontoImpuesto');
            // xmlwriter_text($xwd, $impuestos);
            // xmlwriter_end_element($xwd);

            // xmlwriter_start_element($xwd, 'Total');
            // xmlwriter_text($xwd, round($monto - $discount, 2));
            // xmlwriter_end_element($xwd);

            xmlwriter_end_element($xwd); //Productos
        }

        xmlwriter_start_element($xw, 'Encabezado'); //<Encabezado>

        xmlwriter_start_element($xw, 'Receptor'); //<Receptor>
        xmlwriter_start_element($xw, 'NITReceptor');
        xmlwriter_text($xw, $this->factura['nit']);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'Nombre');
        xmlwriter_text($xw, $this->factura['nombre']);
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Direccion');
        xmlwriter_text($xw, $this->factura['direccion']);
        xmlwriter_end_element($xw);
        xmlwriter_end_element($xw); //</Receptor>

        xmlwriter_start_element($xw, 'InfoDoc'); //<InfoDoc>
        xmlwriter_start_element($xw, 'TipoVenta');
        xmlwriter_text($xw, 'B'); //Esto deberia ir en el detalle, wtf?
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'DestinoVenta');
        xmlwriter_text($xw, '1');
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'Fecha');
        xmlwriter_text($xw, Carbon::now('GMT-6')->format('d/m/Y'));
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'Moneda');
        xmlwriter_text($xw, $this->factura['moneda'] == 'GTQ' ? 1 : 2);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'Tasa');
        xmlwriter_text($xw, 1);
        xmlwriter_end_element($xw);
        xmlwriter_start_element($xw, 'Referencia');
        xmlwriter_text($xw, $this->factura['referenciainterna']);
        xmlwriter_end_element($xw);
        xmlwriter_end_element($xw); //</InfoDoc>

        xmlwriter_start_element($xw, 'Totales'); //<Totales>

        xmlwriter_start_element($xw, 'Bruto');
        xmlwriter_text($xw, round($gMonto, 2));
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Descuento');
        xmlwriter_text($xw, round($gDescuentos, 2));
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Exento');
        xmlwriter_text($xw, 0.00);
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Otros');
        xmlwriter_text($xw, 0.00);
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Neto');
        xmlwriter_text($xw, $gMontoGravable);
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Isr');
        xmlwriter_text($xw, 0.00);
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Iva');
        xmlwriter_text($xw, $gImpuestos);
        xmlwriter_end_element($xw);

        xmlwriter_start_element($xw, 'Total');
        xmlwriter_text($xw, round($gTotal, 2));
        xmlwriter_end_element($xw);
        xmlwriter_end_element($xw); //</Totales>

        xmlwriter_end_element($xw); //</Encabezado>

        xmlwriter_start_element($xw, 'Detalles'); //<Detalles>
        xmlwriter_write_raw($xw, xmlwriter_output_memory($xwd));
        xmlwriter_start_element($xw, 'DocAsociados'); //DocAsociados
        xmlwriter_end_element($xw);
        xmlwriter_end_element($xw); //</Detalles>

        xmlwriter_end_element($xw); //DocElectronico
        xmlwriter_end_document($xw);

        return $this->sendXML(xmlwriter_output_memory($xw), 'emitir');
        //echo xmlwriter_output_memory($xw);
    }

    /****************************************/
    /* $accion = [emitir, anular]
    /****************************************/
    public function sendXML($aXml, $accion = 'emitir')
    {
        Log::info($aXml);

        $entity      = trim($this->empresa['nit']);
        $transaction = 'SYSTEM_REQUEST';
        $data1       = ($accion == 'emitir' ? 'POST_DOCUMENT_SAT' : 'VOID_DOCUMENT');
        $data2       = base64_encode($aXml);
        $data3       = $this->factura['referenciainterna'];
        $username    = $this->empresa['usuario'];

        $respuesta = [
            'id'        => null,
            'uuid'      => null,
            'serie'     => null,
            'documento' => null,
            'firma'     => null,
            'nombre'    => null,
            'direccion' => null,
            'xml'       => null,
            'html'      => null,
            'pdf'       => null,
        ];

        //INFILE - Rest
        switch ($this->resolucion['proveedorface']) {
            case self::Infile:
                $client  = new Client;
                $headers = [
                    'UsuarioFirma'  => $username,
                    'LlaveFirma'    => $this->empresa['firmallave'],
                    'UsuarioApi'    => $username,
                    'LlaveApi'      => $this->empresa['requestor'],
                    'Identificador' => $this->factura['referenciainterna'],
                    'Content-Type'  => 'text/xml; charset=UTF8',
                ];
                $body = $aXml;

                $url      = $this->getURL();
                $response = $client->post($url, [
                    'headers' => $headers,
                    'body'    => $body,
                ]);

                $json = json_decode((string) $response->getBody());
                if ($json->resultado == false) {
                    Log::info(json_encode($json));
                    $err = collect($json->descripcion_errores)->reduce(function ($carry, $e) {
                        return $carry . $e->mensaje_error . ' <br> ';
                    });
                    abort(400, $err);
                }

                //abort(400, json_encode($json));
                $uuid      = $json->uuid;
                $serie     = $json->serie;
                $documento = $json->numero;
                $firma     = $uuid;
                $xml       = $json->xml_certificado;
                $id        = null;
                $nombre    = null;
                $direccion = null;
                $html      = null;
                $pdf       = null;
                break;

            case self::Guatefacturas:
                $soapParams = [
                    'trace'      => 1,
                    'keep_alive' => 0,
                    'login'      => $this->getUserPassword()['user'],
                    'password'   => $this->getUserPassword()['password'],
                ];
                $soapClient = new SoapClient($this->getURL(), $soapParams);
                //TODO: Fix data quemada
                $params = [
                    'pUsuario'         => $username,
                    'pPassword'        => $this->empresa['requestor'],
                    'pNitEmisor'       => $entity,
                    'pEstablecimiento' => 1,
                    'pTipoDoc'         => 1,
                    'pIdMaquina'       => '1',
                    'pTipoRespuesta'   => 'R',
                    'pXml'             => $aXml,
                ];

                //print_r(json_encode($params, JSON_PRETTY_PRINT));
                ini_set('default_socket_timeout', 180);
                try {
                    $result = $soapClient->__soapCall('generaDocumento', $params);
                } catch (SoapFault $exception) {
                    abort(400, $exception->getMessage());
                }

                try {
                    $xml = simplexml_load_string($result);
                } catch (\Throwable $th) {
                    abort(400, json_encode($result));
                }

                if (!$xml->Serie) {
                    abort(400, $xml[0]);
                }

                $serie     = $xml->Serie[0];
                $documento = $xml->Preimpreso[0];
                $uuid      = $xml->NumeroAutorizacion[0];
                $firma     = $uuid;
                $id        = null;
                $nombre    = $xml->Nombre[0];
                $direccion = $xml->Direccion[0];
                $html      = null;
                $pdf       = null;

                break;

            default:
                $soapClient = new SoapClient($this->getURL(), ['trace' => true, 'keep_alive' => false]);

                $params = [
                    'Requestor'   => $this->empresa['requestor'],
                    'Transaction' => $transaction,
                    'Country'     => $this->empresa['codigopais'],
                    'Entity'      => $entity,
                    'User'        => $this->empresa['requestor'],
                    'UserName'    => $username,
                    'Data1'       => $data1,
                    'Data2'       => $data2,
                    'Data3'       => $data3,
                ];

                Log::info($params);

                ini_set('default_socket_timeout', 180);
                $info = $soapClient->RequestTransaction($params);

                $result = $info->RequestTransactionResult;

                if ($result->Response->Result == false && $result->Response->Code != 9) {
                    abort(400, $result->Response->Description);
                }

                // Cuando el documento ya ha sido anulado
                if ($result->Response->Code == 9) {
                    return $respuesta;
                }

                $uuid = optional(optional($result->Response)->Identifier)->DocumentGUID;
                $xml  = optional($result->ResponseData)->ResponseData1;

                $xmlDoc = new DOMDocument();
                $xmlDoc->loadXML(base64_decode($xml));

                $serie     = optional(optional($result->Response)->Identifier)->Batch;
                $documento = optional(optional($result->Response)->Identifier)->Serial;
                $firma     = $uuid;
                $id        = null;
                $nombre    = null;
                $direccion = null;
                $html      = null;
                $pdf       = null;
                break;
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

    public function consultar()
    {
        $response = [
            'xml' => '',
            'pdf' => '',
        ];

        $params = [
            'resolucion'  => $this->resolucion,
            'urls'        => $this->urls,
            'reimpresion' => $this->reimpresion,
            'empresa'     => $this->empresa,
            'url'         => $this->getURL(),
            'nit'         => $this->fixNit($this->empresa['nit']),
            'factura'     => $this->factura,
            'items'       => $this->items,
            'descuentos'  => $this->descuentos,
        ];

        $formato  = new FormatoEmisor;
        $response = $formato::generar($params);

        return $response;
    }

    public function anular()
    {
        //Si es FEL
        if ($this->tipo == 'fel') {
            return $this->felAnular();
        }
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
        $validos = ['tipo', 'serie', 'correlativo', 'numeroautorizacion', 'fecharesolucion', 'rangoinicialautorizado', 'rangofinalautorizado', 'proveedorface', 'formato'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->resolucion                  = array_merge($this->resolucion, $aParams);
        $this->resolucion['proveedorface'] = strtolower($this->resolucion['proveedorface']);

        if (!in_array($this->resolucion['proveedorface'], $this->proveedores)) {
            abort(400, 'El proveedor de facturas es incorrecto');
        }

        $fels = ['FACT', 'FPEQ', 'NCRE'];

        if (in_array($this->resolucion['tipo'], $fels)) {
            $this->tipo = 'fel';
        } else {
            abort(400, 'El tipo de documento es desconocido');
        }
    }

    public function setEmpresa($aParams)
    {
        $validos = [
            'regimen', 'codigoestablecimiento', 'dispositivoelectronico', 'moneda', 'iva', 'codigopais', 'nit', 'footer',
            'requestor', 'usuario', 'test', 'formatos', 'afiliacioniva', 'nombrecomercial', 'direccion', 'retencioniva',
            'codigopostal', 'email', 'firmaalias', 'firmallave', 'nombreestablecimiento', 'departamento', 'municipio', 'logo',
            'totalletras',
        ];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }

        $this->empresa                          = array_merge($this->empresa, $aParams);
        $this->empresa['nombrecomercial']       = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->empresa['nombrecomercial']);
        $this->empresa['nombreestablecimiento'] = preg_replace('/[\x00-\x1F\x7F]/u', '', $this->empresa['nombreestablecimiento']);
        $this->empresa['nit']                   = $this->fixnit($this->empresa['nit']);
        $this->empresa['nombreestablecimiento'] = strlen($this->empresa['nombreestablecimiento']) == 0 ? $this->empresa['nombrecomercial'] : $this->empresa['nombreestablecimiento'];
    }

    public function setFactura($aParams)
    {
        $validos = ['referenciainterna', 'nit', 'nombre', 'direccion', 'descuentoGlobal', 'moneda', 'tipo'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->factura = array_merge($this->factura, $aParams);

        $this->factura['direccion'] = ($this->factura['direccion'] == '' ? 'CIUDAD' : $this->factura['direccion']);
        $this->factura['nit']       = $this->fixnit($this->factura['nit']);
    }

    public function setReimpresion($aParams)
    {
        $validos = ['uuid', 'fecha', 'serie', 'documento', 'fechacertificacion'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->reimpresion = array_merge($this->reimpresion, $aParams);
    }

    public function setAnulacion($aParams)
    {
        $validos = ['serie', 'correlativo', 'razon', 'autorizacion', 'fecha', 'uid', 'nit'];

        foreach ($aParams as $key => $val) {
            if (!in_array($key, $validos)) {
                dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
            }
        }
        $this->anulacion        = array_merge($this->anulacion, $aParams);
        $this->anulacion['nit'] = $this->fixnit($this->anulacion['nit']);
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
        $nit = str_replace(' ', '', $aNit);
        $nit = trim(str_replace('-', '', $nit));
        $nit = strtoupper($nit);
        //Si espera los nits con 12 ceros
        if ($aPadding) {
            $nit = str_pad($nit, 12, '0', STR_PAD_LEFT);
        }

        return $nit;
    }

    private function getURL()
    {
        if ($this->empresa['test']) {
            $url = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['testurl'];
        } else {
            $url = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['url'];
        }

        if ($url == '') {
            abort(400, 'La dirección del webservice es incorrecta.');
        }

        return $url;
    }

    private function getNITURL()
    {
        if ($this->empresa['test']) {
            $url = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['testnit'];
        } else {
            $url = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['nit'];
        }

        if ($url == '') {
            abort(400, 'La dirección del webservice es incorrecta.');
        }

        return $url;
    }

    private function getUserPassword()
    {
        if ($this->empresa['test']) {
            $user     = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['testuser'];
            $password = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['testpassword'];
        } else {
            $user     = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['user'];
            $password = $this->urls[$this->tipo][$this->resolucion['proveedorface']]['password'];
        }

        if ($user == '') {
            abort(400, 'El webservice no tiene configurado usuario/password.');
        }

        return ['user' => $user, 'password' => $password];
    }
}
