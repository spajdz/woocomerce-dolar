<?php
if (!defined('ABSPATH')) {
    exit;
} // Exit if accessed directly
 

add_action('plugins_loaded', 'woocommerce_transbank_init', 0);

require_once( ABSPATH . "wp-includes/pluggable.php" );
require_once( ABSPATH . "wp-content/plugins/woocommerce-transbank/libwebpay/healthcheck.php" );
require_once( ABSPATH . "wp-content/plugins/woocommerce-transbank/libwebpay/tcpdf/reportPDFlog.php");
require_once( ABSPATH . "wp-content/plugins/woocommerce-transbank/libwebpay/loghandler.php");
require_once( ABSPATH . "wp-content/plugins/woocommerce-transbank/libwebpay/webpay-config.php");
require_once( ABSPATH . "wp-content/plugins/woocommerce-transbank/libwebpay/webpay-normal.php");

function woocommerce_transbank_init()
{
    if (!class_exists("WC_Payment_Gateway")){
        return;
    }

    class WC_Gateway_Transbank extends WC_Payment_Gateway
    {
        var $notify_url;

        public function __construct()
        {
            $this->id = 'transbank';
            $this->icon = "https://www.transbank.cl/public/img/Logo_Webpay3-01-50x50.png";
            $this->method_title = __('Pago a trav&eacute;s de Webpay Plus');
            $this->notify_url = add_query_arg('wc-api', 'WC_Gateway_' . $this->id, home_url('/'));
            $this->integration = include( 'integration/integration.php' );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');

            $this->config = array(
                "MODO"            => $this->get_option('webpay_test_mode'),
                "PRIVATE_KEY"     => $this->get_option('webpay_private_key'),
                "PUBLIC_CERT"     => $this->get_option('webpay_public_cert'),
                "WEBPAY_CERT"     => $this->get_option('webpay_webpay_cert'),
                "WEBPAY_CHANGE_VALUE"     => $this->get_option('webpay_change_value'),
                "COMMERCE_CODE" => $this->get_option('webpay_commerce_code'),
                "URL_RETURN"      => home_url('/') . '?wc-api=WC_Gateway_' . $this->id,
                "URL_FINAL"       => "_URL_",
				"ECOMMERCE"     => 'woocommerce',
                "VENTA_DESC"      => array(
						"VD" => "Venta Deb&iacute;to",
						"VN" => "Venta Normal",
						"VC" => "Venta en cuotas",
						"SI" => "3 cuotas sin inter&eacute;s",
						"S2" => "2 cuotas sin inter&eacute;s",
						"NC" => "N cuotas sin inter&eacute;s",
                ),
            );
			$this->args = array (
				"MODO"          => $this->get_option('webpay_test_mode'),
				"COMMERCE_CODE" => $this->get_option('webpay_commerce_code'),
				"PUBLIC_CERT"   => $this->get_option('webpay_public_cert'),
				"PRIVATE_KEY"   => $this->get_option('webpay_private_key'),
				"WEBPAY_CERT"   => $this->get_option('webpay_webpay_cert'),
                 "WEBPAY_CHANGE_VALUE"     => $this->get_option('webpay_change_value'),
				"ECOMMERCE"     => 'woocommerce',
				);

			$this->healthcheck = new HealthCheck($this->args);
			$this->datos_hc = json_decode($this->healthcheck->printFullResume());
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_wc_gateway_' . $this->id, array($this, 'check_ipn_response'));
			$this->log = new LogHandler($this->args['ECOMMERCE']);

			if ($this->config['MODO'] == 'PRODUCCION'){
				$this->healthcheck->getpostinstallinfo();
			}

			if (!$this->is_valid_for_use()) {
                $this->enabled = false;
            }
        }

        function is_valid_for_use()
        {
            //return true;
            if (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_' . $this->id . '_supported_currencies', array('CLP', 'USD')))) {
                return false;
            }
            return true;
        }

        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Activar/Desactivar', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Activar Transbank', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Nombre tipo de pago', 'woocommerce'),
                    'type' => 'text',
                    'default' => __('Pago con Tarjetas de Cr&eacute;dito o Redcompra', 'woocommerce'),
                ),
                'description' => array(
                    'title' => __('Descripci&oacute;n', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __('Permite el pago de productos y/o servicios, con Tarjetas de Cr&eacute;dito y Redcompra a trav&eacute;s de Webpay Plus', 'woocommerce')
                ),
                'webpay_change_value' => array(
                    'title' => __('Valor del cambio', 'woocommerce'),
                    'type' => 'text',
                    'default' => __($this->integration['change_value'], 'woocommerce'),
                ),
                'webpay_test_mode' => array(
                    'title' => __('Ambiente', 'woocommerce'),
                    'type' => 'select',
                    'options' => array('INTEGRACION' => 'Integraci&oacute;n', 'PRODUCCION' => 'Producci&oacute;n'),
                    'default'     => __( 'INTEGRACION', 'woocommerce' ),
                    'custom_attributes' => array(
                        'onchange' => "webpay_mode('".$this->integration['commerce_code']."', '".$this->integration['private_key']."', '".$this->integration['public_cert']."', '".$this->integration['webpay_cert']."')",
                    )
                ),
                'webpay_commerce_code' => array(
                    'title' => __('C&oacute;digo de Comercio', 'woocommerce'),
                    'type' => 'text',
                    'default' => __($this->integration['commerce_code'], 'woocommerce'),
                ),
                'webpay_private_key' => array(
                    'title' => __('Llave Privada', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->integration['private_key']), 'woocommerce'),
                    'css' => 'font-family: monospace',
                ),
                'webpay_public_cert' => array(
                    'title' => __('Certificado', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->integration['public_cert']), 'woocommerce'),
                    'css' => 'font-family: monospace',
                ),
                'webpay_webpay_cert' => array(
                    'title' => __('Certificado Transbank', 'woocommerce'),
                    'type' => 'textarea',
                    'default' => __(str_replace("<br/>", "\n", $this->integration['webpay_cert']), 'woocommerce'),
                    'css' => 'font-family: monospace',
                ),
            );
        }

        function receipt_page($order)
        {
            echo $this->generate_transbank_payment($order);
        }

        function check_ipn_response()
        {
            @ob_clean();

            if (isset($_POST)) {
                header('HTTP/1.1 200 OK');
                $this->check_ipn_request_is_valid($_POST);
            } else {
                echo "Ocurrio un error al procesar su Compra";
            }
        }

        public function check_ipn_request_is_valid($data)
        {
            $voucher = false;

            try {
                if (isset($data["token_ws"])) {
                    $token_ws = $data["token_ws"];
                } else {
                    $token_ws = 0;
                }
				$wp_config = new WebPayConfig($this->config);
                $webpay = new WebPayNormal($wp_config);
                $result = $webpay->getTransactionResult($token_ws);

            } catch (Exception $e) {
                $result["error"] = "Error conectando a Webpay";
                $result["detail"] = $e->getMessage();
            }

            $order_info = new WC_Order($result->buyOrder);

            WC()->session->set($order_info->order_key, $result);

            if ($result->buyOrder && $order_info) {
                if (($result->VCI == "TSY" || $result->VCI == "") && $result->detailOutput->responseCode == 0) {
                    $voucher = true;
                    WC()->session->set($order_info->order_key . "_transaction_paid", 1);

                    self::redirect($result->urlRedirection, array("token_ws" => $token_ws));

                    $order_info->add_order_note(__('Pago con WEBPAY PLUS', 'woocommerce'));
                    $order_info->update_status('completed');
                    $order_info->reduce_order_stock();

                } else {
                    $responseDescription = htmlentities($result->detailOutput->responseDescription);
                }
            }

            if (!$voucher) {
                $date = new DateTime($result->transactionDate);

                WC()->session->set($order_info->order_key, "");

                $error_message = "Estimado cliente, le informamos que su orden número ". $result->buyOrder . ", realizada el " . $date->format('d-m-Y H:i:s') . " termin&oacute; de forma inesperada ( " . $responseDescription . " ) ";
                wc_add_notice(__('ERROR: ', 'woothemes') . $error_message, 'error');

                $redirectOrderReceived = $order_info->get_checkout_payment_url();
                self::redirect($redirectOrderReceived, array("token_ws" => $token_ws));
            }

            die;
        }

		public function redirect($url, $data){
			echo  "<form action='" . $url . "' method='POST' name='webpayForm'>";
			foreach ($data as $name => $value) {
				echo "<input type='hidden' name='".htmlentities($name)."' value='".htmlentities($value)."'>";
			}
			echo  "</form>"
				 ."<script language='JavaScript'>"
				 ."document.webpayForm.submit();"
				 ."</script>";
		}
		
        function generate_transbank_payment($order_id)
        {
            $change = !empty($this->config['WEBPAY_CHANGE_VALUE']) ? $this->config['WEBPAY_CHANGE_VALUE'] : 1;
            $order = new WC_Order($order_id);
            $t = $order->get_total() * $change;
            $amount = (int) number_format($t, 0, ',', '');

            $currency = get_option('woocomerce_currency');
            $message = 'El valor en Pesos Chilenos (CLP) es de ' .  number_format($amount, 0, ',', '.' );
            /* echo '<pre>';
             print_r($change);
             echo '</pre>';die();*/

            if($currency == 'CLP'){
                $amount = (int) number_format($order->get_total(), 0, ',', '');
                $message = '';
            }

            $urlFinal = str_replace("_URL_", add_query_arg('key', $order->order_key, $order->get_checkout_order_received_url()), $this->config["URL_FINAL"]);

            try {
				$wp_config = new WebPayConfig($this->config);
                $webpay = new WebPayNormal($wp_config);
                $result = $webpay->initTransaction($amount, $sessionId = "", $order_id, $urlFinal);

            } catch (Exception $e) {

                $result["error"] = "Error conectando a Webpay";
                $result["detail"] = $e->getMessage();
            }

            if (isset($result["token_ws"])) {
                $url = $result["url"];
                $token = $result["token_ws"];

                echo "<br/>Gracias por su pedido, por favor haga clic en el bot&oacute;n de abajo para pagar con WebPay.<br/><br/>";

                echo "<br/>".$message."<br/><br/>";

                return '<form action="' . $url . '" method="post">' .
                        '<input type="hidden" name="token_ws" value="' . $token . '"></input>' .
                        '<input type="submit" value="WebPay"></input>' .
                        '</form>';
            } else {

                wc_add_notice(__('ERROR: ', 'woothemes') . 'Ocurri&oacute; un error al intentar conectar con WebPay Plus. Por favor intenta mas tarde.<br/>', 'error');
            }
        }

        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);
            return array('result' => 'success', 'redirect' => $order->get_checkout_payment_url(true));
        }

        public function admin_options()
        {
            ?>
			<link rel="stylesheet" href="https://bootswatch.com/spacelab/bootstrap.min.css">

			<link href="../wp-content/plugins/woocommerce-transbank/css/bootstrap-switch.css" rel="stylesheet">
			<link href="../wp-content/plugins/woocommerce-transbank/css/tbk.css" rel="stylesheet">
			<script src="https://ajax.googleapis.com/ajax/libs/jquery/3.2.1/jquery.min.js"></script>
			<script src="https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js"></script>
			<script src="https://unpkg.com/bootstrap-switch"></script>

            <h3><?php _e('WebPay Plus', 'woocommerce'); ?></h3>
           
			<hr>
			<?php if ($this->is_valid_for_use()) : ?>
				<?php if (empty($this->config["COMMERCE_CODE"])) : ?>
					<div class="inline error">
						<p><strong><?php _e('C&oacute;digo de Comercio', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu C&oacute;digo de Comercio', 'woocommerce'); ?></p>
					</div>
				<?php endif; ?>

				<?php if (empty($this->config["PRIVATE_KEY"])) : ?>
					<div class="inline error">
						<p><strong><?php _e('Llave Privada', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu Llave Privada', 'woocommerce'); ?></p>
					</div>
				<?php endif; ?>

				<?php if (empty($this->config["PUBLIC_CERT"])) : ?>
					<div class="inline error">
						<p><strong><?php _e('Certificado', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu Certificado', 'woocommerce'); ?></p>
					</div>
				<?php endif; ?>

				<?php if (empty($this->config["WEBPAY_CERT"])) : ?>
					<div class="inline error">
						<p><strong><?php _e('Certificado Transbank', 'woocommerce'); ?></strong>: <?php _e('Para el correcto funcionamiento de Webpay Plus debes ingresar tu Certificado Transbank', 'woocommerce'); ?></p>
					</div>
				<?php endif; ?>

				<table class="form-table">
					<?php
						$this->generate_settings_html();
					?>
				</table>

			<?php else : ?>
				<div class="inline error">
					<p>
					<strong><?php _e('Webpay Plus ', 'woocommerce');
					?></strong>: <?php _e('Este Plugin est&aacute; dise&ntilde;ado para operar con Webpay Plus solo en Pesos Chilenos.', 'woocommerce');
			?>
					</p>
				</div>
			<?php
			endif;
			setcookie("ambient", $this->get_option('webpay_test_mode'), strtotime('+30 days') ,"/");
			setcookie("storeID", $this->get_option('webpay_commerce_code'), strtotime('+30 days') , "/");
			setcookie("certificate", $this->get_option('webpay_public_cert'), strtotime('+30 days'), "/");
			setcookie("secretCode", $this->get_option('webpay_private_key'), strtotime('+30 days'), "/");
			setcookie("certificateTransbank", $this->get_option('webpay_webpay_cert'), strtotime('+30 days'), "/");
			?>
			<script type="text/javascript">
				function swap_action(){

					if (document.getElementById("action_check").checked){
						document.getElementById('action_txt').innerHTML = 'Registro activado';
						$('#action_txt').removeClass("label-warning").addClass("label-success");
						document.cookie="action_check=true; path=/";
						document.cookie = "size=" + document.getElementById('size').value + "; path=/";
						document.cookie = "days=" + document.getElementById('days').value + "; path=/";
					}
					else{
						document.getElementById('action_txt').innerHTML = 'Registro desactivado';
						$('#action_txt').removeClass("label-success").addClass("label-warning");
						document.cookie="action_check=false; path=/";
						document.cookie = "size=" + document.getElementById('size').value + "; path=/;expires=Thu, 01 Jan 1970 00:00:01 GMT";
						document.cookie = "days=" + document.getElementById('days').value + "; path=/;expires=Thu, 01 Jan 1970 00:00:01 GMT";
					}
					window.open("../wp-content/plugins/woocommerce-transbank/call_loghandler.php")
				}
		
				jQuery().ready(function($){

					$(document).on('click', '#tbk_pdf_button', function(e){
						var iframe = document.createElement("iframe");
						iframe.name = "myTarget";

						window.addEventListener("load", function () {
						  iframe.style.display = "none";
						  document.body.appendChild(iframe);
						});
					
						data = {'document': 'report'};
						var name,
							form = document.createElement("form"),
							node = document.createElement("input");

						iframe.addEventListener("load", function () {
					
						});

						form.action = "../wp-content/plugins/woocommerce-transbank/createpdf.php";
						form.method = 'POST';
						form.target = iframe.name;

						for(name in data) {
						  node.name  = name;
						  node.value = data[name].toString();
						  form.appendChild(node.cloneNode());
						}
						form.style.display = "none";
						document.body.appendChild(form);
						form.submit();
						document.body.removeChild(form);
					});
				})
			</script>
			<?php
        }
    }

    function woocommerce_add_transbank_gateway($methods)
    {
        $methods[] = 'WC_Gateway_transbank';
        return $methods;
    }

    function pay_content($order_id)
    {
        $order_info = new WC_Order($order_id);
        $transbank_data = new WC_Gateway_transbank;

        if ($order_info->payment_method_title == $transbank_data->title) {

            if (WC()->session->get($order_info->order_key . "_transaction_paid") == "" && WC()->session->get($order_info->order_key) == "") {

                wc_add_notice(__('Compra <strong>Anulada</strong>', 'woocommerce') . ' por usuario. Recuerda que puedes pagar o
                    cancelar tu compra cuando lo desees desde <a href="' . wc_get_page_permalink('myaccount') . '">' . __('Tu Cuenta', 'woocommerce') . '</a>', 'error');
                wp_redirect($order_info->get_checkout_payment_url());

                die;
            }
        } else {
            return;
        }

        $finalResponse = WC()->session->get($order_info->order_key);
        WC()->session->set($order_info->order_key, "");

        $paymentTypeCode = $finalResponse->detailOutput->paymentTypeCode;
        $paymenCodeResult = $transbank_data->config['VENTA_DESC'][$paymentTypeCode];

        if ($finalResponse->detailOutput->responseCode == 0) {
            $transactionResponse = "Aceptado";
        } else {
            $transactionResponse = "Rechazado [" . $finalResponse->detailOutput->responseCode . "]";
        }

        $date_accepted = new DateTime($finalResponse->transactionDate);

        if ($finalResponse != null) {

            echo '</br><h2>Detalles del pago</h2>' .
            '<table class="shop_table order_details">' .
            '<tfoot>' .
            '<tr>' .
            '<th scope="row">Respuesta de la Transacci&oacute;n:</th>' .
            '<td><span class="RT">' . $transactionResponse . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Orden de Compra:</th>' .
            '<td><span class="RT">' . $finalResponse->buyOrder . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Codigo de Autorizaci&oacute;n:</th>' .
            '<td><span class="CA">' . $finalResponse->detailOutput->authorizationCode . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Fecha Transacci&oacute;n:</th>' .
            '<td><span class="FC">' . $date_accepted->format('d-m-Y') . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row"> Hora Transacci&oacute;n:</th>' .
            '<td><span class="FT">' . $date_accepted->format('H:i:s') . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Tarjeta de Cr&eacute;dito:</th>' .
            '<td><span class="TC">************' . $finalResponse->cardDetail->cardNumber . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Tipo de Pago:</th>' .
            '<td><span class="TP">' . $paymenCodeResult . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">Monto Compra:</th>' .
            '<td><span class="amount">' . $finalResponse->detailOutput->amount . '</span></td>' .
            '</tr>' .
            '<tr>' .
            '<th scope="row">N&uacute;mero de Cuotas:</th>' .
            '<td><span class="NC">' . $finalResponse->detailOutput->sharesNumber . '</span></td>' .
            '</tr>' .
            '</tfoot>' .
            '</table><br/>';
        }
    }

    add_action('woocommerce_thankyou', 'pay_content', 1);
    add_filter('woocommerce_payment_gateways', 'woocommerce_add_transbank_gateway');
}

if (strpos($_SERVER['REQUEST_URI'], "wc_gateway_transbank") && is_user_logged_in()){
    ?>
        <script src="../wp-content/plugins/woocommerce-transbank/integration/js/integration.js"></script>
    <?php
}