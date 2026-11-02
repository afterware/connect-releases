<?php
/**
 * HTTP client for the Afterware site API.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/**
 * Every remote call to Afterware goes through this class: it builds the
 * request, attaches the bearer site key when one is configured, and maps
 * transport failures and RFC 9457 problem+json error responses to WP_Error.
 */
final class Api_Client {

	/**
	 * Base URL, e.g. AFTERWARE_API_BASE. May already contain a query string.
	 *
	 * @var string
	 */
	private $base;

	/**
	 * The site key to send as a bearer token, or null when not connected yet.
	 *
	 * @var string|null
	 */
	private $site_key;

	/**
	 * @param string      $base     Base URL requests are built against.
	 * @param string|null $site_key Site key to send as `Authorization: Bearer <key>`, or null.
	 */
	public function __construct( $base = AFTERWARE_API_BASE, $site_key = null ) {
		$this->base     = (string) $base;
		$this->site_key = null === $site_key ? null : (string) $site_key;
	}

	/**
	 * POST /sites/register.
	 *
	 * `$this->base` already embeds the version root (AFTERWARE_API_BASE in
	 * production is `https://updates.afterware.io/v1`), so paths here are
	 * relative to that root and must not repeat `/v1`.
	 *
	 * @param array<string,mixed> $descriptor The RegisterRequestSchema descriptor.
	 * @return array<string,mixed>|WP_Error
	 */
	public function register( array $descriptor ) {
		return $this->send( 'POST', $this->url( '/sites/register' ), $descriptor );
	}

	/**
	 * POST /sites/claim-code, with an explicit JSON body for the API's origin guard.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function claim_code() {
		return $this->send( 'POST', $this->url( '/sites/claim-code' ), array() );
	}

	/**
	 * POST /inventory.
	 *
	 * @param array<string,mixed> $payload The InventorySchema payload.
	 * @return array<string,mixed>|WP_Error
	 */
	public function inventory( array $payload ) {
		return $this->send( 'POST', $this->url( '/inventory' ), $payload );
	}

	/**
	 * POST /sites/first-install; only the bounded background receipt job calls this.
	 *
	 * @param array<string,mixed> $payload Schema-1 installation metadata.
	 * @return array<string,mixed>|WP_Error
	 */
	public function first_install( array $payload ) {
		return $this->send( 'POST', $this->url( '/sites/first-install' ), $payload );
	}

	/**
	 * GET /report.
	 *
	 * @return array<string,mixed>|WP_Error
	 */
	public function report() {
		return $this->send( 'GET', $this->url( '/report' ), null );
	}

	/**
	 * GET /updates.
	 *
	 * Builds `slugs[]=a&slugs[]=b` by hand: add_query_arg() with an array
	 * value produces `slugs[0]=a&slugs[1]=b`, which the platform does not read.
	 *
	 * @param string[] $slugs Plugin slugs to request manifests for.
	 * @return array<string,mixed>|WP_Error
	 */
	public function updates( array $slugs ) {
		return $this->send( 'GET', $this->updates_url( $slugs ), null );
	}

	/**
	 * POST /tickets.
	 *
	 * @param array<string,mixed> $ticket The TicketRequestSchema payload.
	 * @return array<string,mixed>|WP_Error
	 */
	public function ticket( array $ticket ) {
		return $this->send( 'POST', $this->url( '/tickets' ), $ticket );
	}

	/**
	 * Builds a request URL for a path with no extra query args.
	 *
	 * Plain string concatenation, not add_query_arg(): add_query_arg()
	 * re-serialises the whole URL, which percent-encodes the slashes in a
	 * base that already carries its own query string (the wp-env stub's
	 * `?rest_route=` form), producing a URL the stub's routing does not
	 * recognise. Concatenation extends both a plain base and a
	 * `?rest_route=` base correctly, matching updates_url() below.
	 *
	 * @param string $path Path starting with '/'.
	 * @return string
	 */
	private function url( $path ) {
		return $this->base . $path;
	}

