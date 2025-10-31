<?php
/**
 * Handles all REST API endpoints for the FA License Manager.
 * - /my-license/v1/activate
 * - /my-license/v1/deactivate
 * - /courier-check/v1/status
 *
 * @package FA_Licence_Manager
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// --- FIX for 500 ERROR ---
// Ensure helper functions (bots, normalizer) are loaded.
// We use a self-sufficient path relative to this file.
require_once plugin_dir_path( __FILE__ ) . 'faplm-direct-courier-helpers.php';


/**
 * Register all REST API endpoints for the FA License Manager.
 * Hooks into: rest_api_init
 */
function faplm_register_api_endpoints() {

	// --- /activate route ---
	register_rest_route(
		'my-license/v1', // Namespace
		'/activate',     // Route
		array(
			'methods'             => 'POST', // Must be a POST request
			'callback'            => 'faplm_handle_activation_request',
			'permission_callback' => '__return_true', // Public endpoint, security is handled by the key
		)
	);

	// --- /deactivate route ---
	register_rest_route(
		'my-license/v1', // Namespace
		'/deactivate',   // Route
		array(
			'methods'             => 'POST',
			'callback'            => 'faplm_handle_deactivation_request',
			'permission_callback' => '__return_true', // Public endpoint
		)
	);

	// --- /courier-check route ---
	register_rest_route(
		'courier-check/v1', // New Namespace
		'/status',          // Route
		array(
			'methods'             => 'POST',
			'callback'            => 'faplm_handle_courier_check_request',
			'permission_callback' => 'faplm_courier_api_permission_check', // Secure permission check
		)
	);
}
add_action( 'rest_api_init', 'faplm_register_api_endpoints' );


// --- FUNCTIONS FOR /my-license ---

