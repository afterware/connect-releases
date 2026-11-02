<?php
/**
 * The explicit account-claim action.
 *
 * @package Afterware
 */

namespace Afterware\Admin;

use Afterware\Claim_Code;
use Afterware\Notices;

/** Shares Actions' nonce and redirect plumbing. */
trait Actions_Claim {
	/**
	 * Issues a code only after the nonce and plugin-management checks.
	 *
	 * @return void
	 */
	public static function claim_code() {
		self::guard( 'claim_code' );
		$result = Claim_Code::request();
		if ( is_wp_error( $result ) ) {
			Notices::add( 'claim', 'error', $result->get_error_message() );
		}
		self::back( Admin::PAGE . '-settings' );
	}
}
