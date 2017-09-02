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

	private $items = [];
	private $detalles;
	// private $descuentos = ['SumaDeDescuentos' => 0];
	private $descuentosNKeys = 1;
	private $descuentos = [
	  'SumaDeDescuentos' => 0,
	];

	public function generar() {
		$this->generarDetalles();

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
					'Direccion2'   => '.',
					'Municipio'    => 'GUATEMALA',
					'Departamento' => 'GUATEMALA',
					'CodigoDePais' => 'GT'
				],
				'Idioma' => 'es'
			];
		}
		$x['Detalles'] = $this->detalles;

		$x['Totales'] = [
      'SubTotalSinDR' => number_format($this->totales['valorSinDRMonto'],4,'.','')
    ];

    if (count($this->descuentos)>$this->descuentosNKeys) {
    	$x['Totales']['DescuentosYRecargos'] = $this->descuentos;
    }

    $arr = [
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
			'TotalLetras'   => Components::numeroALetras($this->totales['valorConDRMonto'] + $this->totales['impuestos'], $this->empresa['moneda'], 2, true),
    ];

    $x['Totales'] = array_merge($x['Totales'], $arr);

		if($this->empresa['footer'] <> '') {
			$x['TextosDePie'] =[
        'Texto' => substr($this->empresa['footer'], 0, 1000)
	    ];
		}

		$xml = new SimpleXMLElement("<FactDocGT xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\" xmlns=\"http://www.fact.com.mx/schema/gt\" xsi:schemaLocation=\"http://www.fact.com.mx/schema/gt http://www.mysuitemex.com/fact/schema/fx_2013_gt_3.xsd\"></FactDocGT>");
		$this->array_to_xml($x, $xml);

		$xmlText = utf8_encode($xml->asXML());

		for ($i=count($this->detalles)-1; $i >= 0; $i-- ) {
			$xmlText = strtr($xmlText, ['item' . $i => 'Detalle']);
			$xmlText = strtr($xmlText, ['desc_' . $i => 'DescuentoORecargo']);
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

		$soapClient = new SoapClient($url, [
			"trace" => true,
			"keep_alive" => false
		]); 
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

			$dir2Node = $nameaddr[0]->getElementsByTagName('streetAddressTwo');
			$dir2     = $dir2Node->length > 0 ? $dir2Node[0]->nodeValue : null;

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
			$respuesta['direccion'] = trim($dir1 . ($dir2 ? ' ' . $dir2 : ''));
			$respuesta['xml']       = $result->ResponseData->ResponseData1;
			$respuesta['html']      = $result->ResponseData->ResponseData2;
			$respuesta['pdf']       = $result->ResponseData->ResponseData3;
  		
  		return $respuesta;
 		}
	}

	public function consultar () {
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
			'Data3'       => 'XML PDF'
			]	
		]); 

  	$result = $info->RequestTransactionResult;

  	if ($result->Response->Result == false) {
  		throw new Exception($result->Response->Description);  	
  	}

		return [
			'xml' => base64_decode($result->ResponseData->ResponseData1),
			'pdf' => $result->ResponseData->ResponseData3
		];

	}

	public function pdf () {
		return $this->consultar();
	}

	public function setDetalle($aCantidad, $aPrecioUnitario, $aDescripcion, $aDescripcionAmpliada='',
		$aBienServicio='BIEN', $aDescuento=0, $aExtras='', $aUnidadMedida='Un', $aCodigoEAN='00000000000000') {

		if ((float)$aCantidad==0.0) return false;
		if (($aBienServicio <> 'BIEN') && ($aBienServicio <> 'SERVICIO')) return false;

		$this->items[] = [
			'cantidad' => $aCantidad,
			'precio' => $aPrecioUnitario,
			'descripcion' => $aDescripcion,
			'descripcionAmpliada' => $aDescripcionAmpliada,
			'tipo' => $aBienServicio,
			'descuento' => $aDescuento,
			'extras' => $aExtras,
			'unidad' => $aUnidadMedida,
			'ean' => $aCodigoEAN
		];
	}

	private function generarDetalles () {
		$descuentoGlobal = isset($this->factura['descuentoGlobal']) ? $this->factura['descuentoGlobal'] : 0;
		foreach ($this->items as $item) {
			
			$aCantidad = $item['cantidad'];
			$aPrecioUnitario = $item['precio'];
			$aDescripcion = $item['descripcion'];
			$aDescripcionAmpliada = $item['descripcionAmpliada'];
			$aBienServicio = $item['tipo'];
			$aDescuento = $item['descuento'];
			$aExtras = $item['extras'];
			$aUnidadMedida = $item['unidad'];
			$aCodigoEAN = $item['ean'];

			if ($aDescripcion=='') $aDescripcion = 'Por su compra';

			$factorIVA        = 1+($this->empresa['iva']/100);
			$monto            = $aPrecioUnitario*$aCantidad;

			if ($aPrecioUnitario > 0 && $descuentoGlobal > 0) {
				$resta = $monto - $aDescuento;
				$porcionDescuentoGlobal = 0;
				if ($descuentoGlobal >= $resta) {
					$porcionDescuentoGlobal = $resta;
				}
				else {
					$porcionDescuentoGlobal = $descuentoGlobal;
				}
				$descuentoGlobal -= $porcionDescuentoGlobal;
				$aDescuento += $porcionDescuentoGlobal;
			}

			$descuento        = $aDescuento/$factorIVA;
			$valorSinDRPrecio = $aPrecioUnitario/$factorIVA;
			$valorSinDRMonto  = $valorSinDRPrecio*$aCantidad;
			$valorConDRMonto  = ($monto/$factorIVA)-$descuento;
			$valorConDRPrecio = $valorConDRMonto/$aCantidad;
			$impuestos        = $valorConDRMonto*($this->empresa['iva']/100);

			if ($aPrecioUnitario > 0)
				$descuentotasa = $aDescuento * 100 / $aPrecioUnitario / $aCantidad;
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
				]
			];

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
				$this->descuentos['SumaDeDescuentos'] = number_format($this->descuentos['SumaDeDescuentos'] + $descuento, 4, '.','');

				$tasaParaBusqueda = number_format($descuentotasa, 4,'.','');

				$tasaFound = false;

				foreach ($this->descuentos as $key => &$value) {
					if (is_array($value) && $value['Tasa'] === $tasaParaBusqueda) {
						$value['Base'] = number_format(((float) $value['Base']) + $valorSinDRMonto, 4, '.', '');
						$value['Monto'] = number_format(((float) $value['Monto']) + $descuento, 4, '.', '');
						$tasaFound = true;
					}
				}

				if (!$tasaFound) {
					$this->descuentos['desc_' . (count($this->descuentos) - $this->descuentosNKeys)] = [
						'Operacion' => 'DESCUENTO',
						'Servicio'  => 'ALLOWANCE_GLOBAL',
						'Base'      => number_format($valorSinDRMonto, 4,'.',''),
						'Tasa'      => $tasaParaBusqueda,
						'Monto'     => number_format($descuento, 4,'.','')
					];
				}
			}

			$detalle['ValorConDR'] = [
					'Precio' => number_format($valorConDRPrecio, 4,'.',''),
					'Monto'  => number_format($valorConDRMonto, 4,'.',''),
			];

			$detalle['Impuestos'] = [
						'TotalDeImpuestos'      => number_format($impuestos, 4,'.',''),
						'IngresosNetosGravados' => number_format($valorConDRMonto, 4,'.',''),
						'TotalDeIVA'            => number_format($impuestos, 4,'.',''),
						'Impuesto'              => [
							'Tipo'  => 'IVA',
							'Base'  => number_format($valorConDRMonto, 4,'.',''),
							'Tasa'  => $this->empresa['iva'],
							'Monto' => number_format($impuestos, 4,'.','')
						]
			];
			$detalle['Categoria'] = $aBienServicio;

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

			$this->detalles[] = $detalle;
		}
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
		$validos = ['referenciainterna', 'nit', 'nombre', 'direccion', 'descuentoGlobal'];

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
        $subnode = $xml_student_info->addChild($key);
        $this->array_to_xml($value, $subnode);
      }
      else {
        $key = is_numeric($key) ? "item$key" : $key;
        $xml_student_info->addChild($key, htmlspecialchars($value));
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