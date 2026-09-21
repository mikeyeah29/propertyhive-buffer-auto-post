<?php
/**
 * Hook registration contract.
 *
 * @package PropertyHiveBufferAutoPost
 */

namespace Homer\PropertyHiveBufferAutoPost\Contracts;

interface Hookable {
	/** Register WordPress hooks. */
	public function register_hooks();
}
