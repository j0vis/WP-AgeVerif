<?php
/**
 * OAuth2 (Authorization Code) flow for AgeVerif.
 *
 * Responsibilities:
 *  - Build the authorization URL the visitor is redirected to.
 *  - Defend CSRF via a STATELESS short-lived cookie + base64url
 *    JSON state (zero DB writes, no transient race conditions).
 *  - Handle the `?ageverif_oauth=callback` redirect from AgeVerif:
 *      validate state → exchange code for an access_token → set
 *      a HMAC-signed verification cookie → redirect back to the
 *      page the visitor started on.
 *  - Provide a single `is_verified()` helper the frontend can call
 *      to decide whether to render normal content or the OAuth gate.
 *
 * Reference: https://docs.ageverif.com/oauth2.html
 */

namespace AgeVerif;

defined( 'ABSPATH' ) || exit;

class AgeVerif_OAuth {

	const ENDPOINT_BASE           = 'https://api.ageverif.com/v1/oauth2';
	const CSRF_COOKIE             = 'ageverif_oauth_csrf';
	const VERIFIED_COOKIE         = 'ageverif_oauth_verified';
	const REST_NAMESPACE          = 'ageverif/v1';
	const REST_ROUTE              = '/oauth/callback';
	const CALLBACK_QUERY_VAR      = 'ageverif_oauth';
	const CSRF_TTL_SECONDS        = 15 * 60; // one-time, plenty for AgeVerif round-trip
	const DEFAULT_VERIFIED_TTL    = 3600;    // access_token expires_in default (1 hour).

	/** @var array merged plugin options + defaults */
	private $options;

	public function __construct() {
		$this->options = AgeVerif_Helper::get_options();

		// Register the canonical OAuth callback as a public REST route.
		add_action( 'rest_api_init', array( $this, 'register_rest_route' ) );
	}

	/**
	 * Register the public REST OAuth callback route.
	 *
	 * The endpoint is publicly callable (the visitor's browser is sent
	 * here by api.ageverif.com after verifying). Permission is "open"
	 * because legitimate visitors have no logged-in WP identity; CSRF
	 * is covered by the stateless state-cookie nonce in the `state`
	 * parameter.
	 */
	public function register_rest_route() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::REST_ROUTE,
			array(
				'methods'             => 'GET,POST',
				'callback'            => array( $this, 'handle_rest_callback' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'code'  => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'state' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'error' => array(
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * REST callback — runs the canonical OAuth Authorization-Code flow.
	 *
	 * After verifying the visitor and setting the verification cookie,
	 * responds with a 302 redirect to the original page so the visitor's
	 * full-page cache (if any) handles the next render normally. We do
	 * NOT return JSON — that's reserved for programmatic clients; the
	 * browser round-trip needs an HTML redirect to land back on the page.
	 *
	 * Pattern: `nocache_headers()` + `wp_safe_redirect()` + `exit`.
	 */
	public function handle_rest_callback( \WP_REST_Request $request ) {
		nocache_headers();

		$code   = (string) $request->get_param( 'code' );
		$state  = (string) $request->get_param( 'state' );
		$error  = (string) $request->get_param( 'error' );

		// Delegate the actual validation/token-exchange/cookie-set to the
		// shared private method. It returns either a redirect URL (success)
		// or fails via fail_safe_redirect() (which exits). So when control
		// returns from process_oauth_callback(), it's a successful exchange.
		$redirect_to = $this->process_oauth_callback( $code, $state, $error );
		if ( '' === $redirect_to ) {
			// Defensive fallback — process_oauth_callback() should have
			// already redirected-and-exited on failure. If we somehow land
			// here, send the visitor to the homepage.
			$redirect_to = home_url( '/' );
		}

		wp_safe_redirect( $redirect_to, 302 );
		exit;
	}

	/* ============================================================
	 * Configuration gates — used by both the callback handler and
	 * the frontend gate renderer so they agree on "is OAuth active".
	 * ============================================================ */

	public function is_active() {
		return AgeVerif_Helper::oauth_is_active( $this->options );
	}

	public function is_test_mode() {
		return ! empty( $this->options['test_mode'] );
	}

	/**
	 * Whether the visitor is currently verified via the OAuth cookie.
	 *
	 * - Test Mode: keep verification admin-only (mirror checker behavior).
	 *   Means: outside Test Mode, valid cookie = bypass; in Test Mode,
	 *   only admins bypass — non-admins always re-verify.
	 * - The cookie payload is `uid|expires|HMAC(key)` so we can verify
	 *   integrity without a DB lookup.
	 */
	public function is_verified() {
		if ( ! $this->is_active() ) {
			return false;
		}
		if ( ! isset( $_COOKIE[ self::VERIFIED_COOKIE ] ) ) {
			return false;
		}
		$raw = sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::VERIFIED_COOKIE ] ) );
		$payload = $this->decode_verified_cookie( $raw );
		if ( null === $payload ) {
			return false;
		}
		if ( $payload['exp'] < time() ) {
			return false;
		}
		if ( $this->is_test_mode() && ! current_user_can( 'manage_options' ) ) {
			return false;
		}
		return true;
	}

