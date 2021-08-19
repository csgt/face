<?php
namespace Csgt\Face;

class FormatoEmisor
{
    private static function consultar_g4s($empresa, $reimpresion, $url, $nit, $retry = 0)
    {
        $username                     = $empresa['usuario'];
        $soapClient                   = new SoapClient($url, ['trace' => true]);
        $soapClient->soap_defencoding = 'UTF-8';

        $info = $soapClient->RequestTransaction([
            'Requestor'   => $empresa['requestor'],
            'Transaction' => 'GET_DOCUMENT',
            'Country'     => $empresa['codigopais'],
            'Entity'      => $nit,
            'User'        => $empresa['requestor'],
            'UserName'    => $username,
            'Data1'       => $reimpresion['uuid'],
            'Data2'       => '',
            'Data3'       => 'PDF',
        ]);

        $result = $info->RequestTransactionResult;
        if ($result->Response->Result == false) {
            $message = $result->Response->Description;
            \Log::error("Hubo un error al generar la factura G4S, retrying..." . $retry);
            if ($retry < 2 && (str_contains($message, 'Could not find file') || str_contains($message, 'no ha sido emitido'))) {
                sleep(3);

                return self::consultar_g4s($empresa, $reimpresion, $url, $nit, $retry++);
            }
            throw new Exception($message);
        }

        return $result;
    }

    public static function generar($params)
    {
        $resolucion  = $params['resolucion'];
        $urls        = $params['urls'];
        $reimpresion = $params['reimpresion'];
        $empresa     = $params['empresa'];
        $url         = $params['url'];
        $nit         = $params['nit'];
        $xml         = '';
        $pdf         = '';

        switch ($resolucion['proveedorface']) {
            case 'infile':
                $url      = $urls['fel']['infile']['pdf'] . $reimpresion['uuid'];
                $client   = new Client;
                $response = $client->get($url);
                $pdf      = base64_encode((string) $response->getBody());
                break;
            default:
                $result = self::consultar_g4s($empresa, $reimpresion, $url, $nit);

                if ($result->Response->Result == false) {
                    Log::info(json_encode($result->Response));

                    sleep(4);
                    $result = self::consultar_g4s($empresa, $reimpresion, $url, $nit);
                    if ($result->Response->Result == false) {
                        throw new Exception($result->Response->Description);
                    }
                }
                $xml = base64_decode($result->ResponseData->ResponseData1);
                $pdf = $result->ResponseData->ResponseData3;
                break;
        }

        return [
            'xml' => $xml,
            'pdf' => $pdf,
        ];
    }
}
