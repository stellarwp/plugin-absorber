<?php
/**
 * Builds Sub_Plugin fixtures.
 *
 * @package Nexcess\PluginAbsorber
 */

declare( strict_types=1 );

namespace Nexcess\PluginAbsorber\Tests\Support\Traits;

use Nexcess\PluginAbsorber\Sub_Plugin;

/**
 * @since 1.0.0
 */
trait WithSubPlugins {
	/**
	 * Build a well-formed sub-plugin, overriding only what the test is about.
	 *
	 * The two remaining required keys are derived from the slug, so fixtures for different
	 * sub-plugins never share a bundled file path or a guard constant. The constant carries a
	 * `_FIXTURE` suffix that nothing ever defines: `define()` lasts for the whole PHP process, so
	 * a default some other test defined would report `is_already_loaded()` true for the rest of
	 * the suite. Tests that need a defined constant pass their own name for it.
	 *
	 * Overrides are merged last, so an invalid value can still be handed to the constructor to
	 * assert that it is rejected; the derived defaults fall back to the default slug in that case.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $overrides Config values to override.
	 *
	 * @return Sub_Plugin
	 */
	protected function make_sub_plugin( array $overrides = [] ): Sub_Plugin {
		$slug = isset( $overrides['slug'] ) && is_string( $overrides['slug'] ) && '' !== $overrides['slug']
			? $overrides['slug']
			: 'give-recurring';

		return new Sub_Plugin(
			array_merge(
				[
					'slug'                   => $slug,
					'bundled_plugin_file'    => "/tmp/{$slug}/{$slug}.php",
					'plugin_loaded_constant' => strtoupper( str_replace( '-', '_', $slug ) ) . '_VERSION_FIXTURE',
				],
				$overrides
			)
		);
	}
}
