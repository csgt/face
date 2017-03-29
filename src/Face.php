<?php  namespace Csgt\Face;

use Csgt\Components\Components;
use DOMDocument, SoapClient, Exception, SimpleXMLElement;

class Face {
	private $resolucion = [
		'tipo'                   => 'FACE63',
		'numeroautorizacion'     => '',
		'fechaemision'           => '',
		'fecharesolucion'        => '',
		'rangoinicialautorizado' => 0,
		'rangofinalautorizado'   => 0,
		'proveedorface'          => 'g4s', //g4s, gyt
	];

	private $factura = [
		'referenciainterna' => 0,
		'nit'               => '',
		'nombre'            => '',
		'direccion'         => ''
	];

	private $totales = [
		'monto'           => 0,
		'descuento'       => 0,
		'valorSinDRMonto' => 0,
		'valorConDRMonto' => 0,
		'impuestos'       => 0
	];

	private $empresa = [
		'regimen'                => 'PAGO_TRIMESTRAL', //RET_DEFINITIVA, PAGO_TRIMESTRAL
		'codigoestablecimiento'  => 1,
		'dispositivoelectronico' => '001',
		'moneda'                 => 'GTQ',
		'iva'                    => 12,
		'codigopais'             => 'GT',
		'nit'                    => '',
		'footer'                 => '',
		'requestor'							 => '',
		'usuario'		             => '',
		'formatos'							 => 'XML',
		'test'									 => false,
	];

	private $reimpresion = [
		'serie'       => '',
		'correlativo' => ''
	];

	private $detalles;

	public function generar() {
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
		if (count($this->detalles) == 0) {
			throw new Exception('Se debe agregar al menos un detalle a la factura');
		}

		$x = [
			'Version' => 3,
			'Encabezado' => [
				'TipoActivo'              => $this->resolucion['tipo'],
				'CodigoDeMoneda'          => $this->empresa['moneda'],
				'TipoDeCambio'            => 1,
				'InformacionDeRegimenIsr' => $this->empresa['regimen'],
				'ReferenciaInterna'       => $this->factura['referenciainterna'],
			],
			'Vendedor' => [
				'Nit'                     => $this->fixnit($this->empresa['nit'], true),
				'Idioma'                  => 'es',
				'CodigoDeEstablecimiento' => $this->empresa['codigoestablecimiento'],
				'DispositivoElectronico'  => $this->empresa['dispositivoelectronico'],
			],
			'Comprador' => [
				'Nit'    => $this->fixnit($this->factura['nit']),
				'Idioma' => 'es'
    	],
		];

		if ($this->factura['nit'] == 'CF') {
			$x['Comprador'] = [
				'Nit'                => $this->factura['nit'],
				'NombreComercial'    => ($this->factura['nombre'] == ''? 'CONSUMIDOR FINAL': $this->factura['nombre']),
				'DireccionComercial' => [
					'Direccion1'   => ($this->factura['direccion'] == ''? 'CIUDAD': $this->factura['direccion']),
					'Municipio'    => 'GUATEMALA',
					'Departamento' => 'GUATEMALA',
					'CodigoDePais' => 'GT'
				]
			];
		}
		$x['Detalles'] = $this->detalles;

		$x['Totales'] = [
      'SubTotalSinDR' => number_format($this->totales['valorSinDRMonto'],4,'.',''),
      'SubTotalConDR' => number_format($this->totales['valorConDRMonto'],4,'.',''),
      'Impuestos'     => [
				'TotalDeImpuestos'      => number_format($this->totales['impuestos'],4,'.',''),
				'IngresosNetosGravados' => number_format($this->totales['valorConDRMonto'],4,'.',''),
				'TotalDeIVA'            => number_format($this->totales['impuestos'],4,'.',''),
				'Impuesto'              => [
					'Tipo'  => 'IVA',
					'Base'  => number_format($this->totales['valorConDRMonto'],4,'.',''),
					'Tasa'  => $this->empresa['iva'],
					'Monto' => number_format($this->totales['impuestos'],4,'.',''),
        ]
      ],
			'Total'         => number_format($this->totales['valorConDRMonto'] + $this->totales['impuestos'],2,'.',''),
			'TotalLetras'   => Components::numeroALetras($this->totales['valorConDRMonto'] + $this->totales['impuestos'], $this->empresa['moneda'], 2,'.',''),
    ];

		if($this->empresa['footer'] <> '') {
			$x['TextosDePie'] =[
        'Texto' => $this->empresa['footer'],
	    ];
		}

		$xml = new SimpleXMLElement("<FactDocGT xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns=\"http://www.fact.com.mx/schema/gt\" xsi:schemaLocation=\"http://www.fact.com.mx/schema/gt http://www.mysuitemex.com/fact/schema/fx_2013_gt_3.xsd\"></FactDocGT>");
		$this->array_to_xml($x, $xml);

		$xmlText = utf8_encode($xml->asXML());

		for ($i=0; $i<count($this->detalles); $i++ ) {
			$xmlText = strtr($xmlText, ['item' . $i => 'Detalle']);
		} 
		
		return $this->sendXML($xmlText);
	}