/**
 * Main callback function to handle license ACTIVATION requests.
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_activation_request( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get parameters from the request body (e.g., JSON)
	$license_key = sanitize_text_field( $request->get_param( 'license_key' ) );
	$domain      = sanitize_text_field( $request->get_param( 'domain' ) );

	// 2. Parameter Validation
	if ( empty( $license_key ) || empty( $domain ) ) {
		return new WP_Error(
			'missing_parameters',
			__( 'Missing required parameters: license_key and domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 3. Core License Validation Logic: Fetch the license
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	// 4. Check 1 (Exists)
	if ( ! $license ) {
		return new WP_Error(
			'invalid_key',
			__( 'The provided license key is invalid.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 5. Check 2 (Status)
	if ( 'active' !== $license->status ) {
		$error_code = 'key_not_active';
		$error_msg  = __( 'This license key is not active.', 'fa-pro-license-manager' );

		if ( 'expired' === $license->status ) {
			$error_code = 'key_expired';
			$error_msg  = __( 'This license key has expired.', 'fa-pro-license-manager' );
		}

		return new WP_Error(
			$error_code,
			$error_msg,
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 6. Check 3 (Expiration)
	if ( null !== $license->expires_at ) {
		$current_time = current_time( 'mysql' ); // Use WP local time

		if ( $current_time > $license->expires_at ) {

			// License has expired, update status in DB
			$wpdb->update(
				$table_name,
				array( 'status' => 'expired' ),
				array( 'id' => $license->id )
			);

			return new WP_Error(
				'key_expired',
				__( 'This license key has expired.', 'fa-pro-license-manager' ),
				array( 'status' => 403 ) // 403 Forbidden
			);
		}
	}

	// 7. Check 4 (Already Activated?)
	$activated_domains = json_decode( $license->activated_domains, true );

	if ( ! is_array( $activated_domains ) ) {
		$activated_domains = array();
	}

	if ( in_array( $domain, $activated_domains, true ) ) {
		return new WP_REST_Response(
			array(
				'success'    => true,
				'message'    => __( 'License is already activated on this domain.', 'fa-pro-license-manager' ),
				'expires_at' => ( null === $license->expires_at ) ? 'Lifetime' : $license->expires_at,
			),
			200 // 200 OK
		);
	}

	// 8. Check 5 (Limit Reached)
	$current_activations = absint( $license->current_activations );
	$activation_limit    = absint( $license->activation_limit );

	if ( $current_activations >= $activation_limit ) {
		return new WP_Error(
			'limit_reached',
			__( 'This license key has reached its activation limit.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 9. Successful Activation Process
	$activated_domains[]     = $domain;
	$new_activations_count = $current_activations + 1;

	$data_to_update = array(
		'current_activations' => $new_activations_count,
		'activated_domains'   => wp_json_encode( $activated_domains ),
	);
	$where = array( 'id' => $license->id );

	$updated = $wpdb->update( $table_name, $data_to_update, $where );

	if ( false === $updated ) {
		return new WP_Error(
			'db_error',
			__( 'Could not save activation data to the database.', 'fa-pro-license-manager' ),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	return new WP_REST_Response(
		array(
			'success'    => true,
			'message'    => __( 'License activated successfully.', 'fa-pro-license-manager' ),
			'expires_at' => ( null === $license->expires_at ) ? 'Lifetime' : $license->expires_at,
		),
		200 // 200 OK
	);
}

/**
 * Main callback function to handle license DEACTIVATION requests.
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_deactivation_request( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get parameters
	$license_key = sanitize_text_field( $request->get_param( 'license_key' ) );
	$domain      = sanitize_text_field( $request->get_param( 'domain' ) );

	// 2. Parameter Validation
	if ( empty( $license_key ) || empty( $domain ) ) {
		return new WP_Error(
			'missing_parameters',
			__( 'Missing required parameters: license_key and domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 3. Core Logic: Fetch the license
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	// 4. Check 1 (Exists)
	if ( ! $license ) {
		return new WP_Error(
			'invalid_key',
			__( 'The provided license key is invalid.', 'fa-pro-license-manager' ),
			array( 'status' => 403 ) // 403 Forbidden
		);
	}

	// 5. Check 2 (Is it activated on this domain?)
	$activated_domains = json_decode( $license->activated_domains, true );

	if ( ! is_array( $activated_domains ) ) {
		$activated_domains = array();
	}

	// Search for the domain in the array
	$key = array_search( $domain, $activated_domains, true );

	if ( false === $key ) {
		// Domain was NOT found in the array
		return new WP_Error(
			'not_activated_here',
			__( 'This license is not activated on the specified domain.', 'fa-pro-license-manager' ),
			array( 'status' => 400 ) // 400 Bad Request
		);
	}

	// 6. Successful Deactivation Process

	// Domain was found. Remove it using its key.
	unset( $activated_domains[ $key ] );

	// Re-index the array to ensure it saves as a JSON array, not an object.
	$updated_domains_array = array_values( $activated_domains );

	// Decrement the activation count, ensuring it doesn't go below zero.
	$new_activations_count = max( 0, absint( $license->current_activations ) - 1 );

	// Prepare data for the database update
	$data_to_update = array(
		'current_activations' => $new_activations_count,
		'activated_domains'   => wp_json_encode( $updated_domains_array ), // Save the re-indexed array
	);
	$where = array( 'id' => $license->id );

	$updated = $wpdb->update( $table_name, $data_to_update, $where );

	if ( false === $updated ) {
		// Handle potential database error
		return new WP_Error(
			'db_error',
			__( 'Could not save deactivation data to the database.', 'fa-pro-license-manager' ),
			array( 'status' => 500 ) // 500 Internal Server Error
		);
	}

	// 7. Return the final success response
	return new WP_REST_Response(
		array(
			'success' => true,
			'message' => __( 'License deactivated successfully.', 'fa-pro-license-manager' ),
		),
		200 // 200 OK
	);
}


// --- FUNCTIONS FOR /courier-check ---

/**
 * Security check for the Courier API endpoint.
 * This function is hooked as the 'permission_callback'
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return bool|WP_Error True if permission is granted, WP_Error otherwise.
 */
