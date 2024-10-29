<?php
/** 
 * Plugin Name: ASAP 507 Panama Shipping
 * Description: Place delivery orders to ASAP Panama after changing the order to Complete.
 * Version: 2.0.0
 * Author: ASAP
 * Author URI: http://www.asap507.com
 */
/*
Changelog
Version 1.1.3
- New 'vehicle_type' parametre 

Version 1.1.2
- Multiple pickup locations 
- ASAP 'always present' box 
- New endpoint
- Error email notification
*/
if ( !defined( 'ABSPATH' ) ) {
    die( 'You are not allowed to call this page directly.' );
}


function wcgdsrd_add_submission( $url, $data, $headers, $customerDataAry )
{
    
    $args = array(
        'body' => $data,
        'timeout' => '30',
        'headers' => $headers
    );
    
    $response = wp_remote_post( $url, $args );
	
	if(wp_remote_retrieve_response_code($response)!=200){
		
		$email = get_option('wcrsprd_emasapcerr');
		
		if(!empty($email)){
			$to = $email;
			$subject = 'WooCommerce - No se pudo generar la orden en ASAP';
			$body1 = '<html><body><b>Order ID: </b>'.$customerDataAry['oid'];
			$body1.='<br><b>Cusomer Name: </b>'.$customerDataAry['cname'];
			$body1.='<br><b>Email: </b>'.$customerDataAry['cemail'];
			$body1.='<br><b>Phone: </b>'.$customerDataAry['cphone'];
			$body1.='<br><b>Date: </b>'.$customerDataAry['date'];
			$body1.='<br><b>Error: </b> Could not connect to API.';
			$body1.='</body></html>';
			
			$headers = array('Content-Type: text/html; charset=UTF-8');
			wp_mail( $to, $subject, $body1, $headers );
		}	
	}
    
    $body = wp_remote_retrieve_body( $response );
	
	
    
    $result = json_decode( $body, true );
    
    return $result;
}


function wcgdsrd_email_asap_tracking_link( $order, $sent_to_admin, $plain_text, $email )
{
    
    $trackLink    = get_post_meta( $order->get_id(), 'asap_tracking_link', true );
    $asapId       = get_post_meta( $order->get_id(), 'asap_delivery_id', true );
    $partialMatch = get_post_meta( $order->get_id(), 'partial_match', true );
    
    $partMsg = '';
    if ( !empty( $partialMatch ) ) {
        $partMsg = '<br><p>' . get_option( 'wcrsprd_apipmamsg' ) . '</p>';
    }
    
    if ( !empty( $asapId ) ) {
        
        echo '
            <h2>ASAP Tracking</h2>
            <ul>
            <li><strong>ASAP Tracking ID:</strong> ' . $asapId . '</li>
            <li><strong>Seguimiento del Envío:</strong> <a href="' . $trackLink . '">Ver en Mapa</a></li>
            </ul>
            <br>' . $partMsg;
    }
    
}
add_action( 'woocommerce_email_order_meta', 'wcgdsrd_email_asap_tracking_link', 10, 4 );


function wcgdsrd_asap_cancel_order($order_id,$order){
	$url         = get_option( 'wcrsprd_apimode' );
    $liveMode    = ( $url == 'https://goasap.app/ecommerce/v2/api' ) ? '_live' : '';
        
		
	$customerName = trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name());
	
	$deliveryId = get_post_meta( $order_id, 'asap_delivery_id', true );

	$data = array(
		 'user_token' => get_option( 'wcrsprd_apitoken' . $liveMode ),
		 'delivery_id' => $deliveryId,
         'addn_reason' => 'User order cancelled',
		 'shared_secret' => get_option( 'wcrsprd_apisecret' . $liveMode ) 
	);
	
	 $order->add_order_note( 'Cancel order request: ' . json_encode( $data ) );
		
		$headers = array('Content-Type' => 'application/json; charset=utf-8','x-api-key' => get_option('wcrsprd_apikey' . $liveMode));
		
        $customerDataAry = array('oid' => $order_id, 'date' => date('Y-m-d H:i:s'), 'cname' => $customerName, 'cemail' => $order->get_billing_email(),'cphone' => $order->get_billing_phone());
		
		
        $cancelShipOrder = wcgdsrd_add_submission( $url . '/cancel', json_encode( $data ), $headers, $customerDataAry);
        
        $order->add_order_note( 'Cancel order response: ' . json_encode( $cancelShipOrder ) );
        
        if($cancelShipOrder['flag']==143) {
			
			$to = $order->get_billing_email();
			$subject = 'Tu ASAP fue cancelado';
			$emailMsgg = 'El ASAP Tracking ID: '.$deliveryId.' ha sido cancelado.';
			ob_start();
			include('emails/email-template.php');
			$emailContent = ob_get_contents();
			ob_end_clean();
			
			$headers = array('Content-Type: text/html; charset=UTF-8');
			wp_mail( $to, $subject, $emailContent, $headers );
			
			update_post_meta( $order_id, 'cancelOrder', 'Y');
			update_post_meta( $order_id, 'asap_delivery_id', '');
			
			$resultAry['status'] = 'success';
			$resultAry['msg'] = 'Successful';

		} else {
			$resultAry['status'] = 'failed';
			$resultAry['msg'] = $cancelShipOrder['message'];
		}
	echo json_encode($resultAry);
}