	/* ============================================================
	 * Authorize URL + CSRF setup
	 * ============================================================ */

	/**
	 * Build the URL the visitor is redirected to in order to start
	 * the AgeVerif verification flow.
	 *
	 * Side effect: sets a short-lived CSRF cookie containing the
	 * nonce portion of the state so we can verify the callback
	 * without writing anything to the database.
	 *
	 * @param string $return_url  Where to send the visitor after success.
	 * @return string             Full authorize URL.
	 */
	public function build_authorize_url( $return_url ) {
		// 32 random bytes → base64url → short CSRF nonce.
		$nonce = $this->random_token( 32 );
		$state = $this->encode_state(
			array(
				'csrf'      => $nonce,
				'return'    => esc_url_raw( $return_url ),
				'ts'        => time(),
			)
		);

		// Set the short-lived CSRF cookie BEFORE redirect. SameSite=Lax
		// is required: Strict breaks when the browser returns from
		// api.ageverif.com → {site}/?ageverif_oauth=callback.
		if ( ! headers_sent() ) {
			$secure = is_ssl();
			setcookie(
				self::CSRF_COOKIE,
				$nonce,
				array(
					'expires'  => time() + self::CSRF_TTL_SECONDS,
					'path'     => '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => $secure,
					'httponly'  => true,
					'samesite' => 'Lax',
				)
			);
		}

		$flow = ( 'login' === (string) $this->options['oauth_flow'] ) ? 'login' : 'checker';
		$endpoint = self::ENDPOINT_BASE . '/' . $flow;

		$params = array(
			'response_type' => 'code',
			'client_id'     => (string) $this->options['oauth_client_id'],
			'redirect_uri'  => self::callback_url(),
			'scope'         => 'read',
			'state'         => $state,
		);

		$lang = isset( $this->options['oauth_language'] ) ? (string) $this->options['oauth_language'] : 'auto';
		if ( '' !== $lang && 'auto' !== $lang ) {
			$params['language'] = sanitize_text_field( $lang );
		}

		$challenges = isset( $this->options['oauth_challenges'] ) && is_array( $this->options['oauth_challenges'] )
			? array_values( array_filter( array_map( 'sanitize_key', $this->options['oauth_challenges'] ) ) )
			: array();
		if ( $challenges ) {
			$params['challenges'] = implode( ',', $challenges );
		}

		return add_query_arg( $params, $endpoint );
	}

	/**
	 * Public callback URL the Webmasters Platform must allow-list.
	 *
	 * This is the REST endpoint at `/wp-json/ageverif/v1/oauth/callback`.
	 * The REST form is cleaner for caching layers: full-page cache plugins
	 * (Nginx Helper, Cloudflare APO, WP Rocket's separate-mobile cache) all
	 * exempt REST endpoints by default.
	 */
	public static function callback_url() {
		return rest_url( self::REST_NAMESPACE . self::REST_ROUTE );
	}

	/* ============================================================
	 * Callback handler
	 * ============================================================ */

