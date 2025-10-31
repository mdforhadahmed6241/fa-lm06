<?php
/**
 * Helper functions for the Direct Courier API logic.
 * - Data Normalization (faplm_normalize_responses)
 * - Steadfast Bot (faplm_get_steadfast_session_data)
 * - RedEx Bot (faplm_get_redex_session_data)
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * =================================================================
 * STEP 3: STEADFAST AUTOMATION BOT
 * =================================================================
 */

/**
 * Automatically logs into Steadfast to fetch and cache a valid session.
 *
 * @return object|WP_Error An object containing {session_cookie_value, xsrf_token_value} on success,
 * or WP_Error on failure.
 */
function faplm_get_steadfast_session_data() {

	// 1. Check for Cached Session
	$cached_session = get_transient( 'steadfast_session_data' );
	if ( $cached_session ) {
		return $cached_session;
	}

	// 2. Handle Cache Miss: Run the Bot
	$login_url = 'https://steadfast.com.bd/login';

	// --- Part A: GET Request (Get _token and initial cookies) ---
	$get_response = wp_remote_get( $login_url, array( 'timeout' => 15 ) );

	if ( is_wp_error( $get_response ) ) {
		return new WP_Error( 'steadfast_get_failed', 'Failed to GET Steadfast login page.', $get_response->get_error_message() );
	}

	$initial_cookies = wp_remote_retrieve_cookies( $get_response );
	$html_body       = wp_remote_retrieve_body( $get_response );

	// Parse the _token from the HTML
	$token = '';
	// More robust regex to find the token
	if ( preg_match( '/<input[^>]+name="_token"[^>]+value="([^"]+)"/', $html_body, $matches ) ) {
		$token = $matches[1];
	}

	if ( empty( $token ) ) {
		return new WP_Error( 'steadfast_token_not_found', 'Could not find _token on Steadfast login page.' );
	}

	// --- Part B: POST Request (Attempt Login) ---

	// Retrieve saved credentials
	$options  = get_option( 'faplm_courier_settings' );
	$email    = isset( $options['steadfast_email'] ) ? $options['steadfast_email'] : '';
	$password = isset( $options['steadfast_password'] ) ? $options['steadfast_password'] : '';

	if ( empty( $email ) || empty( $password ) ) {
		return new WP_Error( 'steadfast_no_creds', 'Steadfast email or password is not set in settings.' );
	}

	$post_args = array(
		'timeout' => 15,
		'body'    => array(
			'_token'   => $token,
			'email'    => $email,
			'password' => $password,
		),
		'cookies' => $initial_cookies, // Pass back the cookies we just received
	);

	$post_response = wp_remote_post( $login_url, $post_args );

	if ( is_wp_error( $post_response ) ) {
		return new WP_Error( 'steadfast_post_failed', 'Failed to POST to Steadfast login page.', $post_response->get_error_message() );
	}

	// --- Part C: Handle Response (Parse Cookies) ---
	$final_headers = wp_remote_retrieve_headers( $post_response );

	// wp_remote_retrieve_header() automatically handles arrays or strings for 'set-cookie'
	$set_cookie_headers = wp_remote_retrieve_header( $post_response, 'set-cookie' );

	if ( empty( $set_cookie_headers ) ) {
		return new WP_Error( 'steadfast_login_failed', 'Login to Steadfast failed. No session cookies were set. (Check credentials)' );
	}

	// Make sure $set_cookie_headers is an array
	if ( ! is_array( $set_cookie_headers ) ) {
		$set_cookie_headers = array( $set_cookie_headers );
	}

	$session_cookie = '';
	$xsrf_token     = '';

	foreach ( $set_cookie_headers as $cookie_string ) {
		if ( preg_match( '/steadfast_courier_session=([^;]+);/', $cookie_string, $session_match ) ) {
			// We only want the value, not the 'steadfast_courier_session=' part
			$session_cookie = $session_match[1];
		}
		if ( preg_match( '/XSRF-TOKEN=([^;]+);/', $cookie_string, $xsrf_match ) ) {
			$xsrf_token = $xsrf_match[1];
		}
	}

	// Check if we found both required cookies
	if ( empty( $session_cookie ) || empty( $xsrf_token ) ) {
		return new WP_Error( 'steadfast_cookie_parse_failed', 'Login to Steadfast succeeded, but could not parse required session cookies.' );
	}

	// --- Part D: Cache and Return ---
	$session_data = (object) array(
		'session_cookie_value' => $session_cookie,
		'xsrf_token_value'     => $xsrf_token,
	);

	// Cache the new session data for 6 hours
	set_transient( 'steadfast_session_data', $session_data, 6 * HOUR_IN_SECONDS );

	return $session_data;
}


/**
 * =================================================================
 * STEP 2 (NEW): REDEX AUTOMATION BOT (API LOGIN VERSION)
 * =================================================================
 */

/**
 * Automatically logs into RedEx via their API to fetch a session token.
 *
 * @return object|WP_Error An object containing {token} on success,
 * or WP_Error on failure.
 */