function wcgdsrd_create_order_asap_order_complete( )
{
    $resultAry = array();
	$order_id = sanitize_text_field( $_POST['hid_pid'] );
	$pickupLocation =  sanitize_text_field( $_POST['asap_pickup_location'] );
	
	
    $order = new WC_Order( $order_id );
    
    if ( !$order ){
		$resultAry['status'] = 'failed';
		$resultAry['msg'] = 'Order not found.';
		
		echo json_encode($resultAry);
		exit;
	}
    
	if(isset($_POST['cancel_order']) && !empty($_POST['cancel_order'])){
			wcgdsrd_asap_cancel_order($order_id,$order);
			exit;
		}
	
	if(!isset($_POST['use_asap']) or empty($order_id) or empty($pickupLocation) or empty($_POST['dest_address']) or empty($_POST['dest_latitude']) or empty($_POST['dest_longitude'])){
		$resultAry['status'] = 'failed';
		$resultAry['msg'] = 'Please select all required fields.';
		
		echo json_encode($resultAry);
		exit;
	}
    
    if ( $order->is_paid()) {
		/*$address1 = $order->get_shipping_address_1();
		$address2 = $order->get_shipping_address_2();
		$city = $order->get_shipping_city();
		//$state = $fields['shipping_provincia'];
		$state = $order->get_shipping_state();
		$country = $order->get_shipping_country(); 
		
		if ( !empty( $address1 ) && !empty( $city ) && !empty( $state ) ) {
			$addressStr = $address1;
			if ( !empty( $address2 ) ) {
				$addressStr .= ',' . $address2;
			}

			if ( !empty( $city ) ) {
				$addressStr .= ',' . $city;
			}
			if ( !empty( $state ) ) {
				$addressStr .= ',' . $state;
			}
			if ( !empty( $country ) ) {
				$addressStr .= ',' . $country;
			}

			$gMapApiKey = get_option( 'wcrsprd_apigmap' );


			$response = wp_remote_get( 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode( $addressStr ) . '&key=' . $gMapApiKey );

			$responseAry = wp_remote_retrieve_body( $response );

			$responseAry = json_decode( $responseAry, true );

			if ( $responseAry['status'] == 'OK' ) {
				$destLat  = $responseAry['results'][0]['geometry']['location']['lat'];
				$destLng = $responseAry['results'][0]['geometry']['location']['lng'];
				
				update_post_meta( $order_id, 'asap_laitude_dest', $destLat );
				update_post_meta( $order_id, 'asap_longitude_dest', $destLng );
				
				if ( isset( $responseAry['results'][0]['partial_match'] ) ) {
					$order->add_order_note('Shipping address partial match.');
					update_post_meta( $order_id, 'partial_match', 'Y' );
				}
			} else {
				$resultAry['status'] = 'failed';
				$resultAry['msg'] = 'Latitude and longitude could not be found for shipping address.';

				echo json_encode($resultAry);
				exit;
			}
		} else {
			$resultAry['status'] = 'failed';
			$resultAry['msg'] = 'Shipping address fields are not set. Address line 1, city, state must be set.';

			echo json_encode($resultAry);
			exit;
		}
		$destAddress = $order->get_formatted_shipping_address();
        
        $destAddress = str_replace( array(
             "<br />",
            "<br>",
            "<br/>" 
        ), array(
             ',',
            ',',
            ',' 
        ), $destAddress );
		
		$tmpDestAddrs = explode(',',$destAddress);
		
		unset($tmpDestAddrs[0]);
		
		$destAddress = implode(',',$tmpDestAddrs);
		*/
		
        //$destLat    = get_post_meta( $order_id, 'asap_laitude_dest' );
        //$destLng    = get_post_meta( $order_id, 'asap_longitude_dest' );
		
		$asap_pickup_location = sanitize_text_field( $_POST['asap_pickup_location'] );
        update_post_meta( $order_id, 'asap_pickup_location', $asap_pickup_location);
        
		$destAddress = sanitize_text_field( $_POST['dest_address'] );
        update_post_meta( $order_id, 'dest_address', $destAddress);
        
		$destLat = sanitize_text_field( $_POST['dest_latitude'] );
        update_post_meta( $order_id, 'dest_latitude', $destLat);
        
		$destLng = sanitize_text_field( $_POST['dest_longitude'] );
        update_post_meta( $order_id, 'dest_longitude', $destLng);
		  
		$vehicle_type = sanitize_text_field( $_POST['asap_vehicle_type'] );
        update_post_meta( $order_id, 'asap_vehicle_type', $vehicle_type);
		
			
		$destAddress = str_replace( array(
             "<br />",
            "<br>",
            "<br/>" 
        ), array(
             ',',
            ',',
            ',' 
        ), $destAddress );	
		
		$getLocations = get_option( 'wcrsprd_pickup_locations' );
		$locationsAry = array();

		if(!empty($getLocations)){
			$locationsAry = json_decode($getLocations, true);
			foreach($locationsAry as $arK => $lAry){
				if($pickupLocation==$arK){
					$srcLat     = $lAry['latitude'];
        			$srcLng     = $lAry['longitude'];
        			$srcAddress = $lAry['direction'];
					break;
				}
			}
		}
        
        $url         = get_option( 'wcrsprd_apimode' );
        $liveMode    = ( $url == 'https://goasap.app/ecommerce/v2/api' ) ? '_live' : '';
        
		
		$customerName = trim($order->get_shipping_first_name().' '.$order->get_shipping_last_name());
        
         $destNote = 'Order Number: '. $order_id .', Name: ' . $customerName . ', Phone: ' . $order->get_billing_phone() . ', Address: ' . $destAddress;
        
        $data = array(
             'user_token' => get_option( 'wcrsprd_apitoken' . $liveMode ),
            'type_id' => 2,
            'is_personal' => 0,
            'is_oneway' => 1,
            'source_address' => utf8_encode( $srcAddress ),
            'source_lat' => $srcLat,
            'source_long' => $srcLng,
            'special_inst' => '-',
            'desti_address' => utf8_encode($destAddress),
            'desti_lat' => $destLat,
            'desti_long' => $destLng,
            'dest_special_inst' => $destNote,
            'shared_secret' => get_option( 'wcrsprd_apisecret' . $liveMode ),
            'vehicle_type' => $vehicle_type
        );
        
        
        $reqLater = 0;
        
        
        $data['request_later'] = $reqLater;
        
        $order->add_order_note( 'Create order request: ' . json_encode( $data ) );
		
		$headers = array('Content-Type' => 'application/json; charset=utf-8','x-api-key' => get_option('wcrsprd_apikey' . $liveMode));
		
        $customerDataAry = array('oid' => $order_id, 'date' => date('Y-m-d H:i:s'), 'cname' => $customerName, 'cemail' => $order->get_billing_email(),'cphone' => $order->get_billing_phone());
		
		
        $addShipOrder = wcgdsrd_add_submission( $url . '/order', json_encode( $data ), $headers, $customerDataAry);
        
        $order->add_order_note( 'Create order response: ' . json_encode( $addShipOrder ) );
        
        if ( isset( $addShipOrder['status'] ) && isset( $addShipOrder['result']['delivery_id'] ) ) {
            update_post_meta( $order_id, 'asap_delivery_id', $addShipOrder['result']['delivery_id'] );
			update_post_meta( $order_id, 'cancelOrder', 'N');
            $data = array(
                 'user_token' => get_option( 'wcrsprd_apitoken' . $liveMode ),
                'delivery_id' => $addShipOrder['result']['delivery_id'],
                'shared_secret' => get_option( 'wcrsprd_apisecret' . $liveMode ) 
            );
            
            $order->add_order_note( 'Get tracking link request: ' . json_encode( $data ) );
            
			$args = array(
				'timeout' => '30',
				'redirection' => '5',
				'httpversion' => '1.0',
				'blocking' => true,
				'headers' => $headers,
				'cookies' => array ()
			);
			
            $getLink1 = wp_remote_get( $url . '/order/tracking?' .
                    http_build_query($data), $args );
            
            $getLink = json_decode( wp_remote_retrieve_body( $getLink1 ), true );
            
            if ( isset( $getLink['tracking_link'] ) ) {
                update_post_meta( $order_id, 'asap_tracking_link', $getLink['tracking_link'] );
                $order->add_order_note( 'ASAP Tracking Link: ' . $getLink['tracking_link'] );
				//Send Email:
				
				$to = $order->get_billing_email();
				$subject = 'Tu ASAP está en camino';
				$emailMsgg = '<strong>Pedido: </strong>'.$order_id;
				$emailMsgg.='<br><strong>ASAP Tracking ID: </strong>'.$addShipOrder['result']['delivery_id'];
				$emailMsgg.='<br><strong>Seguimiento del Envío: </strong><a href="'.$getLink['tracking_link'].'">Ver en Mapa</a>';
				
				ob_start();
				include('emails/email-template.php');
				$emailContent = ob_get_contents();
				ob_end_clean();

				$headers = array('Content-Type: text/html; charset=UTF-8');
				wp_mail( $to, $subject, $emailContent, $headers );
				
				//do_action('woocommerce_order_status_completed', $order_id);
				
            } else {
                $order->add_order_note( 'ASAP Tracking Link Error: ' . $getLink1 );
            }
			
			$resultAry['status'] = 'success';
		
			echo json_encode($resultAry);
			exit;
			
        } else {
            $order->add_order_note( 'ASAP API error: ' . json_encode( $addShipOrder ) );
			$resultAry['status'] = 'failed';
			$resultAry['msg'] = 'ASAP API error occurred. Check order notes for more info.';
			
			$email = get_option('wcrsprd_emasaporderr');
		
		if(!empty($email)){
			$to = $email;
			$subject = 'WooCommerce - No se pudo generar la orden en ASAP';
			$body1 = '<html><body><b>Order ID: </b>'.$customerDataAry['oid'];
			$body1.='<br><b>Cusomer Name: </b>'.$customerDataAry['cname'];
			$body1.='<br><b>Email: </b>'.$customerDataAry['cemail'];
			$body1.='<br><b>Phone: </b>'.$customerDataAry['cphone'];
			$body1.='<br><b>Date: </b>'.$customerDataAry['date'];
			$body1.='<br><b>Error: </b> '.json_encode( $addShipOrder );
			$body1.='</body></html>';
			
			$headers = array('Content-Type: text/html; charset=UTF-8');
			wp_mail( $to, $subject, $body1, $headers );
		}	

			echo json_encode($resultAry);
			exit;
        }
    }
}
//add_action( 'woocommerce_order_status_completed', 'wcgdsrd_create_order_asap_order_complete' );