function faplm_courier_api_permission_check( WP_REST_Request $request ) {
	global $wpdb;
	$table_name = $wpdb->prefix . FAPLM_LICENSES_TABLE;

	// 1. Get the Authorization header
	$auth_header = $request->get_header( 'authorization' );

	if ( empty( $auth_header ) ) {
		return new WP_Error(
			'401_unauthorized',
			'Authorization header is missing.',
			array( 'status' => 401 )
		);
	}

	// 2. Parse the "Bearer <LICENSE_KEY>" format
	$license_key = '';
	if ( sscanf( $auth_header, 'Bearer %s', $license_key ) !== 1 ) {
		return new WP_Error(
			'401_unauthorized',
			'Authorization header is malformed. Expected "Bearer <KEY>".',
			array( 'status' => 401 )
		);
	}

	if ( empty( $license_key ) ) {
		return new WP_Error(
			'401_unauthorized',
			'No license key provided in Authorization header.',
			array( 'status' => 401 )
		);
	}

	// 3. Query the database for this license key
	$license = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM $table_name WHERE license_key = %s",
			$license_key
		)
	);

	$error_msg = __( 'Invalid or unauthorized license.', 'fa-pro-license-manager' );

	// 4. Perform Security Checks

	// Check 1: Key exists and status is 'active'
	if ( ! $license || 'active' !== $license->status ) {
		return new WP_Error( '403_forbidden', $error_msg, array( 'status' => 403 ) );
	}

	// Check 2: Expiration date (if it's not NULL)
	if ( null !== $license->expires_at ) {
		$current_time = current_time( 'mysql' ); // Use WP local time for consistency

		if ( $current_time > $license->expires_at ) {
			// As a courtesy, update the status to 'expired' in the DB
			$wpdb->update( $table_name, array( 'status' => 'expired' ), array( 'id' => $license->id ) );
			return new WP_Error( '403_forbidden', 'This license has expired.', array( 'status' => 403 ) );
		}
	}

	// Check 3: 'allow_courier_api' column must be 1
	if ( 1 !== (int) $license->allow_courier_api ) {
		return new WP_Error( '403_forbidden', 'This license does not have permission to access the courier API.', array( 'status' => 403 ) );
	}

	// 5. All checks passed!
	// Store the license key in a global var for logging (optional, but good practice)
	$GLOBALS['faplm_current_license_key'] = $license_key;
	return true;
}

/**
 * =================================================================
 * STEP 5: MAIN LOGIC - THE BRAIN (SOURCE SELECTION & DISPATCH)
 * =================================================================
 *
 * Main callback for the courier-check/v1/status endpoint.
 * This function acts as the "command center". It handles:
 * 1. Caching (Check, Hit, Miss, Set)
 * 2. Dispatching (Deciding which data source function to call)
 *
 * @param WP_REST_Request $request The incoming request object.
 * @return WP_REST_Response|WP_Error
 */
function faplm_handle_courier_check_request( WP_REST_Request $request ) {

	// 1. Get searchTerm from the JSON body
	$params      = $request->get_json_params();
	$search_term = isset( $params['searchTerm'] ) ? sanitize_text_field( $params['searchTerm'] ) : '';

	if ( empty( $search_term ) ) {
		return new WP_Error(
			'400_bad_request',
			'searchTerm is required in the JSON body.',
			array( 'status' => 400 )
		);
	}

	// 2. Implement Caching (Cache-Check)
	$options              = get_option( 'faplm_courier_settings' );
	$cache_duration_hours = isset( $options['cache_duration'] ) ? absint( $options['cache_duration'] ) : 6;
	$cache_in_seconds     = $cache_duration_hours * HOUR_IN_SECONDS;
	$transient_key        = 'faplm_courier_' . md5( $search_term );

	if ( $cache_in_seconds > 0 ) {
		$cached_data = get_transient( $transient_key );
		// 3. (Cache Hit) If data is found, return it immediately.
		if ( false !== $cached_data ) {
			// Data is stored as a PHP array, so we return it directly.
			return new WP_REST_Response( $cached_data, 200 );
		}
	}

	// 4. (Cache Miss) No data found in cache. Proceed to API call.

	// a. Get the selected data source ('hoorin' or 'direct')
	$data_source = isset( $options['data_source'] ) ? $options['data_source'] : 'hoorin';

	// b. Dispatch to the correct function
	if ( 'hoorin' === $data_source ) {
		$final_data = faplm_fetch_hoorin_data_round_robin( $search_term, $options );
	} else {
		// This function calls the bots and the normalizer
		$final_data = faplm_fetch_direct_data_concurrently( $search_term );
	}

	// c. Handle errors from the fetch functions
	if ( is_wp_error( $final_data ) ) {
		return $final_data; // Pass the WP_Error through
	}

	// 5. Finalize and Return
	// a. (Cache Set) Save the final data (which is a PHP array) to the cache.
	if ( $cache_in_seconds > 0 ) {
		set_transient( $transient_key, $final_data, $cache_in_seconds );
	}

	// b. Return the data to the user.
	return new WP_REST_Response( $final_data, 200 );
}