	/**
	 * Shared OAuth callback pipeline.
	 *
	 * On failure, redirects to the homepage via fail_safe_redirect()
	 * (which exits). On success, returns the URL the visitor should be
	 * redirected to.
	 *
	 * @param string $code   Authorization code from AgeVerif.
	 * @param string $state  Base64url-encoded state token.
	 * @param string $error  Optional error code from AgeVerif.
	 * @return string        Redirect URL on success (empty on failure path
	 *                       — but that path calls exit before returning).
	 */
	private function process_oauth_callback( $code, $state, $error ) {
		// Block the callback if OAuth isn't even configured — better
		// to fail loudly than silently let AgeVerif write a cookie.
		if ( ! $this->is_active() ) {
			$this->fail_safe_redirect(
				__( 'AgeVerif OAuth is not configured on this site.', 'ageverif-wordpress' )
			);
		}

		if ( '' !== $error ) {
			$this->fail_safe_redirect(
				sprintf(
					/* translators: %s: error code returned by AgeVerif */
					__( 'AgeVerif authorization was denied: %s', 'ageverif-wordpress' ),
					$error
				)
			);
		}

		if ( '' === $code || '' === $state ) {
			$this->fail_safe_redirect(
				__( 'AgeVerif callback was missing required parameters.', 'ageverif-wordpress' )
			);
		}

		$state_payload = $this->decode_state( $state );
		if ( null === $state_payload ) {
			$this->fail_safe_redirect(
				__( 'AgeVerif callback state was invalid or tampered with.', 'ageverif-wordpress' )
			);
		}

		// CSRF: the nonce stored in the short-lived cookie MUST match
		// the nonce embedded in state. Constant-time compare.
		$cookie_nonce = isset( $_COOKIE[ self::CSRF_COOKIE ] )
			? sanitize_text_field( wp_unslash( (string) $_COOKIE[ self::CSRF_COOKIE ] ) )
			: '';
		if ( '' === $cookie_nonce
			|| ! isset( $state_payload['csrf'] )
			|| ! hash_equals( $cookie_nonce, (string) $state_payload['csrf'] ) ) {
			$this->clear_csrf_cookie();
			$this->fail_safe_redirect(
				__( 'AgeVerif callback failed CSRF verification.', 'ageverif-wordpress' )
			);
		}

		// State has its own freshness check (defense in depth — cookie
		// alone could be replayed if the cookie TTL is ever bumped).
		$age = isset( $state_payload['ts'] ) ? (int) $state_payload['ts'] : 0;
		if ( $age <= 0 || ( time() - $age ) > self::CSRF_TTL_SECONDS + 60 ) {
			$this->clear_csrf_cookie();
			$this->fail_safe_redirect(
				__( 'AgeVerif callback expired. Please try again.', 'ageverif-wordpress' )
			);
		}

		// Clear the one-shot CSRF cookie.
		$this->clear_csrf_cookie();

		$return_url = isset( $state_payload['return'] )
			? esc_url_raw( (string) $state_payload['return'] )
			: home_url( '/' );
		// Only redirect back to the same host — otherwise an attacker who
		// somehow gets a state packet could pin the visitor to an external URL.
		$return_url = $this->sanitize_return_to_same_site( $return_url );
		if ( '' === $return_url ) {
			$return_url = home_url( '/' );
		}

		// Exchange code → access_token (server-to-server).
		$token_response = $this->exchange_code_for_token( $code );
		if ( is_wp_error( $token_response ) ) {
			$this->fail_safe_redirect(
				sprintf(
					/* translators: %s: error message */
					__( 'AgeVerif token exchange failed: %s', 'ageverif-wordpress' ),
					$token_response->get_error_message()
				)
			);
		}

		$access_token = isset( $token_response['access_token'] )
			? (string) $token_response['access_token']
			: '';
		$expires_in   = isset( $token_response['expires_in'] )
			? max( 60, (int) $token_response['expires_in'] ) // clamp ≥ 60s so a bogus short TTL doesn't expire the cookie mid-page
			: self::DEFAULT_VERIFIED_TTL;

		if ( '' === $access_token ) {
			$this->fail_safe_redirect(
				__( 'AgeVerif did not return an access token.', 'ageverif-wordpress' )
			);
		}

		// (Optional) Resources endpoint — gives back a stable UID + age
		// threshold. We DON'T fail the verification if resources fails,
		// because the docs say "If you get a valid response from the
		// previous step, the visitor is guaranteed to be verified."
		$uid = '';
		$resources = $this->fetch_resources( $access_token );
		if ( is_array( $resources ) && ! empty( $resources['uid'] ) ) {
			$uid = (string) $resources['uid'];
			// Resources endpoint returns the verification's expires_in,
			// which can be longer than the access_token itself.
			if ( ! empty( $resources['expires_in'] ) ) {
				$expires_in = max( $expires_in, (int) $resources['expires_in'] );
			}
		}

		$this->set_verified_cookie( $uid, $expires_in );
		// Hard cap at 30 days — even if resources endpoint returns
		// weeks of expiry, we re-prompt at least monthly.
		$expires_in = min( $expires_in, 30 * DAY_IN_SECONDS );

		return $return_url;
	}