function faplm_get_redex_session_data() {

	// 1. Check for Cached Session
	$transient_key  = 'redex_session_data';
	$cached_session = get_transient( $transient_key );
	if ( $cached_session ) {
		return $cached_session;
	}

	// 2. Handle Cache Miss: Run the API Login Bot
	$login_url = 'https://api.redx.com.bd/v4/auth/login'; // The API endpoint

	// Retrieve saved credentials
	$options  = get_option( 'faplm_courier_settings' );
	$phone    = isset( $options['redex_phone'] ) ? $options['redex_phone'] : '';
	$password = isset( $options['redex_password'] ) ? $options['redex_password'] : '';

	if ( empty( $phone ) || empty( $password ) ) {
		return new WP_Error( 'redex_no_creds', 'RedEx phone or password is not set in settings.' );
	}

	// Prepare the JSON payload
	$payload = wp_json_encode(
		array(
			'phone'    => $phone,
			'password' => $password,
		)
	);

	// Prepare the POST request
	$post_args = array(
		'timeout' => 15,
		'headers' => array(
			'Content-Type' => 'application/json',
			'Accept'       => 'application/json',
		),
		'body'    => $payload,
	);

	$post_response = wp_remote_post( $login_url, $post_args );

	if ( is_wp_error( $post_response ) ) {
		return new WP_Error( 'redex_post_failed', 'Failed to POST to RedEx login API.', $post_response->get_error_message() );
	}

	$response_body = wp_remote_retrieve_body( $post_response );
	$response_code = wp_remote_retrieve_response_code( $post_response );
	$data          = json_decode( $response_body );

	// Check for a failed login (e.g., 401, 422) or missing token
	if ( 200 !== $response_code || ! isset( $data->access_token ) ) {
		$error_message = isset( $data->message ) ? $data->message : 'Login failed. Check credentials.';
		return new WP_Error( 'redex_login_failed', 'RedEx API: ' . $error_message, $response_body );
	}

	// --- Part D: Cache and Return ---
	// We only need to cache the access token
	$session_data = (object) array(
		'token' => $data->access_token,
	);

	// Cache the new session data for 6 hours
	set_transient( $transient_key, $session_data, 6 * HOUR_IN_SECONDS );

	return $session_data;
}


/**
 * =================================================================
 * STEP 7: DATA NORMALIZATION
 * =================================================================
 */

/**
 * Normalizes the unique JSON responses from Steadfast, Pathao, and RedEx
 * into a single, consistent array matching the Hoorin 'Summaries' format.
 *
 * @param string|false $steadfast_json The raw JSON string from Steadfast, or false on failure.
 * @param string|false $pathao_json    The raw JSON string from Pathao, or false on failure.
 * @param string|false $redex_json     The raw JSON string from RedEx, or false on failure.
 *
 * @return array The final data formatted in the 'Summaries' structure.
 */
function faplm_normalize_responses( $steadfast_json, $pathao_json, $redex_json ) {

	// 1. Initialize the final "Summaries" array (Hoorin format).
	$summaries = array(
		'Summaries' => array(
			'Steadfast' => array(
				'Total Parcels'     => 0,
				'Delivered Parcels' => 0,
				'Canceled Parcels'  => 0,
			),
			'RedX'      => array(
				'Total Parcels'     => 0,
				'Delivered Parcels' => 0,
				'Canceled Parcels'  => 0,
			),
			'Pathao'    => array(
				'Total Delivery'      => 0,
				'Successful Delivery' => 0,
				'Canceled Delivery'   => 0,
			),
		),
	);

	// 2. Process Steadfast Data
	$steadfast_data = json_decode( $steadfast_json );
	if ( $steadfast_data && is_object( $steadfast_data ) && isset( $steadfast_data->total_delivered ) && isset( $steadfast_data->total_cancelled ) ) {
		$delivered = (int) $steadfast_data->total_delivered;
		$canceled  = (int) $steadfast_data->total_cancelled;
		$total     = $delivered + $canceled;

		$summaries['Summaries']['Steadfast'] = array(
			'Total Parcels'     => $total,
			'Delivered Parcels' => $delivered,
			'Canceled Parcels'  => $canceled,
		);
	}

	// 3. Process RedEx Data
	$redex_data = json_decode( $redex_json );
	if ( $redex_data && is_object( $redex_data ) && isset( $redex_data->data->totalParcels ) && isset( $redex_data->data->deliveredParcels ) ) {
		$total     = (int) $redex_data->data->totalParcels;
		$delivered = (int) $redex_data->data->deliveredParcels;
		// Calculate canceled, as it's not always provided directly
		$canceled = $total - $delivered;

		$summaries['Summaries']['RedX'] = array(
			'Total Parcels'     => $total,
			'Delivered Parcels' => $delivered,
			'Canceled Parcels'  => $canceled,
		);
	}

	// 4. Process Pathao Data
	$pathao_data = json_decode( $pathao_json );
	if ( $pathao_data && is_object( $pathao_data ) && isset( $pathao_data->data->customer->total_delivery ) && isset( $pathao_data->data->customer->successful_delivery ) ) {
		$total     = (int) $pathao_data->data->customer->total_delivery;
		$delivered = (int) $pathao_data->data->customer->successful_delivery;
		$canceled  = $total - $delivered;

		$summaries['Summaries']['Pathao'] = array(
			'Total Delivery'      => $total,
			'Successful Delivery' => $delivered,
			'Canceled Delivery'   => $canceled,
		);
	}

	// 5. Add the debug key back in for testing
	$summaries['_debug_raw_responses'] = array(
		'steadfast_input' => $steadfast_json,
		'pathao_input'    => $pathao_json,
		'redex_input'     => $redex_json,
	);

	// 6. Return the final, normalized array.
	return $summaries;
}