function wcgdsrd_validate_address( $fields, $errors )
{
    wcgddrd_chck_session();
    
    if(isset($fields['ship_to_different_address']) && !empty($fields['ship_to_different_address'])){
		$address1 = $fields['shipping_address_1'];
		$address2 = $fields['shipping_address_2']; 
		$country = $fields['shipping_country']; 
		$city = $fields['shipping_city'];
		//$state = $fields['shipping_provincia'];
		$state = $fields['shipping_state'];
		// this field is WC standard but for GiftyThings we are using _provincia
	} else {
		$address1 = $fields['billing_address_1'];
		$address2 = $fields['billing_address_2']; 
		$country = $fields['billing_country']; 
		$city = $fields['billing_city'];
		//$state = $fields['shipping_provincia'];
		$state = $fields['billing_state'];
	}
    
    if ( !empty( $address1 ) && !empty( $city ) && !empty( $state ) && !empty( $country ) ) {
        $addressStr = $address1;
        if ( !empty( $address2 ) ) {
            $addressStr .= ',' . $address2;
        }
        
        if ( !empty( $city ) ) {
            $addressStr .= ',' . $city;
        }
        if ( !empty( $state ) ) {
            $addressStr .= ',' . $state;
        }
        if ( !empty( $country ) ) {
            $addressStr .= ',' . $country;
        }
        
        $gMapApiKey = get_option( 'wcrsprd_apigmap' );
        
        
        $response = wp_remote_get( 'https://maps.googleapis.com/maps/api/geocode/json?address=' . urlencode( $addressStr ) . '&key=' . $gMapApiKey );
        
        $responseAry = wp_remote_retrieve_body( $response );
        
        $responseAry = json_decode( $responseAry, true );
        
        if ( $responseAry['status'] == 'OK' ) {
            $_SESSION['latitude']  = $responseAry['results'][0]['geometry']['location']['lat'];
            $_SESSION['longitude'] = $responseAry['results'][0]['geometry']['location']['lng'];
            if ( isset( $responseAry['results'][0]['partial_match'] ) ) {
                $_SESSION['partial_match'] = 'Y';
            }
        } else {
            //$errors->add( 'validation', get_option( 'wcrsprd_apilaterror' ) );
        }
    } else {
        $errors->add( 'validation', 'Please fill all required address fields.' );
    }
}
add_action( 'woocommerce_after_checkout_validation', 'wcgdsrd_validate_address', 10, 2 );