/**
 * =================================================================
 * DATA FETCHING: HOORIN
 * =================================================================
 *
 * Handles the round-robin logic for the Hoorin API.
 *
 * @param string $search_term The phone number.
 * @param array $options The plugin settings array.
 * @return array|WP_Error The JSON-decoded data array or a WP_Error.
 */
function faplm_fetch_hoorin_data_round_robin( $search_term, $options ) {

	// a. Fetch API Keys from settings
	$api_keys_string = isset( $options['hoorin_api_keys'] ) ? $options['hoorin_api_keys'] : '';

	// b. Prepare Key Array
	$api_keys = preg_split( '/\r\n|\r|\n/', $api_keys_string ); // Split by any newline
	$api_keys = array_map( 'trim', $api_keys );               // Trim whitespace from each key
	$api_keys = array_filter( $api_keys );                    // Remove any empty lines

	if ( empty( $api_keys ) ) {
		return new WP_Error(
			'no_api_keys',
			'No Hoorin API keys are configured.',
			array( 'status' => 500 )
		);
	}

	// c. Implement Round-Robin (Get Index)
	$current_index = absint( get_option( 'faplm_hoorin_key_index', 0 ) );

	// d. Select Key
	if ( $current_index >= count( $api_keys ) ) {
		$current_index = 0;
	}
	$key_to_use = $api_keys[ $current_index ];

	// e. Implement Round-Robin (Update Index for next request)
	$next_index = ( $current_index + 1 ) % count( $api_keys );
	update_option( 'faplm_hoorin_key_index', $next_index );

	// 5. Call the External Hoorin API
	$api_url  = 'https://dash.hoorin.com/api/courier/news.php';
	$full_url = add_query_arg(
		array(
			'apiKey'     => $key_to_use,
			'searchTerm' => $search_term,
		),
		$api_url
	);

	$response = wp_remote_get( $full_url, array( 'timeout' => 15 ) );

	// 6. Error Handling
	if ( is_wp_error( $response ) ) {
		return new WP_Error(
			'api_call_failed',
			'The external API call failed. ' . $response->get_error_message(),
			array( 'status' => 500 )
		);
	}

	// 7. Response Handling
	$response_body = wp_remote_retrieve_body( $response );
	$response_code = wp_remote_retrieve_response_code( $response );

	if ( 200 === $response_code ) {
		$data = json_decode( $response_body, true ); // true for associative array

		if ( null === $data ) {
			return new WP_Error(
				'invalid_json',
				'The external API returned invalid JSON.',
				array(
					'status' => 502,
					'body'   => $response_body,
				)
			);
		}
		// Return the associative array
		return $data;

	} else {
		// (Failed Call - e.g., 401, 403, 500 from Hoorin API)
		return new WP_Error(
			'external_api_error',
			'The external API returned an error.',
			array(
				'status'        => 502, // 502 Bad Gateway
				'upstream_code' => $response_code,
				'upstream_body' => json_decode( $response_body, true ),
			)
		);
	}
}


/**
 * =================================================================
 * STEP 6 (FINAL): CONCURRENT (SIMULTANEOUS) CALL FUNCTION
 * =================================================================
 *
 * This function is the "Direct Courier" mode's core.
 * It calls the bots for Steadfast and RedEx, then constructs and
 * executes all three API calls (Pathao, RedEx, Steadfast) simultaneously.
 *
 * @param string $search_term The phone number to search for.
 * @return array|WP_Error The normalized data array from faplm_normalize_responses() or WP_Error.
 */