	/**
	 * Exchange the authorization code for an access_token.
	 *
	 * Documents show EITHER Basic auth OR body params; we use Basic
	 * auth because the docs' primary example uses it and it avoids
	 * leaking the client_secret into URLs.
	 *
	 * @param string $code
	 * @return array|\WP_Error  Decoded JSON body on success.
	 */
	private function exchange_code_for_token( $code ) {
		$client_id     = (string) $this->options['oauth_client_id'];
		$client_secret = (string) $this->options['oauth_client_secret'];

		if ( '' === $client_id || '' === $client_secret ) {
			return new \WP_Error(
				'ageverif_oauth_no_secret',
				__( 'Client ID or Client Secret is missing in Settings → AgeVerif.', 'ageverif-wordpress' )
			);
		}

		$basic = 'Basic ' . base64_encode( $client_id . ':' . $client_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- OAuth Basic auth.

		$response = wp_remote_post(
			self::ENDPOINT_BASE . '/token',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => $basic,
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Accept'        => 'application/json',
				),
				'body'    => array(
					'grant_type'   => 'authorization_code',
					'code'         => $code,
					'redirect_uri' => self::callback_url(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code_http = (int) wp_remote_retrieve_response_code( $response );
		$body      = (string) wp_remote_retrieve_body( $response );
		$decoded   = json_decode( $body, true );

		if ( 200 !== $code_http || ! is_array( $decoded ) ) {
			$message = is_array( $decoded ) && isset( $decoded['error_description'] )
				? (string) $decoded['error_description']
				: ( is_array( $decoded ) && isset( $decoded['error'] ) ? (string) $decoded['error'] : sprintf( 'HTTP %d', $code_http ) );
			return new \WP_Error( 'ageverif_oauth_token', $message );
		}

		return $decoded;
	}

	/**
	 * (Optional) fetch visitor resources to extract verification UID
	 * + possibly longer expires_in. Returns array or null on failure.
	 *
	 * @param string $access_token
	 * @return array|null
	 */
	private function fetch_resources( $access_token ) {
		$response = wp_remote_get(
			self::ENDPOINT_BASE . '/resources',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return null;
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['resources'] ) || ! is_array( $decoded['resources'] ) ) {
			return null;
		}
		$resources = $decoded['resources'];
		if ( empty( $resources['verified'] ) ) {
			return null;
		}
		return $resources;
	}

	/* ============================================================
	 * Verified cookie
	 * ============================================================ */

	/**
	 * Issue the visitor-facing verification cookie.
	 * Cookie payload:  uid|exp|hex_hmac  (HMAC keyed by wp_salt('auth')).
	 *
	 * Not HttpOnly? On purpose — sites may want JS to inspect
	 * `ageverifOauthVerified` to tweak UX. SameSite=Lax so the cookie
	 * never leaks on cross-site redirects. Secure when over HTTPS.
	 *
	 * @param string $uid        Verification UID (may be empty if resources endpoint wasn't called).
	 * @param int    $expires_in Lifetime in seconds.
	 */
	private function set_verified_cookie( $uid, $expires_in ) {
		$exp    = time() + (int) $expires_in;
		$key    = wp_salt( 'auth' );
		$mac    = hash_hmac( 'sha256', $uid . '|' . $exp, $key );
		$value  = $uid . '|' . $exp . '|' . $mac;

		setcookie(
			self::VERIFIED_COOKIE,
			$value,
			array(
				'expires'  => $exp,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				// HttpOnly: keeps the HMAC-bearing payload out of JS. The
				// parallel `_js` cookie below covers any conditional UI.
				'httponly'  => true,
				'samesite' => 'Lax',
			)
		);
		// Parallel JS-readable cookie (`ageverif_oauth_verified_js`) so pages
		// that want conditional markup (`ageverifOauthVerified === '1'`) can
		// read it without a server round-trip. Holds no sensitive data.
		setcookie(
			self::VERIFIED_COOKIE . '_js',
			'1',
			array(
				'expires'  => $exp,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly'  => false,
				'samesite' => 'Lax',
			)
		);
	}

	private function decode_verified_cookie( $raw ) {
		$parts = explode( '|', $raw );
		if ( count( $parts ) !== 3 ) {
			return null;
		}
		list( $uid, $exp, $mac ) = $parts;
		$exp = (int) $exp;
		if ( $exp <= 0 ) {
			return null;
		}
		$expected = hash_hmac( 'sha256', $uid . '|' . $exp, wp_salt( 'auth' ) );
		if ( ! hash_equals( $expected, (string) $mac ) ) {
			return null;
		}
		return array(
			'uid' => (string) $uid,
			'exp' => $exp,
		);
	}

	private function clear_csrf_cookie() {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::CSRF_COOKIE,
			'',
			array(
				'expires'  => time() - 3600,
				'path'     => '/',
				'domain'   => COOKIE_DOMAIN,
				'secure'   => is_ssl(),
				'httponly'  => true,
				'samesite' => 'Lax',
			)
		);
	}

	/* ============================================================
	 * State encoding helpers
	 * ============================================================ */

	private function encode_state( array $payload ) {
		$json = wp_json_encode( $payload );
		return $this->base64url_encode( $json );
	}

	private function decode_state( $state ) {
		$json = $this->base64url_decode( (string) $state );
		if ( null === $json ) {
			return null;
		}
		$payload = json_decode( $json, true );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		return $payload;
	}

	private function base64url_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- state token encoding per OAuth docs.
	}