	public function sendXML($aXml) {
		if ($this->empresa['test'])
			$url = config('csgtface.testurl');
		else
			$url = config('csgtface.url');
		
		$username = $this->empresa['usuario'];
		if($this->resolucion['proveedorface'] == 'gyt') {
			$username = $this->empresa['codigopais'] . '.' . $this->fixnit($this->empresa['nit']) . '.' . $this->empresa['usuario'];
		} 

		$soapClient = new SoapClient($url, ["trace" => true, ""]); 
  	$info = $soapClient->__call("RequestTransaction", ["parameters" => [
			'Requestor'   => $this->empresa['requestor'],
			'Transaction' => 'CONVERT_NATIVE_XML',
			'Country'     => $this->empresa['codigopais'],
			'Entity'      => $this->fixnit($this->empresa['nit'], true),
			'User'        => $this->empresa['requestor'],
			'UserName'    => $username,
			'Data1'       => $aXml,
			'Data2'       => $this->empresa['formatos'],
			'Data3'       => ''
    ]]); 
    
  	$result = $info->RequestTransactionResult;

  	if ($result->Response->Result == false) {
  		throw new Exception($result->Response->Description);  		
  	}
  	else {
  		//dd($result->ResponseData);
  		$xml = $result->ResponseData->ResponseData1;

  		$xmlDoc = new DOMDocument();
			$xmlDoc->loadXML(base64_decode($xml));

			$invoice = $xmlDoc->getElementsByTagNameNS('urn:ean.ucc:pay:2','*');
			$invoice = $invoice->item(0);

  		$id       = $invoice->parentNode->getAttribute('Id');
			$buyer    = $invoice->getElementsByTagName('buyer');
			$nameaddr = $buyer[0]->getElementsByTagName('nameAndAddress');
			$nombre   = $nameaddr[0]->getElementsByTagName('name')[0]->nodeValue;
			$dir1     = $nameaddr[0]->getElementsByTagName('streetAddressOne')[0]->nodeValue;
			$dir2     = $nameaddr[0]->getElementsByTagName('streetAddressTwo')[0]->nodeValue;

			$cae  = $xmlDoc->getElementsByTagName('CAE');
			$dcae = $cae[0]->getElementsByTagName('DCAE');
			$fcae = $cae[0]->getElementsByTagName('FCAE');

			$serie     = $dcae[0]->getElementsByTagName('Serie')[0]->nodeValue;
			$documento = $dcae[0]->getElementsByTagName('NumeroDocumento')[0]->nodeValue;
			$firma     = $fcae[0]->getElementsByTagName('SignatureValue')[0]->nodeValue;

			$respuesta['id']        = $id;
			$respuesta['serie']     = $serie;
			$respuesta['documento'] = $documento;
			$respuesta['firma']     = $firma;
			$respuesta['nombre']    = $nombre;
			$respuesta['direccion'] = trim($dir1 . ' ' . $dir2);
			$respuesta['xml']       = $result->ResponseData->ResponseData1;
			$respuesta['html']      = $result->ResponseData->ResponseData2;
			$respuesta['pdf']       = $result->ResponseData->ResponseData3;
  		
  		return $respuesta;
 		}
	}