function faplm_fetch_direct_data_concurrently( $search_term ) {

	// 1. Get All Credentials & Bot Sessions
	$options = get_option( 'faplm_courier_settings' );

	// --- Pathao (Token) ---
	$pathao_token = isset( $options['pathao_bearer_token'] ) ? $options['pathao_bearer_token'] : '';
	if ( ! empty( $pathao_token ) && ! str_starts_with( $pathao_token, 'Bearer ' ) ) {
		$pathao_token = 'Bearer ' . $pathao_token;
	}

	// --- Steadfast (Call Bot) ---
	$steadfast_session = faplm_get_steadfast_session_data();
	if ( is_wp_error( $steadfast_session ) ) {
		// Don't block all calls; just log and continue. The normalizer will handle the error.
		$steadfast_cookie_val = '';
		$steadfast_xsrf_val   = '';
		// You could add logging here: error_log( 'Steadfast Bot Error: ' . $steadfast_session->get_error_message() );
	} else {
		$steadfast_cookie_val = $steadfast_session->session_cookie_value;
		$steadfast_xsrf_val   = $steadfast_session->xsrf_token_value;
	}

	// --- RedEx (Call Bot) ---
	$redex_session = faplm_get_redex_session_data();
	if ( is_wp_error( $redex_session ) ) {
		$redex_token = '';
		// You could add logging here: error_log( 'RedEx Bot Error: ' . $redex_session->get_error_message() );
	} else {
		$redex_token = 'Bearer ' . $redex_session->token;
	}

	// 2. Build Concurrent Request Objects
	$requests = array(
		// --- Request 1: Pathao ---
		'pathao'    => array(
			'url'     => 'https://merchant.pathao.com/api/v1/user/success',
			'type'    => 'POST',
			'headers' => array(
				'Authorization'  => $pathao_token,
				'Content-Type'   => 'application/json',
				'Accept'         => 'application/json',
				'Origin'         => 'https://merchant.pathao.com', // Pathao requires Origin
			),
			'data'    => wp_json_encode( array( 'phone' => $search_term ) ),
		),
		// --- Request 2: RedEx ---
		'redex'     => array(
			'url'     => 'https://redx.com.bd/api/redx_se/admin/parcel/customer-success-return-rate?phoneNumber=88' . $search_term,
			'type'    => 'GET',
			'headers' => array(
				'Authorization' => $redex_token, // Use the fresh token from the bot
				'Accept'        => 'application/json',
			),
		),
		// --- Request 3: Steadfast ---
		'steadfast' => array(
			'url'     => 'https://steadfast.com.bd/user/consignment/getbyphone/' . $search_term,
			'type'    => 'GET',
			'headers' => array(
				'Cookie'       => "steadfast_courier_session={$steadfast_cookie_val}; XSRF-TOKEN={$steadfast_xsrf_val}",
				'X-XSRF-TOKEN' => $steadfast_xsrf_val,
			),
		),
	);

	// 3. Execute All Requests Concurrently
	// This uses the built-in WordPress Requests library (which wraps cURL multi_exec)
	$responses = Requests::request_multiple( $requests );

	// 4. Process Responses
	// We check each response individually. If one fails, we pass `false` to the
	// normalizer, which knows how to handle it and will just return 0 for that courier.
	$pathao_body    = ( isset( $responses['pathao'] ) && $responses['pathao']->success ) ? $responses['pathao']->body : false;
	$redex_body     = ( isset( $responses['redex'] ) && $responses['redex']->success ) ? $responses['redex']->body : false;
	$steadfast_body = ( isset( $responses['steadfast'] ) && $responses['steadfast']->success ) ? $responses['steadfast']->body : false;

	// 5. Normalize and Return
	// This calls the function from 'faplm-direct-courier-helpers.php'
	return faplm_normalize_responses( $steadfast_body, $pathao_body, $redex_body );
}