function wcgdsrd_set_address_latlong( $order_id )
{
    wcgddrd_chck_session();
    
    if ( isset( $_SESSION['latitude'] ) && !empty( $_SESSION['latitude'] ) ) {
        update_post_meta( $order_id, 'asap_laitude_dest', sanitize_text_field( $_SESSION['latitude'] ) );
        unset( $_SESSION['latitude'] );
    }
    
    if ( isset( $_SESSION['longitude'] ) && !empty( $_SESSION['longitude'] ) ) {
        update_post_meta( $order_id, 'asap_longitude_dest', sanitize_text_field( $_SESSION['longitude'] ) );
        unset( $_SESSION['longitude'] );
    }
    
    if ( isset( $_SESSION['partial_match'] ) && !empty( $_SESSION['partial_match'] ) ) {
        update_post_meta( $order_id, 'partial_match', sanitize_text_field( $_SESSION['partial_match'] ) );
        unset( $_SESSION['partial_match'] );
    }
    
}


add_action( 'woocommerce_checkout_update_order_meta', 'wcgdsrd_set_address_latlong' );

function wcgdsrd_delivery_instructions()
{
    echo '<div class="woocommerce-delivery-instructions">
        	<p>' . get_option( 'wcrsprd_apidelivery' ) . '</p>
		</div>';
}
add_action( 'woocommerce_checkout_after_terms_and_conditions', 'wcgdsrd_delivery_instructions' );