	private function base64url_decode( $data ) {
		$remainder = strlen( $data ) % 4;
		if ( 0 !== $remainder ) {
			$data .= str_repeat( '=', 4 - $remainder );
		}
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- state token decoding per OAuth docs.
		return ( false === $decoded ) ? null : $decoded;
	}

	private function random_token( $bytes = 32 ) {
		try {
			$rand = random_bytes( $bytes );
		} catch ( \Exception $e ) {
			// Fallback: wp_generate_password is suitable for non-secret state.
			$rand = wp_generate_password( $bytes, false, false );
			// wp_generate_password returns ASCII, not binary; base64url-encode it.
			return $this->base64url_encode( $rand );
		}
		return $this->base64url_encode( $rand );
	}

	/* ============================================================
	 * Misc helpers
	 * ============================================================ */

	private function sanitize_return_to_same_site( $url ) {
		if ( '' === $url ) {
			return '';
		}
		$home = home_url( '/' );
		$host = wp_parse_url( $home, PHP_URL_HOST );
		$uh   = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! $uh || ! $host ) {
			return '';
		}
		if ( strcasecmp( $uh, $host ) !== 0 ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	private function fail_safe_redirect( $admin_error_message ) {
		// Stash a short admin-only notice if a privileged user is
		// bouncing around with these failures during setup.
		if ( current_user_can( 'manage_options' ) ) {
			set_transient(
				'ageverif_admin_notice',
				array(
					'type'    => 'error',
					'message' => $admin_error_message,
				),
				MINUTE_IN_SECONDS
			);
		}
		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/* ============================================================
	 * UI helpers — button label/color defaults shared with the
	 * admin renderer and the auto-gate/block.
	 * ============================================================ */

	public function button_label() {
		$custom = isset( $this->options['oauth_button_label'] )
			? trim( (string) $this->options['oauth_button_label'] )
			: '';
		return '' === $custom ? AgeVerif_Helper::default_button_label_from_options( $this->options ) : $custom;
	}

	public function button_color() {
		$allowed = array( 'blue', 'white', 'black' );
		$color   = isset( $this->options['oauth_button_color'] )
			? sanitize_key( $this->options['oauth_button_color'] )
			: 'blue';
		return in_array( $color, $allowed, true ) ? $color : 'blue';
	}
}