	/**
	 * Builds the /updates URL with a hand-built slugs[] query string.
	 *
	 * @param string[] $slugs Plugin slugs.
	 * @return string
	 */
	private function updates_url( array $slugs ) {
		$url = $this->base . '/updates';

		$pairs = array();
		foreach ( $slugs as $slug ) {
			$pairs[] = 'slugs%5B%5D=' . rawurlencode( (string) $slug );
		}
		if ( array() === $pairs ) {
			return $url;
		}

		$separator = ( false === strpos( $url, '?' ) ) ? '?' : '&';
		return $url . $separator . implode( '&', $pairs );
	}

	/**
	 * Sends a request and maps the result to a decoded array or a WP_Error.
	 *
	 * @param string                   $method HTTP method.
	 * @param string                   $url    Fully built request URL.
	 * @param array<string,mixed>|null $body   Request body, JSON-encoded when present.
	 * @return array<string,mixed>|WP_Error
	 */
	private function send( $method, $url, $body ) {
		$headers = array(
			'Accept'     => 'application/json',
			'User-Agent' => sprintf( 'Afterware Connect/%s; %s', AFTERWARE_VERSION, home_url() ),
		);
		if ( null !== $body ) {
			$headers['Content-Type'] = 'application/json';
		}
		if ( null !== $this->site_key ) {
			$headers['Authorization'] = 'Bearer ' . $this->site_key;
		}

		$args = array(
			'method'      => $method,
			'timeout'     => 15,
			'redirection' => 0,
			'sslverify'   => true,
			'headers'     => $headers,
			'body'        => null === $body ? null : wp_json_encode( $body ),
		);

		$res = wp_remote_request( $url, $args );
		if ( is_wp_error( $res ) ) {
			return new WP_Error(
				'afterware_http',
				sprintf(
					/* translators: %s: the underlying transport error, e.g. "Connection timed out". */
					__( 'Afterware could not be reached: %s.', 'afterware' ),
					self::sentence( $res->get_error_message() )
				)
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'afterware_api_' . $code, self::error_message( $code, $data ), array( 'status' => $code ) );
		}

		return is_array( $data ) ? $data : array();
	}

	/**
	 * What a site owner reads when the API refuses.
	 *
	 * Every message this class produces is a whole sentence, because every one
	 * of them can end up in an admin notice, a WP-CLI error or a ticket. The
	 * status comes first, so the message says something even when the body is
	 * an HTML error page from something in between; the platform's own
	 * `detail` follows as a second sentence when there is one.
	 *
	 * @param int   $code HTTP status code.
	 * @param mixed $data Decoded response body, or null when it was not JSON.
	 * @return string
	 */
	private static function error_message( $code, $data ) {
		$message = sprintf(
			/* translators: %d: HTTP status code. */
			__( 'Afterware answered with HTTP %d.', 'afterware' ),
			$code
		);

		$detail = ( is_array( $data ) && isset( $data['detail'] ) && is_scalar( $data['detail'] ) )
			? self::sentence( (string) $data['detail'] )
			: '';

		return ( '' === $detail ) ? $message : $message . ' ' . $detail . '.';
	}

	/**
	 * Trims a fragment and drops a trailing full stop, so the caller can end
	 * the sentence itself without producing "Not Found..".
	 *
	 * A transport can fail with nothing to say at all, and every caller here
	 * puts the result mid-sentence; "Afterware could not be reached: ." is not
	 * one, so an empty fragment becomes a phrase that admits as much.
	 *
	 * @param string $text Text from a transport or from the platform.
	 * @return string
	 */
	private static function sentence( $text ) {
		$text = rtrim( trim( (string) $text ), '.' );
		return ( '' === $text ) ? __( 'no details', 'afterware' ) : $text;
	}
}
