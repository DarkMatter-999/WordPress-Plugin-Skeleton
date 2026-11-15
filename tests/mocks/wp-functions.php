<?php
/**
 * Minimal WordPress function shims for isolated unit tests.
 *
 * @package DarkMatter_Package
 */

if ( ! function_exists( 'wp_parse_url' ) ) {
	/**
	 * Parse a URL using PHP's URL parser for tests without WordPress loaded.
	 *
	 * @param string $url       URL to parse.
	 * @param int    $component Requested URL component.
	 * @return array|string|false
	 */
	function wp_parse_url( $url, $component = -1 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Shim for the WordPress function in isolated tests.
		return parse_url( $url, $component );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	/**
	 * Encode a value using PHP's JSON encoder for tests without WordPress loaded.
	 *
	 * @param mixed $data    Value to encode.
	 * @param int   $options JSON encoding options.
	 * @param int   $depth   Maximum encoding depth.
	 * @return string|false
	 */
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Shim for the WordPress function in isolated tests.
		return json_encode( $data, $options, $depth );
	}
}

if ( ! function_exists( 'wp_json_file_decode' ) ) {
	/**
	 * Decode a JSON fixture using the WordPress API's return shape.
	 *
	 * @param string $filename JSON file path.
	 * @param array  $options  JSON decoder options.
	 * @return mixed
	 */
	function wp_json_file_decode( $filename, $options = array() ) {
		$GLOBALS['dmp_json_file_decode_calls'] = isset( $GLOBALS['dmp_json_file_decode_calls'] )
			? $GLOBALS['dmp_json_file_decode_calls'] + 1
			: 1;
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Test shim for WordPress's local JSON file decoder.
		$contents = file_get_contents( $filename );
		if ( false === $contents ) {
			return null;
		}

		$associative = isset( $options['associative'] ) && $options['associative'];
		$decoded     = json_decode( $contents, $associative );

		return JSON_ERROR_NONE === json_last_error() ? $decoded : null;
	}
}
