<?php
/**
 * Caption rendering and validation.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Content;

class TemplateRenderer {
	const PLACEHOLDERS = array(
		'{property_id}',
		'{address}',
		'{price}',
		'{previous_price}',
		'{bedrooms}',
		'{property_type}',
		'{property_url}',
	);

	/** Find unsupported placeholders. */
	public function invalid_placeholders( $template ) {
		preg_match_all( '/\{[a-z0-9_]+\}/i', (string) $template, $matches );
		return array_values( array_diff( array_unique( $matches[0] ), self::PLACEHOLDERS ) );
	}

	/** Render a caption, returning WP_Error if a token remains. */
	public function render( $template, array $values ) {
		$invalid = $this->invalid_placeholders( $template );
		if ( ! empty( $invalid ) ) {
			return new \WP_Error(
				'unsupported_placeholder',
				sprintf(
					/* translators: %s: Comma-separated unsupported caption placeholders. */
					__( 'The caption template contains unsupported placeholders: %s', 'propertyhive-buffer-auto-post' ),
					implode( ', ', $invalid )
				)
			);
		}

		$replacements = array();
		foreach ( self::PLACEHOLDERS as $placeholder ) {
			$key                          = trim( $placeholder, '{}' );
			$replacements[ $placeholder ] = isset( $values[ $key ] ) ? (string) $values[ $key ] : '';
		}

		$caption = strtr( (string) $template, $replacements );
		$caption = preg_replace( "/[ \t]+\n/", "\n", $caption );
		$caption = trim( (string) $caption );

		if ( preg_match_all( '/\{[a-z0-9_]+\}/i', $caption, $matches ) ) {
			return new \WP_Error(
				'unresolved_placeholder',
				sprintf(
					/* translators: %s: Comma-separated unresolved caption placeholders. */
					__( 'The caption contains unresolved placeholders: %s', 'propertyhive-buffer-auto-post' ),
					implode( ', ', array_unique( $matches[0] ) )
				)
			);
		}
		return $caption;
	}
}
