<?php
/**
 * Short-lived account association codes, issued only on an explicit admin action.
 *
 * @package Afterware
 */

namespace Afterware;

use WP_Error;

/** Keeps the site key out of the browser and validates the complete claim response. */
final class Claim_Code {
	/** @var string The only dashboard destination a claim response may name. */
	const URL = 'https://app.afterware.io/app/sites/claim';

	/**
	 * Requests a replacement code. Uncertain outcomes invalidate the previous local display.
	 *
	 * @return true|WP_Error
	 */
	public static function request() {
		if ( ! current_user_can( 'update_plugins' ) || is_wp_error( Switch_Guard::may_manage_plugins() ) ) {
			return new WP_Error( 'afterware_claim_permission', __( 'You do not have permission to connect this site to an account.', 'afterware' ) );
		}
		if ( ! Connection::is_connected() || ! Options::get( Options::SITE_ID ) ) {
			return new WP_Error( 'afterware_not_connected', __( 'Register this site with Afterware first.', 'afterware' ) );
		}

		Options::delete( Options::CLAIM_CODE );
		$key    = (string) Options::get( Options::SITE_KEY );
		$client = new Api_Client( AFTERWARE_API_BASE, $key );
		$result = $client->claim_code();
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['status'] ) && 429 === $data['status'] ) {
				return new WP_Error( 'afterware_claim_limit', __( 'Too many connection codes were requested. Wait an hour before trying again.', 'afterware' ) );
			}
			return new WP_Error( 'afterware_claim_unavailable', __( 'Could not get a connection code. Try again shortly or contact Afterware support.', 'afterware' ) );
		}
		$code = self::validate( $result );
		if ( null === $code ) {
			return new WP_Error( 'afterware_claim_response', __( 'Afterware returned an invalid connection code. Please try again.', 'afterware' ) );
		}
		Options::set(
			Options::CLAIM_CODE,
			array_merge(
				$code,
				array(
					'user_id'  => get_current_user_id(),
					'site_id'  => (string) Options::get( Options::SITE_ID ),
					'key_hash' => hash( 'sha256', $key ),
				)
			)
		);
		return true;
	}

	/**
	 * Reads a code without I/O or writes; expiry and identity are rechecked each time.
	 *
	 * @return array{code:string,expires:int,url:string}|null
	 */
	public static function peek() {
		if ( ! current_user_can( 'update_plugins' ) || is_wp_error( Switch_Guard::may_manage_plugins() ) || ! Connection::is_connected() ) {
			return null;
		}
		$saved = Options::get( Options::CLAIM_CODE );
		if ( ! is_array( $saved ) || ! isset( $saved['user_id'], $saved['site_id'], $saved['key_hash'], $saved['code'], $saved['expires'] ) ) {
			return null;
		}
		if ( get_current_user_id() !== $saved['user_id'] || Options::get( Options::SITE_ID ) !== $saved['site_id']
			|| ! is_string( $saved['key_hash'] ) || ! hash_equals( hash( 'sha256', (string) Options::get( Options::SITE_KEY ) ), $saved['key_hash'] )
			|| ! is_string( $saved['code'] ) || 1 !== preg_match( '/^[A-HJ-NP-Z2-9]{8}$/D', $saved['code'] )
			|| ! is_int( $saved['expires'] ) || $saved['expires'] <= time() || $saved['expires'] > time() + 20 * MINUTE_IN_SECONDS ) {
			return null;
		}
		return array(
			'code'    => $saved['code'],
			'expires' => $saved['expires'],
			'url'     => self::URL,
		);
	}

	/**
	 * Mirrors ClaimCodeResponseSchema without a runtime dependency.
	 *
	 * @param array<string,mixed> $result Decoded API response.
	 * @return array{code:string,expires:int,url:string}|null
	 */
	private static function validate( array $result ) {
		if ( ! isset( $result['code'], $result['expires_at'], $result['claim_url'] )
			|| ! is_string( $result['code'] ) || 1 !== preg_match( '/^[A-HJ-NP-Z2-9]{8}$/D', $result['code'] )
			|| self::URL !== $result['claim_url'] || ! is_string( $result['expires_at'] ) ) {
			return null;
		}
		$date = $result['expires_at'];
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D', $date )
			|| ! checkdate( (int) substr( $date, 5, 2 ), (int) substr( $date, 8, 2 ), (int) substr( $date, 0, 4 ) ) ) {
			return null;
		}
		$expires = strtotime( $date );
		if ( false === $expires || $expires <= time() || $expires > time() + 20 * MINUTE_IN_SECONDS ) {
			return null;
		}
		return array(
			'code'    => $result['code'],
			'expires' => $expires,
			'url'     => self::URL,
		);
	}
}