	public function pdf() {
		if ($this->empresa['test'])
			$url = config('csgtface.testurl');
		else
			$url = config('csgtface.url');
		
		$username = $this->empresa['usuario'];
		if($this->resolucion['proveedorface'] == 'gyt') {
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
			'Data1'       => $this->reimpresion['serie'],
			'Data2'       => $this->reimpresion['correlativo'],
			'Data3'       => 'PDF'
			]	
		]); 

  	$result = $info->RequestTransactionResult;

  	if ($result->Response->Result == false) {
  		throw new Exception($result->Response->Description);  	
  	}
  	else {
			$respuesta['pdf']       = $result->ResponseData->ResponseData3;
  		return $respuesta;
 		}

	}

	public function setDetalle($aCantidad, $aPrecioUnitario, $aDescripcion, $aDescripcionAmpliada='',
		$aBienServicio='BIEN', $aDescuento=0, $aExtras='', $aUnidadMedida='Un', $aCodigoEAN='00000000000000') {

		if ((float)$aCantidad==0.0) return false;
		if (($aBienServicio <> 'BIEN') && ($aBienServicio <> 'SERVICIO')) return false;
		if ($aDescripcion=='') $aDescripcion = 'Por su compra';

		$factorIVA        = 1+($this->empresa['iva']/100);
		$monto            = $aPrecioUnitario*$aCantidad;
		$descuento        = $aDescuento/$factorIVA;
		$valorSinDRPrecio = $aPrecioUnitario/$factorIVA;
		$valorSinDRMonto  = $valorSinDRPrecio*$aCantidad;
		$valorConDRMonto  = ($monto/$factorIVA)-$descuento;
		$valorConDRPrecio = $valorConDRMonto/$aCantidad;
		$impuestos        = $valorConDRMonto*($this->empresa['iva']/100);

		if ($aPrecioUnitario<>0)
			$descuentotasa = ($aDescuento*100)/$aPrecioUnitario;
		else 
			$descuentotasa = 0;
		
		$this->totales['monto']           += $monto;
		$this->totales['descuento']       += $descuento;
		$this->totales['valorSinDRMonto'] += $valorSinDRMonto;
		$this->totales['valorConDRMonto'] += $valorConDRMonto;
		$this->totales['impuestos']       += $impuestos;

		$detalle = [
			'Descripcion'    => trim(substr($aDescripcion, 0, 69)),
			'CodigoEAN'      => $aCodigoEAN,
			'UnidadDeMedida' => $aUnidadMedida,
			'Cantidad'       => $aCantidad,
      'ValorSinDR'     => [
				'Precio' => number_format($valorSinDRPrecio, 4,'.',''),
				'Monto'  => number_format($valorSinDRMonto, 4,'.',''),
      ],
      'ValorConDR'     => [
				'Precio' => number_format($valorConDRPrecio, 4,'.',''),
				'Monto'  => number_format($valorConDRMonto, 4,'.',''),
      ],
      'Impuestos' => [
					'TotalDeImpuestos'      => number_format($impuestos, 4,'.',''),
					'IngresosNetosGravados' => number_format($valorConDRMonto, 4,'.',''),
					'TotalDeIVA'            => number_format($impuestos, 4,'.',''),
					'Impuesto'              => [
						'Tipo'  => 'IVA',
						'Base'  => number_format($valorConDRMonto, 4,'.',''),
						'Tasa'  => $this->empresa['iva'],
						'Monto' => number_format($impuestos, 4,'.','')
          ]
      ],
      'Categoria' => $aBienServicio
    ];
    if (trim($aDescripcionAmpliada) <>'' || trim(substr($aDescripcion, 69))) {
    	$detalle['TextosDePosicion']['Texto'] = trim(substr(trim(substr($aDescripcion, 69) . ' ' . $aDescripcionAmpliada), 0, 999));
    }

    if ($aExtras<>'') {
    	$textos = [];
    	$arr = explode(PHP_EOL, $aExtras);
    	foreach($arr as $linea) {
    		$textos[] = $linea;
    	}
    	$detalle['TextosDePosicion'] = $textos;
    }

    if($aDescuento>0) {
    	$detalle['DescuentosYRecargos'] = [
    		'SumaDeDescuentos' => number_format($descuento, 4,'.',''),
    		'DescuentoORecargo' => [
					'Operacion' => 'DESCUENTO',
					'Servicio'  => 'ALLOWANCE_GLOBAL',
					'Base'      => number_format($valorSinDRMonto, 4,'.',''),
					'Tasa'      => number_format($descuentotasa, 4,'.',''),
					'Monto'     => number_format($descuento, 4,'.','')
    		]
    	];
   	}
   	$this->detalles[] = $detalle;
   	 //['Detalle' => $detalle];
	}

	public function setResolucion($aParams){
		$validos = ['tipo','numeroautorizacion','fechaemision','fecharesolucion','rangoinicialautorizado', 'rangofinalautorizado'];

		foreach($aParams as $key => $val) {
			if (!in_array($key, $validos)) {
				dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
			}
		}
		$this->resolucion = array_merge($this->resolucion, $aParams);
	}

	public function setEmpresa($aParams){
		$validos = ['regimen', 'codigoestablecimiento', 'dispositivoelectronico', 'moneda' , 'iva', 'codigopais', 'nit', 'footer','requestor','usuario','test'];

		foreach($aParams as $key => $val) {
			if (!in_array($key, $validos)) {
				dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
			}
		}
		$this->empresa = array_merge($this->empresa, $aParams);
	}

	public function setFactura($aParams){
		$validos = ['referenciainterna', 'nit', 'nombre', 'direccion'];

		foreach($aParams as $key => $val) {
			if (!in_array($key, $validos)) {
				dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
			}
		}
		$this->factura = array_merge($this->factura, $aParams);
	}

	public function setReimpresion($aParams){
		$validos = ['serie', 'correlativo'];

		foreach($aParams as $key => $val) {
			if (!in_array($key, $validos)) {
				dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
			}
		}
		$this->reimpresion = array_merge($this->reimpresion, $aParams);
	}

	public function setFormatos($aParams){
		$validos = ['XML', 'PDF', 'HTML'];

		foreach($aParams as $key) {
			if (!in_array($key, $validos)) {
				dd('Parámetro inválido (' . $key . ') solo se permiten: ' . implode(',', $validos));
			}
		}
		$this->empresa['formatos'] = trim(implode(' ', $aParams));
	}

	private function array_to_xml($student_info, &$xml_student_info) {
    foreach($student_info as $key => $value) {
      if(is_array($value)) {
        $key = is_numeric($key) ? "item$key" : $key;
        $subnode = $xml_student_info->addChild("$key");
        $this->array_to_xml($value, $subnode);
      }
      else {
        $key = is_numeric($key) ? "item$key" : $key;
        $xml_student_info->addChild("$key","$value");
      }
    }
	}

	private function fixnit($aNit, $aPadding = false) {
		$nit = trim(str_replace('-', '', $aNit));
		//Solo GyT espera los nits con 12 ceros
		if ($aPadding)
			$nit = str_pad($nit, 12, '0', STR_PAD_LEFT);
		return $nit;
	}
}