//Add Meta box to order
add_action( 'add_meta_boxes', 'wcgdsrd_asap_metabox' );
function wcgdsrd_asap_metabox()
{
	add_meta_box( 'wcgdsrd_asap_fields', __('ASAP Shipping','woocommerce'), 'wcgdsrd_asap_order_fields', 'shop_order', 'normal','default' );
}
function wcgdsrd_formatted_shipping_address($order)
{
    if(!empty($order->shipping_address_1)) {

        return
            $order->shipping_address_1 . ', ' . 
            $order->shipping_address_2 . ' ' .
            $order->shipping_city      . ', ' .
            $order->shipping_state;
    }
}
function wcgdsrd_asap_order_fields()
{
	global $post;
	
	$getLocations = get_option( 'wcrsprd_pickup_locations' );
	$locationsAry = array();
	
	if(!empty($getLocations)){
		$locationsAry = json_decode($getLocations, true);
	}
	
    $asapId = get_post_meta( $post->ID, 'asap_delivery_id', true );
	
	$order = new WC_Order( $post->ID );
	
    
    $asap_pickup_location = get_post_meta( $post->ID, 'asap_pickup_location', true );
    
    $new_dest_address = get_post_meta( $post->ID, 'dest_address', true );
    
    $destination_address = wcgdsrd_formatted_shipping_address($order);
    
    $asap_laitude_dest    = get_post_meta( $post->ID, 'asap_laitude_dest', true );
    
    $new_dest_lat = get_post_meta( $post->ID, 'dest_latitude', true );
    
    $asap_longitude_dest  = get_post_meta( $post->ID, 'asap_longitude_dest', true );
    
    $new_dest_lon = get_post_meta( $post->ID, 'dest_longitude', true );
    
    $asap_vehicle_type = get_post_meta( $post->ID, 'asap_vehicle_type', true );
	
	//$orderCancelled = get_post_meta( $post->ID, 'cancelOrder', true );
		
	foreach( $order->get_items( 'shipping' ) as $item_id => $shipping_item_obj ){
        $shipping_item_data = $shipping_item_obj->get_data();
		$shipName = trim($shipping_item_data['name']);
		$shipMethod = trim($shipping_item_data['method_title']);
		
		if(strtolower($shipName)=='asap' or strtolower($shipMethod)=='asap')
		{
			$goAhead = 1;
			break;
		}
	}
	
	?>
			<div id="errDiv" style="color: #B8001C; font-size: 1.1rem;"></div>
		<table class="" border="0" cellpadding="10">
			<tr>
				<td><h3>Utilzar ASAP:</h3></td>
				<td><label><input type="checkbox" id="use_asap" value="1" <?php if(!empty( $asapId)) { echo 'checked disabled'; } if(isset($goAhead)) echo 'checked'; ?> /> Sí</label></td>
			</tr>
			
			<tr>
				<td>
                    <h3>Origen:</h3>
                </td>
				<td>
                    <select id="asap_pickup_location" <?php if(!empty( $asapId)) echo 'disabled'; ?>>
					
                        <?php
                        if(!empty($locationsAry)){
                            $i = 1;
                            foreach($locationsAry as $arK => $lAry){
                                $location = "pl_$i";
                                $selected = '';
                                if(!empty($asapId)) {
                                    if($location === $asap_pickup_location) {
                                        $selected = ' selected';
                                    }
                                }
                                echo '<option value="'. $arK .'"'.$selected.'>'.$lAry['nombre'].'--> '. $lAry['direction'] .'</option>';
                                $i++;
                            }
                        } 
                        ?>
					</select>
                </td>
			</tr>
			
			
			<?php if(empty( $asapId)){ 
            
            ?>
			<tr>
				<td><h3>Dirección de Destino:</h3></td>
				<td><input type="text" id="dest_address" value="<?php echo $destination_address;?>" <?php if(!empty( $asapId)) { echo 'disabled'; } ?> style="width: 100%;" /> </td>
			</tr>
			
			<tr>
				<td><h3>Latitud de Destino:</h3></td>
				<td><input type="text" id="dest_latitude" value="<?php echo $asap_laitude_dest; ?>" <?php if(!empty( $asapId)) { echo 'disabled'; } ?> /> </td>
			</tr>
			
			<tr>
				<td><h3>Longitud de Destino:</h3></td>
				<td><input type="text" id="dest_longitude" value="<?php echo $asap_longitude_dest; ?>" <?php if(!empty( $asapId)) { echo 'disabled'; } ?> /> </td>
			</tr>
			
            <tr>
				<td>
                    <h3>Tipo de Vehículo:</h3>
                </td>
				<td>
                    <?php 
                        $selectedBike = '';
                        $selectedCar = '';
                            
                        if(!empty( $asapId)) {
                            if(($asap_vehicle_type == 'bike')) {
                                $selectedBike = ' selected';
                            }
                            if(($asap_vehicle_type == 'car')) {
                                $selectedCar = ' selected';
                            }
  
                        } 
                    ?>
                    <select id="asap_vehicle_type" <?php if(!empty( $asapId)) echo 'disabled'; ?>>
                        <option value="bike" <?php echo $selectedBike; ?>>Moto</option>
                        <option value="car"  <?php echo $selectedCar; ?>>Carro</option>
					</select>
                </td>
			</tr>
			
			<tr>
				<td colspan="2">
					<button type="button" id="updt_asap_btn" class="btn btn-primary button button-primary" value="Solicitar ASAP" style="cursor:pointer;">Solicitar ASAP</button>
				</td>
			</tr>
			
			<?php } else{
			$trackingLink = get_post_meta( $post->ID, 'asap_tracking_link', true );
			?>
            
			<tr>
				<td><h3>ASAP Delivery ID:</h3></td>
				<td><?php echo $asapId; ?></td>
			</tr>
            <tr>
                <td><h3>Dirección de Destino:</h3></td>
                <td><?php echo $new_dest_address; ?></td>
            </tr>
            <tr>
                <td><h3>Latitud de Destino:</h3></td>
                <td><?php echo $new_dest_lat;?></td>
            </tr>
            <tr>
                <td><h3>Longitud de Destino:</h3></td>
                <td><?php echo $new_dest_lon;?></td>
            </tr>
            <tr>
                <td><h3>Tipo de Vehículo:</h3></td>
                <td>
                    <?php
                        if($asap_vehicle_type == 'bike') {
                               echo 'Moto';
                           } 
                        if($asap_vehicle_type == 'car') {
                               echo 'Carro';
                        }
                    ?>
                </td>
            </tr>
			
			<tr>
				<td><h3>ASAP Tracking Link:</h3></td>
				<td><a href="<?php echo $trackingLink; ?>" target="_blank">Click here</a></td>
			</tr>
			<?php
			if(get_post_meta( $post->ID, 'cancelOrder', true )!='Y'){ ?>
			<tr>
				<td colspan="2">
					<input type="hidden" name="hid_pid" value="<?php echo $post->ID; ?>" />
					<button type="button" id="cancl_asap_btn" class="btn btn-danger button button-danger">Cancel Order</button>
				</td>
			</tr>
			
			<?php } else {
				echo '<tr>
				<td colspan="2"><strong style="color:red;">Solicitud cancelada.</strong></td>
			</tr>';
			} 
	} ?>
		</table>
			<input type="hidden" name="hid_pid" value="<?php echo $post->ID; ?>" />
		
<script>
	jQuery(document).ready(function($){
		$('#updt_asap_btn').on('click',function(){
			$('#errDiv').html('');
			if($('#asap_pickup_location').val()==''){
				$('#errDiv').html("Please select pickup location");
				return false;
			}
			
			if($('#use_asap:checked').length<1){
				$('#errDiv').html("Please check the Use Asap checkbox.");
				return false;
			}
			
			
			if($('#dest_address').val()==''){
				$('#errDiv').html("Please enter destination address.");
				return false;
			}
			
			if($('#dest_latitude').val()==''){
				$('#errDiv').html("Please enter destination latitude.");
				return false;
			}
			
			if($('#dest_longitude').val()==''){
				$('#errDiv').html("Please enter destination longitude.");
				return false;
			}
            
			/*if($('#asap_vehicle_type').val()==''){
				$('#errDiv').html("Por favor seleccionar el tipo de vehículo.");
				return false;
			}*/
			
			$.ajax({
				type: "POST",
				url: '<?php echo site_url().'/wp-admin/admin-ajax.php'; ?>',
				data: {
                    hid_pid: $('input[name=hid_pid]').val(), 
                    action: 'wcgdsrd_callasap', 
                    asap_pickup_location: $('#asap_pickup_location').val(),
                    use_asap:'1',
                    dest_address: $('#dest_address').val(),
                    dest_latitude: $('#dest_latitude').val(),
                    dest_longitude: $('#dest_longitude').val(),
                    asap_vehicle_type: $('#asap_vehicle_type').val() },//$('#asapship_ordform').serialize() 
				cache: false,
				success: function(response){
					var respData = JSON.parse(response);
					if(respData.status=='success'){
						window.location.reload();
					} else {
						$('#errDiv').html(respData.msg);
					}
				}
			});
		});
		
		
		
		$('#cancl_asap_btn').on('click',function(){
			$('#errDiv').html('');
			
			$.ajax({
				type: "POST",
				url: '<?php echo site_url().'/wp-admin/admin-ajax.php'; ?>',
				data: {
					   hid_pid: $('input[name=hid_pid]').val(), 
					  action: 'wcgdsrd_callasap',cancel_order: 'cancelled'},
				cache: false,
				success: function(response){
					var response = JSON.parse(response);
					if(response.status=='success'){
						window.location.reload();
					} else {
						$('#errDiv').html(response.msg);
					}
				}
			});
		});
		
	});
</script>
	<?php
}

