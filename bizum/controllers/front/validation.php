<?php
/**
* NOTA SOBRE LA LICENCIA DE USO DEL SOFTWARE
* 
* El uso de este software está sujeto a las Condiciones de uso de software que
* se incluyen en el paquete en el documento "Aviso Legal.pdf". También puede
* obtener una copia en la siguiente url:
* http://www.redsys.es/wps/portal/redsys/publica/areadeserviciosweb/descargaDeDocumentacionYEjecutables
* 
* Redsys es titular de todos los derechos de propiedad intelectual e industrial
* del software.
* 
* Quedan expresamente prohibidas la reproducción, la distribución y la
* comunicación pública, incluida su modalidad de puesta a disposición con fines
* distintos a los descritos en las Condiciones de uso.
* 
* Redsys se reserva la posibilidad de ejercer las acciones legales que le
* correspondan para hacer valer sus derechos frente a cualquier infracción de
* los derechos de propiedad intelectual y/o industrial.
* 
* Redsys Servicios de Procesamiento, S.L., CIF B85955367
*/

class BizumValidationModuleFrontController extends ModuleFrontController {
	public function postProcess () {
		try {
			$idLog = generateIdLog();

			$logActivo = Configuration::get('BIZUM_LOG');
			escribirLog($idLog . " -- " . "Entramos en la validación del pedido", $logActivo);
			
			$accesoDesde = "";
			if (!empty($_POST)) {
				$accesoDesde = 'POST';
			} else if (!empty($_GET)) {
				$accesoDesde = 'GET';
			}
			
			if ($accesoDesde === 'POST' || $accesoDesde === 'GET') {
		
				/** Recoger datos de respuesta **/
				$version     = Tools::getValue('Ds_SignatureVersion');
				$datos    = Tools::getValue('Ds_MerchantParameters');
				$firma_remota    = Tools::getValue('Ds_Signature');
			
				// Se crea Objeto
				$miObj = new RedsysAPI;
				
				/** Se decodifican los datos enviados y se carga el array de datos **/
				$decodec = $miObj->decodeMerchantParameters($datos);
			
				/** Clave **/
				$kc = Configuration::get('BIZUM_CLAVE256');
				
				/** Se calcula la firma **/
				$firma_local = $miObj->createMerchantSignatureNotif($kc,$datos);
				
		
				/** Extraer datos de la notificación **/
				$total     = $miObj->getParameter('Ds_Amount');
				$pedido    = $miObj->getParameter('Ds_Order');
				escribirLog($idLog." -- "."Pedido de Bizum: ".$pedido,$logActivo);
				$pedidoSecuencial = $pedido;
				$pedido = intval(substr($pedidoSecuencial, 0, 11));
				escribirLog($idLog." -- "."Pedido de Prestashop: ".$pedido,$logActivo);
				$codigo    = $miObj->getParameter('Ds_MerchantCode');
				escribirLog($idLog." -- "."Cod: ".$codigo,$logActivo);
				$moneda    = $miObj->getParameter('Ds_Currency');
				$respuesta = $miObj->getParameter('Ds_Response');
				escribirLog($idLog." -- "."Respuesta: ".$respuesta,$logActivo);
				$id_trans = $miObj->getParameter('Ds_AuthorisationCode');
				escribirLog($idLog." -- "."ID trans: ".$id_trans,$logActivo);
				$id_trans = str_replace("+", "", $id_trans);
			
				/** Código de comercio **/
				$codigoOrig = Configuration::get('BIZUM_CODIGO');
				
				/** Pedidos Cancelados **/
				$error_pago = Configuration::get('BIZUM_ERROR_PAGO');

				/** VALIDACIONES DE LIBRERÍA **/
				if ($firma_local === $firma_remota
					&& checkImporte($total)
					&& checkPedidoNum($pedido)
					&& checkFuc($codigo)
					&& checkMoneda($moneda)
					&& checkRespuesta($respuesta)) {
					if ($accesoDesde === 'POST') {
						escribirLog($idLog." -- "."Acceso POST",$logActivo);
						/** Creamos los objetos para confirmar el pedido **/
						$context = Context::getContext();
						$cart = new Cart($pedido);
						$bizum = new bizum();
						$carrito_valido = true;
						/** Validamos Objeto carrito **/
						if ($cart->id_customer == 0) {
							escribirLog($idLog." -- "."Error validando el carrito. Cliente vacío.",$logActivo);
							$carrito_valido = false;
						}
						if ($cart->id_address_delivery == 0) {
							escribirLog($idLog." -- "."Error validando el carrito. Dirección de envío vacía.",$logActivo);
							$carrito_valido = false;
						}
						if ($cart->id_address_invoice == 0){
							escribirLog($idLog." -- "."Error validando el carrito. Dirección de facturación vacía.",$logActivo);
							$carrito_valido = false;
						}
						if (!$bizum->active) {
							escribirLog($idLog." -- "."Error. Módulo desactivado.",$logActivo);
							$carrito_valido = false;
						}

						if (!$carrito_valido){
							Tools::redirect('index.php?controller=order&step=1');
						}
						/** Validamos Objeto cliente **/
						$customer = new Customer((int)$cart->id_customer);
						
						/** Donet **/
						Context::getContext()->customer = $customer;
						$address = new Address((int)$cart->id_address_invoice);
						Context::getContext()->country = new Country((int)$address->id_country);
						Context::getContext()->customer = new Customer((int)$cart->id_customer);
						Context::getContext()->language = new Language((int)$cart->id_lang);
						Context::getContext()->currency = new Currency((int)$cart->id_currency);			
						
						if (!Validate::isLoadedObject($customer)) {
							escribirLog($idLog." -- "."Error validando el cliente.",$logActivo);
							Tools::redirect('index.php?controller=order&step=1');
						}

						/** VALIDACIONES DE DATOS y LIBRERÍA **/
	
						$currencyOrig = new Currency($cart->id_currency);
						$currency_decimals = is_array($currencyOrig) ? (int) $currencyOrig['decimals'] : (int) $currencyOrig->decimals;
						$decimals = $currency_decimals * _PS_PRICE_DISPLAY_PRECISION_;
						// ISO Moneda
						$monedaOrig = $currencyOrig->iso_code_num;
						$monedaOrig = $currencyOrig->iso_code_num;
						if ($monedaOrig == 0 || $monedaOrig == null){
							escribirLog($idLog." -- "."Error cargando moneda, utilizando la moneda recuperada.",$logActivo);
							$monedaOrig = $moneda;
						}
						// DsResponse
						$respuesta = (int)$respuesta;

						
						if ($monedaOrig == $moneda && (int)$codigoOrig == (int)$codigo && $respuesta < 101) {
							/** Compra válida **/
							$mailvars['transaction_id'] = (int)$id_trans;
							$bizum->validateOrder($pedido, Configuration::get("BIZUM_ESTADO_PEDIDO"), $total/100, $bizum->displayName, null, $mailvars, (int)$cart->id_currency, false, $customer->secure_key);
							escribirLog($idLog." -- "."El pedido con ID de carrito " . $pedido . " es válido y se ha registrado correctamente.",$logActivo);
							echo "Pedido validado con éxito";
							exit();
						} else {
							if (!($monedaOrig == $moneda)) {
								escribirLog($idLog." -- "."La moneda no coincide. ($monedaOrig : $moneda)",$logActivo);
							}
							if (!((int)$codigoOrig == (int)$codigo)) {
								escribirLog($idLog." -- "."El código de comercio no coincide. ($codigoOrig : $codigo)",$logActivo);
							}
							if ($error_pago=="no"){
								/** se anota el pedido como no pagado **/
								$bizum->validateOrder($pedido, _PS_OS_ERROR_, 0, $bizum->displayName, 'errores:'.$respuesta);
							}
							escribirLog($idLog." -- "."El pedido con ID de carrito " . $pedido . " es inválido.",$logActivo);
						}
					} else if ($accesoDesde === 'GET') {
						$respuesta = (int)$respuesta;
						if ($respuesta < 101) {
							/** Compra válida **/
							Tools::redirect('index.php?controller=order&step=1');
						} else {
							Tools::redirect('index.php?controller=order&step=1');
						}
					}
				} else {
					if ($accesoDesde === 'POST') {
						if (!($firma_local === $firma_remota)) {
							escribirLog($idLog." -- "."La firma no coincide.",$logActivo);
						}
						if (!checkImporte($total)){
							escribirLog($idLog." -- "."Ds_Amount inválido.",$logActivo);
						}
						if (!checkPedidoNum($pedido)){
							escribirLog($idLog." -- "."Ds_Order inválido.",$logActivo);
						}
						if (!checkFuc($codigo)){
							escribirLog($idLog." -- "."Ds_MerchantCode inválido.",$logActivo);
						}
						if (!checkMoneda($moneda)){
							escribirLog($idLog." -- "."Ds_Currency inválido.",$logActivo);
						}
						if (!checkRespuesta($respuesta)){
							escribirLog($idLog." -- "."Ds_Response inválido.",$logActivo);
						}
						if ($error_pago=="no"){
							/** se anota el pedido como no pagado **/
							$bizum->validateOrder($pedido, _PS_OS_ERROR_, 0, $bizum->displayName, 'errores:'.$respuesta);
						}
						escribirLog($idLog." -- "."Notificación: El pedido con ID de carrito " . $pedido . " es inválido.",$logActivo);
					} else if ($accesoDesde === 'GET') {
						Tools::redirect('index.php?controller=order&step=1');
					}
				}
			}
		}
		catch (Exception $e){
			$idLogExc = generateIdLog();
			escribirLog($idLogExc." -- Excepcion en la validacion: ".$e->getMessage(),$logActivo);
			die("Excepcion en la validacion");
		}
	}
}