add_action( 'wp_ajax_wcgdsrd_callasap', 'wcgdsrd_create_order_asap_order_complete' );
////Admin Settings

function wcgdsrd_asap_admin_menu()
{
    
    add_submenu_page( 'woocommerce', 'ASAP Shipping API Settings', 'ASAP Shipping', 'manage_options', 'wcrsprd-asap-settings', 'wcgdsrd_asap_admin_settings' );
    
}

function wcgdsrd_asap_admin_settings()
{
    if ( isset( $_POST['wcrsprd_apitoken'] ) ) {
        update_option( 'wcrsprd_apimode', sanitize_text_field( $_POST['wcrsprd_apimode'] ) );
        update_option( 'wcrsprd_apitoken', sanitize_text_field( $_POST['wcrsprd_apitoken'] ) );
        update_option( 'wcrsprd_apisecret', sanitize_text_field( $_POST['wcrsprd_apisecret'] ) );
		update_option( 'wcrsprd_apikey', sanitize_text_field( $_POST['wcrsprd_apikey'] ) );
        update_option( 'wcrsprd_apitoken_live', sanitize_text_field( $_POST['wcrsprd_apitoken_live'] ) );
        update_option( 'wcrsprd_apisecret_live', sanitize_text_field( $_POST['wcrsprd_apisecret_live'] ) );
		update_option( 'wcrsprd_apikey_live', sanitize_text_field( $_POST['wcrsprd_apikey_live'] ) );
        update_option( 'wcrsprd_apigmap', sanitize_text_field( $_POST['wcrsprd_apigmap'] ) );
        
        update_option( 'wcrsprd_apidelivery', sanitize_text_field( $_POST['wcrsprd_apidelivery'] ) );
        update_option( 'wcrsprd_apipmamsg', sanitize_text_field( $_POST['wcrsprd_apipmamsg'] ) );
		
       
        update_option( 'wcrsprd_emasaporderr', sanitize_text_field( $_POST['wcrsprd_emasaporderr'] ) );
        update_option( 'wcrsprd_emasapcerr', sanitize_text_field( $_POST['wcrsprd_emasapcerr'] ) );
		
		update_option( 'wcrsprd_forceasapp', sanitize_text_field( $_POST['wcrsprd_forceasapp'] ) );
		
        //update_option( 'wcrsprd_apisrclong', sanitize_text_field( $_POST['wcrsprd_apisrclong'] ) );
        
        update_option( 'wcrsprd_add_provinces_dropdown', sanitize_text_field( $_POST['wcrsprd_add_provinces_dropdown'] ) );
		
		//update locations
		$pLocations = sanitize_text_field( $_POST['hid_pl_counter'] );
		
		$saveLocAry = array();
		
		if($pLocations>0){
			for($i=1;$i<=$pLocations;$i++){
				$arKey = 'pl_'.$i;
				$saveLocAry[$arKey] = array(
					'nombre' => sanitize_text_field( $_POST['nombre'.$i]),
					'direction' => sanitize_text_field( $_POST['direction'.$i]),
					'latitude' => sanitize_text_field( $_POST['latitude'.$i]),
					'longitude' => sanitize_text_field( $_POST['longitude'.$i]),
				);
			}
		}
		
		update_option('wcrsprd_pickup_locations', json_encode($saveLocAry));
        
        echo '<h3 class="wrap" style="color: #4a9c4a;background: #fff;font-weight: 400;padding: 10px;border: 1px solid #000;">Settings updated.</h3>';
    }
    
    $mode = ( get_option( 'wcrsprd_apimode' ) == false ) ? 'https://goasap.dev/ecommerce/v2/api' : get_option( 'wcrsprd_apimode' );
    
    $provinces_dropdown = get_option( 'wcrsprd_add_provinces_dropdown' );
	
	$getLocations = get_option( 'wcrsprd_pickup_locations' );
	$locationsAry = array();
	
	if(!empty($getLocations)){
		$locationsAry = json_decode($getLocations, true);
	}
    
?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Integración con ASAP</h1>
       <br>
        <form method="post" action="">
        <table class="" border="0" cellpadding="10">
            <tbody>
                <tr>
                    <td><h3>API Mode: </h3></td>
                    <td style="vertical-align:middle;">
                        <label>
                            <input type="radio" name="wcrsprd_apimode" value="https://goasap.app/ecommerce/v2/api" <?php
    if ( $mode == 'https://goasap.app/ecommerce/v2/api' ) {
        echo 'checked';
    }
?> /> Live
                        </label>
                        &nbsp;
                        <label>
                            <input type="radio" name="wcrsprd_apimode" value="https://goasap.dev/ecommerce/v2/api" <?php
    if ( $mode == 'https://goasap.dev/ecommerce/v2/api' ) {
        echo 'checked';
    }
?> /> Test
                        </label>
                    </td>
                </tr>

                <tr>
                    <td><h3>ASAP API Token (Test): </h3></td>
                    <td><input type="text" name="wcrsprd_apitoken" value="<?php
    echo ( get_option( 'wcrsprd_apitoken' ) == false ) ? '' : get_option( 'wcrsprd_apitoken' );
?>" style="width:500px;" /></td>
                </tr>

                <tr>
                    <td><h3>ASAP API Shared Secret (Test): </h3></td>
                    <td><input type="text" name="wcrsprd_apisecret" value="<?php
    echo ( get_option( 'wcrsprd_apisecret' ) == false ) ? '' : get_option( 'wcrsprd_apisecret' );
?>" style="width:500px;" /></td>
                </tr>
				
				<tr>
                    <td><h3>ASAP API Key (Test): </h3></td>
                    <td><input type="text" name="wcrsprd_apikey" value="<?php
    echo ( get_option( 'wcrsprd_apikey' ) == false ) ? '' : get_option( 'wcrsprd_apikey' );
?>" style="width:500px;" /></td>
                </tr>

                <tr>
                    <td><h3>ASAP API Token (Live): </h3></td>
                    <td><input type="text" name="wcrsprd_apitoken_live" value="<?php
    echo ( get_option( 'wcrsprd_apitoken_live' ) == false ) ? '' : get_option( 'wcrsprd_apitoken_live' );
?>" style="width:500px;" /></td>
                </tr>

                <tr>
                    <td><h3>ASAP API Shared Secret (Live): </h3></td>
                    <td><input type="text" name="wcrsprd_apisecret_live" value="<?php
    echo ( get_option( 'wcrsprd_apisecret_live' ) == false ) ? '' : get_option( 'wcrsprd_apisecret_live' );
?>" style="width:500px;" /></td>
                </tr> 
				
				<tr>
                    <td><h3>ASAP API Key (Live): </h3></td>
                    <td><input type="text" name="wcrsprd_apikey_live" value="<?php
    echo ( get_option( 'wcrsprd_apikey_live' ) == false ) ? '' : get_option( 'wcrsprd_apikey_live' );
?>" style="width:500px;" /></td>
                </tr> 

                <tr>
                    <td><h3>Google Maps API Key: </h3></td>
                    <td><input type="text" name="wcrsprd_apigmap" value="<?php
    echo ( get_option( 'wcrsprd_apigmap' ) == false ) ? '' : get_option( 'wcrsprd_apigmap' );
?>" style="width:500px;" /></td>
                </tr> 

                 <tr>
                    <td><h3>Instrucciones del Delivery: </h3></td>
                    <td><textarea name="wcrsprd_apidelivery" style="width:500px;" rows="8"><?php
    echo ( get_option( 'wcrsprd_apidelivery' ) == false ) ? '' : get_option( 'wcrsprd_apidelivery' );
?></textarea></td>
                </tr>   

                 <tr>
                    <td><h3>Mensaje por si ocurre un Partial Match en Google Maps: </h3></td>
                    <td><textarea name="wcrsprd_apipmamsg" style="width:500px;" rows="8"><?php
    echo ( get_option( 'wcrsprd_apipmamsg' ) == false ) ? '' : get_option( 'wcrsprd_apipmamsg' );
?></textarea></td>
                </tr>  
                
                
                <tr>
                    <td><h3>¿Agregar Provincias Como Dropdown?</h3></td>
                    <td style="vertical-align:middle;">
                        <label>
                            <input type="radio" name="wcrsprd_add_provinces_dropdown" value="No" <?php
    if ( $provinces_dropdown == 'No' ) {
        echo 'checked';
    }
?> /> No
                        </label>
                        &nbsp;
                        <label>
                            <input type="radio" name="wcrsprd_add_provinces_dropdown" value="Si" <?php
    if ( $provinces_dropdown == 'Si' ) {
        echo 'checked';
    }
?> /> Si
                        </label>
                    </td>
                </tr>
                
				<tr>
                    <td><h3>Email address for ASAP connection error: </h3></td>
                    <td><input type="text" name="wcrsprd_emasapcerr" value="<?php
    echo ( get_option( 'wcrsprd_emasapcerr' ) == false ) ? '' : get_option( 'wcrsprd_emasapcerr' );
?>" style="width:500px;" /></td>
                </tr> 
				
				<tr>
                    <td><h3>Email address for ASAP order error: </h3></td>
                    <td><input type="text" name="wcrsprd_emasaporderr" value="<?php
    echo ( get_option( 'wcrsprd_emasaporderr' ) == false ) ? '' : get_option( 'wcrsprd_emasaporderr' );
?>" style="width:500px;" /></td>
                </tr> 
				
				<tr>
                    <td><h3>Forzar Actualización de Métodos de Envío: </h3></td>
                    <td style="vertical-align:middle;">
                        <label>
                            <input type="radio" name="wcrsprd_forceasapp" value="1" <?php
    if ( get_option( 'wcrsprd_forceasapp' ) == '1' or  get_option( 'wcrsprd_forceasapp' )==false) {
        echo 'checked';
    }
?> /> Si
                        </label>
                        &nbsp;
                        <label>
                            <input type="radio" name="wcrsprd_forceasapp" value="2" <?php
    if ( get_option( 'wcrsprd_forceasapp' ) == '2' ) {
        echo 'checked';
    }
?> /> No
                        </label>
                    </td>
                </tr>
				
            </tbody>
        </table>
        <br>
		
			<h3>Pickup Locations</h3>
		<table border="0" cellpadding="10" id="locationTbl">
			<th>Nombre</th>
			<th>Dirección</th>
			<th>Latitude</th>
			<th>Longitude</th>
			<th>Action</th>
			<?php if(!empty($locationsAry)){
		$counter = 1;
		foreach($locationsAry as $lAry){
			?>
			<tr>
				<td><input type="text" name="nombre<?php echo $counter; ?>" id="nombre<?php echo $counter; ?>" value="<?php echo $lAry['nombre']; ?>" required /></td>
				<td><input type="text" name="direction<?php echo $counter; ?>" id="direction<?php echo $counter; ?>" value="<?php echo $lAry['direction']; ?>" required /></td>
				<td><input type="text" name="latitude<?php echo $counter; ?>" id="latitude<?php echo $counter; ?>" value="<?php echo $lAry['latitude']; ?>" required /></td>
				<td><input type="text" name="longitude<?php echo $counter; ?>" id="longitude<?php echo $counter; ?>" value="<?php echo $lAry['longitude']; ?>" required /></td>
				<td><input type="button" value="Remove Location" onClick="var curVal = jQuery('#hid_pl_counter').val(); jQuery('#hid_pl_counter').val(curVal-1); jQuery(this).parent().parent().remove();" /></td>
			</tr>
			<?php
			$counter++;
		}
	} ?>
		</table>
			
		<br />
			<input type="hidden" name="hid_pl_counter" id="hid_pl_counter" value="<?php echo sizeof($locationsAry); ?>" />
			<input type="button" id="addLocation" value="Add Location" />
		<br /><br />
        <input type="submit" value="Guardar" class="button button-primary" />
            
    </form>
        <script>
			jQuery(document).ready(function($){
				$('#addLocation').on('click',function(){
					var curVal = $('#hid_pl_counter').val();
					var nextVal = curVal -1 + 2;
					$('#locationTbl').append('<tr><td><input type="text" name="nombre'+ nextVal +'" id="nombre'+ nextVal +'" value="" required /></td><td><input type="text" name="direction'+ nextVal +'" id="direction'+ nextVal +'" value="" required /></td><td><input type="text" name="latitude'+ nextVal +'" id="latitude'+ nextVal +'" value="" required /></td><td><input type="text" name="longitude'+ nextVal +'" id="longitude'+ nextVal +'" value="" required /></td><td><input type="button" value="Remove Location" onClick="var curVal = jQuery(\'#hid_pl_counter\').val(); jQuery(\'#hid_pl_counter\').val(curVal-1); jQuery(this).parent().parent().remove();" /></td></tr>');
					
					$('#hid_pl_counter').val(nextVal);
					return false;
				});
			});
		</script>
    </div>
    <?php
}
add_action( 'admin_menu', 'wcgdsrd_asap_admin_menu', 10 );

function wcgddrd_chck_session()
{
    if ( session_status() == PHP_SESSION_NONE ) {
        @session_start();
    }
}
/**
 * Add Provinces to Checkout Dropdown
 */

$add_provinces_dropdown = get_option( 'wcrsprd_add_provinces_dropdown' );
if ( $add_provinces_dropdown == "Si" ) {
    add_filter( 'woocommerce_states', 'wcgddrd_woocommerce_states' );
    
    function wcgddrd_woocommerce_states( $states )
    {
        
        $states['PA'] = array(
             'panama' => 'Ciudad de Panamá',
            'bocas-del-toro' => 'Bocas del Toro',
            'cocle' => 'Coclé',
            'colon' => 'Colón',
            'chiriqui' => 'Chiriquí',
            'darien' => 'Darién',
            'herrera' => 'Herrera',
            'los-santos' => 'Los Santos',
            'veraguas' => 'Veraguas',
            'panama-oeste' => 'Panama Oeste' 
        );
        
        return $states;
    }
}

function wcrsprd_update_woocommerce_shipping_region_change(){
  if ( function_exists('is_checkout') && is_checkout() ) {
    ?>
    <script>
      window.addEventListener('load', function(){
        var el = document.getElementById("billing_state_field");
        el.className += ' update_totals_on_change'; 
      });
    </script>
    <?php 
  }
}

if(get_option( 'wcrsprd_forceasapp' )!== false && get_option( 'wcrsprd_forceasapp' )<2){
	add_action('wp_print_footer_scripts', 'wcrsprd_update_woocommerce_shipping_region_change');	
}

?>